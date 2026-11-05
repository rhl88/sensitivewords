<?php

namespace App\Apps\CmsproSensitivewords\Services;

use App\Apps\CmsproSensitivewords\Models\SensitiveCategory;
use App\Apps\CmsproSensitivewords\Models\SensitiveHitLog;
use App\Apps\CmsproSensitivewords\Models\SensitiveWord;
use App\Services\HookManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 敏感词过滤核心服务（检测 / 替换 / 动作决策 / 命中日志 / 词典编译缓存）。
 *
 * 本类是应用对外的主入口，其他应用通过辅助函数、容器调用或验证规则最终都落到这里。
 * 设计要点：
 *
 * 1. 词典编译缓存：词条上万时每次请求重建 DFA 树开销明显，因此按「词条签名」
 *    （启用词条数 + 最近更新时间）把编译好的树序列化到
 *    storage/app_cmspro_sensitivewords/dictionary/，签名不变直接反序列化载入，
 *    词条增删改后签名变化自动重建（无需手工清缓存）。
 *
 * 2. 动作三级决策：词条 action → 分类 default_action → 全局配置 default_action。
 *    这样既能对个别词单独加严，也能按类目批量设定策略。
 *
 * 3. 异常不外溢：过滤是横切能力，任何内部异常（表未建、缓存目录不可写、
 *    钩子回调报错）都只记日志并降级为「放行原文」，绝不把调用方主流程带崩。
 */
class SensitiveWordService
{
    /** 词典编译缓存目录（相对 storage/） */
    private const DICT_DIR = 'app_cmspro_sensitivewords/dictionary';

    /** 请求内已构建的引擎实例（单例服务 + 请求内复用，避免同请求多次建树） */
    protected ?SensitiveWordEngine $engine = null;

    /** 当前引擎对应的词条签名（用于判断是否需要重建） */
    protected ?string $loadedSignature = null;

    /** 分类默认动作映射缓存：category_id => action */
    protected ?array $categoryActions = null;

    public function __construct(protected ?HookManager $hooks = null)
    {
    }

    // ══════════════════════════════════════════════════════════════
    // 一、能力探测
    // ══════════════════════════════════════════════════════════════

    /**
     * 判断过滤能力是否就绪（词条表存在且可查询）。
     *
     * 供辅助函数在调用前探测；应用未安装或迁移未执行时返回 false，
     * 调用方据此降级为「不过滤」，不抛异常。
     */
    public function isReady(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('app_cmspro_sensitivewords_words');
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 过滤总开关是否开启。
     */
    public function isEnabled(): bool
    {
        return (string) $this->config('filter_enabled', '1') === '1';
    }

    /**
     * 当前启用词条数量。
     */
    public function wordTotal(): int
    {
        try {
            return SensitiveWord::enabled()->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // ══════════════════════════════════════════════════════════════
    // 二、对外主接口：检测 / 替换 / 校验
    // ══════════════════════════════════════════════════════════════

    /**
     * 检测文本，返回命中详情与最终动作（不修改文本）。
     *
     * 命中后写命中日志、累加词条命中次数并触发命中通知钩子。
     * 若只想试检测而不产生任何副作用，请使用 inspect()。
     *
     * @param string $text      待检测文本
     * @param string $scene     业务场景标识（写入日志，如 comment.create）
     * @param string $sourceApp 来源应用 ID（写入日志，如 cmspro.blog）
     * @return array{
     *     passed:bool,action:string,message:string,hit_count:int,filtered:string,
     *     hits:array<int,array{word:string,normalized:string,start:int,end:int,level:int,action:string,category_id:int,word_id:int}>
     * }
     */
    public function check(string $text, string $scene = '', string $sourceApp = ''): array
    {
        $result = $this->inspect($text, $scene, $sourceApp);
        if ($result['hit_count'] === 0) {
            return $result;
        }

        try {
            // 触发命中通知（Action 钩子，供其他应用做自定义处理，如推送人工审核）
            $this->doAction('cmspro.sensitivewords.hit', $result['hits'], [
                'text' => $text,
                'scene' => $scene,
                'source_app' => $sourceApp,
                'action' => $result['action'],
            ]);

            $this->writeLog($result['hits'], $result['action'], $text, $scene, $sourceApp);
            $this->increaseHitCount($result['hits']);
        } catch (Throwable $e) {
            // 日志与计数属旁路能力，失败不影响检测结果返回
            Log::warning('[cmspro.sensitivewords] 命中记录失败', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * 试检测：返回命中详情与替换后文本，不写日志、不累加计数、不触发命中钩子。
     *
     * 供后台「在线检测」与其他应用的干跑（dry-run）场景使用。
     *
     * @return array{
     *     passed:bool,action:string,message:string,hit_count:int,filtered:string,
     *     hits:array<int,array<string,mixed>>
     * }
     */
    public function inspect(string $text, string $scene = '', string $sourceApp = ''): array
    {
        $empty = [
            'passed' => true,
            'action' => 'none',
            'message' => '',
            'hit_count' => 0,
            'hits' => [],
            'filtered' => $text,
        ];

        if ($text === '' || ! $this->isEnabled()) {
            return $empty;
        }

        try {
            $engine = $this->engine();
            if ($engine->isEmpty()) {
                return $empty;
            }

            $matches = $engine->match($text);
            if ($matches === []) {
                return $empty;
            }

            $hits = $this->resolveHits($matches);
            $hits = $this->applyWhitelist($hits, $scene, $sourceApp);
            if ($hits === []) {
                return $empty;
            }

            $action = $this->decideAction($hits);
            $words = array_values(array_unique(array_column($hits, 'word')));
            $replacement = (string) $this->config('replacement', '*');

            return [
                'passed' => $action !== SensitiveWord::ACTION_BLOCK,
                'action' => $action,
                'message' => $action === SensitiveWord::ACTION_BLOCK
                    ? '内容包含违规词：' . implode('、', array_slice($words, 0, 5))
                    : '',
                'hit_count' => count($hits),
                'hits' => $hits,
                'filtered' => $this->replaceHits($text, $hits, $replacement === '' ? '*' : $replacement),
            ];
        } catch (Throwable $e) {
            Log::error('[cmspro.sensitivewords] 检测异常，已降级放行', ['error' => $e->getMessage()]);

            return $empty;
        }
    }

    /**
     * 过滤文本：把命中词替换为掩码字符。
     *
     * 动作语义：
     * - replace / block：执行替换（block 词同样被掩码，保证落库内容安全；
     *   需要「拒绝提交」的调用方请改用 check() 或 validateText()）
     * - warn：保留原文，仅记录日志
     *
     * @param string $text      待过滤文本
     * @param string $scene     业务场景标识
     * @param string $sourceApp 来源应用 ID
     * @return string 过滤后的文本
     */
    public function filter(string $text, string $scene = '', string $sourceApp = ''): string
    {
        if ($text === '' || ! $this->isEnabled()) {
            return $text;
        }

        try {
            // 前置钩子：允许其他应用预处理文本（如先剥离 HTML 标签）
            $text = (string) $this->applyFilter('cmspro.sensitivewords.before_filter', $text, $scene, $sourceApp);

            $result = $this->check($text, $scene, $sourceApp);
            if ($result['hit_count'] === 0) {
                return $text;
            }

            // check() 内已完成替换计算，直接复用结果，避免重复匹配一次
            $filtered = (string) $result['filtered'];

            // 后置钩子：允许其他应用对过滤结果再加工
            return (string) $this->applyFilter(
                'cmspro.sensitivewords.after_filter',
                $filtered,
                $text,
                $result['hits'],
                $scene,
                $sourceApp
            );
        } catch (Throwable $e) {
            Log::error('[cmspro.sensitivewords] 过滤异常，已返回原文', ['error' => $e->getMessage()]);

            return $text;
        }
    }

    /**
     * 校验文本：命中「禁止」级词条时返回错误提示，否则返回 null。
     *
     * 供验证规则与控制器直接使用：
     * ```php
     * if ($error = app(SensitiveWordService::class)->validateText($content)) {
     *     return ApiResponse::error(40002, $error);
     * }
     * ```
     *
     * @param string $text      待校验文本
     * @param string $scene     业务场景标识
     * @param string $sourceApp 来源应用 ID
     * @return string|null 错误提示文案，通过时返回 null
     */
    public function validateText(string $text, string $scene = '', string $sourceApp = ''): ?string
    {
        $result = $this->check($text, $scene, $sourceApp);

        return $result['passed'] ? null : ($result['message'] !== '' ? $result['message'] : '内容包含违规词，请修改后重试');
    }

    /**
     * 供 Laravel 验证规则调用的布尔判定（命中禁止级即验证失败）。
     */
    public function passes(string $text, string $scene = '', string $sourceApp = ''): bool
    {
        return $this->validateText($text, $scene, $sourceApp) === null;
    }

    // ══════════════════════════════════════════════════════════════
    // 三、词典构建与编译缓存
    // ══════════════════════════════════════════════════════════════

    /**
     * 获取当前可用的匹配引擎（按签名复用编译缓存）。
     */
    public function engine(): SensitiveWordEngine
    {
        $signature = $this->signature();

        if ($this->engine !== null && $this->loadedSignature === $signature) {
            return $this->engine;
        }

        $engine = new SensitiveWordEngine(
            (string) $this->config('ignore_case', '1') === '1',
            (string) $this->config('full_width_to_half', '1') === '1',
            (string) $this->config('skip_symbols', '1') === '1'
        );

        $cached = $this->loadCompiledTree($signature);
        if ($cached !== null) {
            $engine->loadTree($cached['tree'], (int) ($cached['word_count'] ?? 0));
        } else {
            $engine->build($this->loadWordEntriesFromDb());
            $this->saveCompiledTree($signature, $engine);
        }

        $this->engine = $engine;
        $this->loadedSignature = $signature;

        return $engine;
    }

    /**
     * 强制重建词典（词条批量变更后调用，同时清理旧编译缓存文件）。
     */
    public function rebuildDictionary(): void
    {
        $this->engine = null;
        $this->loadedSignature = null;
        $this->categoryActions = null;
        $this->clearCompiledTrees();
    }

    /**
     * 词条签名：启用词条数 + 最近更新时间 + 匹配策略配置。
     *
     * 任一项变化都会导致签名变化，从而自动重建词典；把匹配策略配置也纳入签名，
     * 是因为「忽略大小写 / 全角转半角 / 跳过干扰符」会改变归一化结果与树结构。
     */
    protected function signature(): string
    {
        try {
            $row = DB::table('app_cmspro_sensitivewords_words')
                ->where('status', 1)
                ->selectRaw('COUNT(*) AS c, MAX(update_time) AS m, MAX(create_time) AS cm')
                ->first();

            $raw = ($row->c ?? 0) . '|' . ($row->m ?? '') . '|' . ($row->cm ?? '');
        } catch (Throwable $e) {
            $raw = 'unavailable';
        }

        $raw .= '|' . $this->config('ignore_case', '1')
            . '|' . $this->config('full_width_to_half', '1')
            . '|' . $this->config('skip_symbols', '1');

        return md5($raw);
    }

    /**
     * 从数据库加载启用中的词条（只取建树必需的 id 与 word 两列，避免大字段全量载入）。
     *
     * @return array<int,array{id:int,word:string}>
     */
    protected function loadWordEntriesFromDb(): array
    {
        try {
            return SensitiveWord::enabled()
                ->orderBy('id')
                ->get(['id', 'word'])
                ->map(fn (SensitiveWord $w) => ['id' => (int) $w->id, 'word' => (string) $w->word])
                ->all();
        } catch (Throwable $e) {
            Log::error('[cmspro.sensitivewords] 词条加载失败', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * 载入编译好的词典树。
     *
     * @return array{tree:array<string,mixed>,word_count:int}|null
     */
    protected function loadCompiledTree(string $signature): ?array
    {
        $path = $this->dictPath($signature);
        if (! is_file($path)) {
            return null;
        }

        try {
            $decoded = unserialize((string) File::get($path));

            return is_array($decoded) && isset($decoded['tree']) && is_array($decoded['tree']) ? $decoded : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * 写入编译缓存（目录不可写时静默跳过，仅损失性能不影响功能）。
     */
    protected function saveCompiledTree(string $signature, SensitiveWordEngine $engine): void
    {
        try {
            $dir = storage_path(self::DICT_DIR);
            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
            }

            File::put($this->dictPath($signature), serialize($engine->export()));

            // 清理旧签名文件，避免长期堆积
            foreach (glob($dir . '/dict_*.php') ?: [] as $old) {
                if (! str_contains($old, $signature)) {
                    @unlink($old);
                }
            }
        } catch (Throwable $e) {
            // 缓存写入失败不影响过滤功能
        }
    }

    /**
     * 清空全部编译缓存文件。
     */
    protected function clearCompiledTrees(): void
    {
        try {
            $dir = storage_path(self::DICT_DIR);
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        } catch (Throwable $e) {
            // 忽略清理异常
        }
    }

    /**
     * 编译缓存文件绝对路径。
     */
    protected function dictPath(string $signature): string
    {
        return storage_path(self::DICT_DIR . '/dict_' . $signature . '.php');
    }

    // ══════════════════════════════════════════════════════════════
    // 四、命中结果加工：动作决策 / 白名单 / 替换
    // ══════════════════════════════════════════════════════════════

    /**
     * 把引擎的原始命中片段补全为带词条属性的命中详情。
     *
     * 按引擎返回的 word_ids 批量回查词条（一次 IN 查询，避免 N+1）。
     * 用 ID 而非归一化键回查：ID 不随匹配策略配置变化，
     * 即便管理员事后改了大小写/全角/干扰符开关，命中定位依然准确。
     *
     * @param array<int,array{start:int,end:int,raw:string,normalized:string,word_ids:array<int,int>}> $matches
     * @return array<int,array{word:string,normalized:string,start:int,end:int,level:int,action:string,category_id:int,word_id:int}>
     */
    protected function resolveHits(array $matches): array
    {
        // 汇总所有命中片段的词条 ID，一次查库
        $ids = [];
        foreach ($matches as $match) {
            foreach (($match['word_ids'] ?? []) as $id) {
                $ids[(int) $id] = true;
            }
        }
        $ids = array_keys($ids);

        $words = [];
        if ($ids !== []) {
            try {
                $words = SensitiveWord::enabled()
                    ->whereIn('id', $ids)
                    ->get(['id', 'word', 'word_lower', 'category_id', 'action', 'level', 'status'])
                    ->keyBy('id')
                    ->all();
            } catch (Throwable $e) {
                Log::error('[cmspro.sensitivewords] 命中词条回查失败', ['error' => $e->getMessage()]);
            }
        }

        $hits = [];
        foreach ($matches as $match) {
            // 一个片段可能命中多条 ID（归一化后同键的历史词条），取首个仍启用的
            $model = null;
            foreach (($match['word_ids'] ?? []) as $id) {
                if (isset($words[(int) $id])) {
                    $model = $words[(int) $id];
                    break;
                }
            }
            if ($model === null) {
                // 词条在检测过程中被禁用/删除：跳过该命中，避免误伤
                continue;
            }

            $hits[] = [
                'word' => (string) $model->word,
                'normalized' => $match['normalized'],
                'start' => (int) $match['start'],
                'end' => (int) $match['end'],
                'level' => (int) $model->level,
                'action' => $this->resolveAction($model),
                'category_id' => (int) $model->category_id,
                'word_id' => (int) $model->id,
            ];
        }

        return $hits;
    }

    /**
     * 决策单个词条的动作：词条 action → 分类 default_action → 全局默认动作。
     *
     * @param SensitiveWord|array<string,mixed> $word
     */
    protected function resolveAction(SensitiveWord|array $word): string
    {
        $action = (string) (is_array($word) ? ($word['action'] ?? '') : $word->action);
        if ($this->isValidAction($action)) {
            return $action;
        }

        $categoryId = (int) (is_array($word) ? ($word['category_id'] ?? 0) : $word->category_id);
        $categoryAction = $this->categoryActions()[$categoryId] ?? '';
        if ($this->isValidAction($categoryAction)) {
            return $categoryAction;
        }

        $global = (string) $this->config('default_action', SensitiveWord::ACTION_REPLACE);

        return $this->isValidAction($global) ? $global : SensitiveWord::ACTION_REPLACE;
    }

    /**
     * 分类默认动作映射（请求内缓存一次查询）。
     *
     * @return array<int,string>
     */
    protected function categoryActions(): array
    {
        if ($this->categoryActions !== null) {
            return $this->categoryActions;
        }

        try {
            $this->categoryActions = SensitiveCategory::enabled()
                ->where('default_action', '!=', '')
                ->pluck('default_action', 'id')
                ->all();
        } catch (Throwable $e) {
            $this->categoryActions = [];
        }

        return $this->categoryActions;
    }

    /**
     * 动作值合法性校验。
     */
    protected function isValidAction(string $action): bool
    {
        return in_array($action, [
            SensitiveWord::ACTION_BLOCK,
            SensitiveWord::ACTION_REPLACE,
            SensitiveWord::ACTION_WARN,
        ], true);
    }

    /**
     * 决策整段文本的最终动作：存在禁止级即禁止，否则替换优先于提醒。
     *
     * @param array<int,array<string,mixed>> $hits
     */
    protected function decideAction(array $hits): string
    {
        $actions = array_column($hits, 'action');

        if (in_array(SensitiveWord::ACTION_BLOCK, $actions, true)) {
            return SensitiveWord::ACTION_BLOCK;
        }

        if (in_array(SensitiveWord::ACTION_REPLACE, $actions, true)) {
            return SensitiveWord::ACTION_REPLACE;
        }

        return SensitiveWord::ACTION_WARN;
    }

    /**
     * 应用白名单钩子：其他应用可通过 Filter 钩子放行指定词。
     *
     * 典型用法（其他应用在自己的 Hooks.php 中）：
     * ```php
     * $hooks->registerFilter('cmspro.sensitivewords.whitelist', function ($whitelist, $scene) {
     *     if ($scene === 'order.remark') { $whitelist[] = '代办'; }
     *     return $whitelist;
     * }, 10, 'cmspro.xxx');
     * ```
     *
     * @param array<int,array<string,mixed>> $hits
     * @return array<int,array<string,mixed>>
     */
    protected function applyWhitelist(array $hits, string $scene, string $sourceApp): array
    {
        $whitelist = $this->applyFilter('cmspro.sensitivewords.whitelist', [], $scene, $sourceApp);
        if (! is_array($whitelist) || $whitelist === []) {
            return $hits;
        }

        // 白名单按原文与归一化键双向匹配，调用方传哪种写法都能生效
        $normalized = [];
        foreach ($whitelist as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            $normalized[mb_strtolower($item)] = true;
        }

        return array_values(array_filter($hits, function (array $hit) use ($normalized) {
            return ! isset($normalized[mb_strtolower($hit['word'])])
                && ! isset($normalized[$hit['normalized']]);
        }));
    }

    /**
     * 按命中位置把原文片段替换为掩码（掩码长度与原文片段长度一致）。
     *
     * @param array<int,array<string,mixed>> $hits
     */
    protected function replaceHits(string $text, array $hits, string $replacement): string
    {
        // 仅替换动作非 warn 的命中；按起始位置倒序替换，避免前面的替换改变后面的下标
        $targets = array_values(array_filter(
            $hits,
            fn (array $hit) => ($hit['action'] ?? '') !== SensitiveWord::ACTION_WARN
        ));

        if ($targets === []) {
            return $text;
        }

        usort($targets, fn (array $a, array $b) => $b['start'] <=> $a['start']);

        $maskChar = mb_substr($replacement, 0, 1);
        foreach ($targets as $hit) {
            $length = (int) $hit['end'] - (int) $hit['start'] + 1;
            $mask = str_repeat($maskChar, max(1, $length));
            $text = mb_substr($text, 0, (int) $hit['start']) . $mask . mb_substr($text, (int) $hit['end'] + 1);
        }

        return $text;
    }

    // ══════════════════════════════════════════════════════════════
    // 五、命中日志与计数
    // ══════════════════════════════════════════════════════════════

    /**
     * 写入命中日志（受 log_enabled 配置控制，失败不影响主流程）。
     *
     * @param array<int,array<string,mixed>> $hits
     */
    protected function writeLog(array $hits, string $action, string $text, string $scene, string $sourceApp): void
    {
        if ((string) $this->config('log_enabled', '1') !== '1') {
            return;
        }

        try {
            $request = request();
            $length = (int) $this->config('log_content_length', '200');
            $snapshot = '';
            if ($length > 0) {
                $snapshot = mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '...' : $text;
            }

            SensitiveHitLog::create([
                'source_app' => mb_substr($sourceApp, 0, 64),
                'scene' => mb_substr($scene, 0, 64),
                'words' => mb_substr(implode(',', array_unique(array_column($hits, 'word'))), 0, 500),
                'word_count' => count($hits),
                'action' => $action,
                'content' => $snapshot,
                'request_path' => $request ? mb_substr((string) $request->path(), 0, 255) : '',
                'ip' => $request ? mb_substr((string) $request->ip(), 0, 45) : '',
                'user_id' => (int) (auth('web')->id() ?? 0),
                'admin_id' => (int) (auth('admin')->id() ?? 0),
            ]);
        } catch (Throwable $e) {
            Log::warning('[cmspro.sensitivewords] 命中日志写入失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 累加词条命中次数（受 stat_hit_count 配置控制）。
     *
     * 一次 UPDATE ... WHERE id IN (...) 完成全部词条计数，不逐条更新。
     *
     * @param array<int,array<string,mixed>> $hits
     */
    protected function increaseHitCount(array $hits): void
    {
        if ((string) $this->config('stat_hit_count', '1') !== '1') {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map(
            fn (array $hit) => (int) ($hit['word_id'] ?? 0),
            $hits
        ))));
        if ($ids === []) {
            return;
        }

        try {
            SensitiveWord::whereIn('id', $ids)->update([
                'hit_count' => DB::raw('hit_count + 1'),
                'last_hit_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // 计数失败不影响过滤结果
        }
    }

    /**
     * 清理超期命中日志（按 log_keep_days 配置）。
     *
     * @return int 清理条数
     */
    public function cleanExpiredLogs(): int
    {
        $days = (int) $this->config('log_keep_days', '90');
        if ($days <= 0) {
            return 0;
        }

        try {
            return SensitiveHitLog::where('create_time', '<', date('Y-m-d H:i:s', strtotime("-{$days} days")))->delete();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // ══════════════════════════════════════════════════════════════
    // 六、内部工具
    // ══════════════════════════════════════════════════════════════

    /**
     * 读取应用配置（走 helpers 的缓存读取函数，未加载时回落 config()）。
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        if (function_exists('cmspro_sensitivewords_config')) {
            return cmspro_sensitivewords_config($key, $default);
        }

        return config('apps.cmspro.sensitivewords.' . $key, $default);
    }

    /**
     * 触发 Filter 钩子（HookManager 不可用时原样返回）。
     */
    protected function applyFilter(string $hook, mixed $value, mixed ...$args): mixed
    {
        try {
            $hooks = $this->hooks ?? app(HookManager::class);

            return $hooks->applyFilter($hook, $value, ...$args);
        } catch (Throwable $e) {
            return $value;
        }
    }

    /**
     * 触发 Action 钩子（HookManager 不可用时静默跳过）。
     */
    protected function doAction(string $hook, mixed ...$args): void
    {
        try {
            $hooks = $this->hooks ?? app(HookManager::class);
            $hooks->doAction($hook, ...$args);
        } catch (Throwable $e) {
            // 钩子失败不影响过滤主流程
        }
    }
}
