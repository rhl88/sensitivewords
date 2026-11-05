<?php

namespace App\Apps\CmsproSensitivewords\Services;

use App\Apps\CmsproSensitivewords\Models\SensitiveCategory;
use App\Apps\CmsproSensitivewords\Models\SensitiveWord;
use App\Enums\Status;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 词条与分类管理服务（后台 CRUD、批量导入导出、词库包解析）。
 *
 * 与 SensitiveWordService 的分工：
 * - SensitiveWordService 负责「运行时过滤」（检测/替换/日志），追求低开销与异常安全
 * - 本类负责「管理态维护」（增删改查/导入导出/在线词库落库），写完必须让运行时词典失效
 *
 * 所有词条写入操作（含批量导入）最终都会调用 rebuildDictionary()，
 * 保证后台改动即时生效，不需要管理员手工清缓存。
 */
class SensitiveWordManageService
{
    /** 单次批量导入的最大词条数（防止超大文件打满内存与请求时长） */
    private const IMPORT_MAX_ROWS = 50000;

    /** 词条最大长度（与 word 列宽 191 一致） */
    private const WORD_MAX_LENGTH = 191;

    public function __construct(protected SensitiveWordService $filter)
    {
    }

    // ══════════════════════════════════════════════════════════════
    // 一、词条 CRUD
    // ══════════════════════════════════════════════════════════════

    /**
     * 分页查询词条列表。
     *
     * @param array<string,mixed> $params keyword/category_id/action/source/status/level 过滤条件
     */
    public function listWords(array $params): LengthAwarePaginator
    {
        $query = SensitiveWord::query()->with('category:id,name,code');

        if (! empty($params['keyword'])) {
            $query->where('word', 'like', '%' . $params['keyword'] . '%');
        }
        if (isset($params['category_id']) && $params['category_id'] !== '') {
            $query->where('category_id', (int) $params['category_id']);
        }
        if (! empty($params['action'])) {
            $query->where('action', (string) $params['action']);
        }
        if (! empty($params['source'])) {
            $query->where('source', (string) $params['source']);
        }
        if (isset($params['level']) && $params['level'] !== '') {
            $query->where('level', (int) $params['level']);
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int) $params['status']);
        }

        $perPage = (int) ($params['limit'] ?? $params['per_page'] ?? 15);
        $page = (int) ($params['page'] ?? 1);

        return $query->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 新增词条。
     *
     * @param array<string,mixed> $data
     * @throws RuntimeException 词条为空或归一化后重复时
     */
    public function createWord(array $data): SensitiveWord
    {
        $word = $this->normalizeInputWord($data['word'] ?? '');
        $lower = $this->buildMatchKey($word);

        if (SensitiveWord::where('word_lower', $lower)->exists()) {
            throw new RuntimeException('词条「' . $word . '」已存在（或与现有词条归一化后相同）');
        }

        $item = SensitiveWord::create([
            'word' => $word,
            'word_lower' => $lower,
            'category_id' => (int) ($data['category_id'] ?? 0),
            'action' => $this->normalizeAction($data['action'] ?? ''),
            'level' => $this->normalizeLevel($data['level'] ?? 1),
            'source' => SensitiveWord::SOURCE_MANUAL,
            'remark' => mb_substr((string) ($data['remark'] ?? ''), 0, 255),
            'status' => isset($data['status']) ? (int) $data['status'] : Status::ENABLED->value,
        ]);

        $this->refreshCategoryCount((int) $item->category_id);
        $this->filter->rebuildDictionary();

        return $item;
    }

    /**
     * 更新词条。
     *
     * @param array<string,mixed> $data
     * @throws RuntimeException 词条不存在或归一化后与其他词条冲突时
     */
    public function updateWord(int $id, array $data): SensitiveWord
    {
        $item = SensitiveWord::find($id);
        if ($item === null) {
            throw new RuntimeException('词条不存在');
        }

        $oldCategoryId = (int) $item->category_id;

        if (array_key_exists('word', $data) && trim((string) ($data['word'] ?? '')) !== '') {
            $word = $this->normalizeInputWord($data['word']);
            $lower = $this->buildMatchKey($word);

            if (SensitiveWord::where('word_lower', $lower)->where('id', '!=', $id)->exists()) {
                throw new RuntimeException('词条「' . $word . '」已存在（或与现有词条归一化后相同）');
            }

            $item->word = $word;
            $item->word_lower = $lower;
        }

        if (array_key_exists('category_id', $data)) {
            $item->category_id = (int) $data['category_id'];
        }
        if (array_key_exists('action', $data)) {
            $item->action = $this->normalizeAction($data['action']);
        }
        if (array_key_exists('level', $data)) {
            $item->level = $this->normalizeLevel($data['level']);
        }
        if (array_key_exists('remark', $data)) {
            $item->remark = mb_substr((string) $data['remark'], 0, 255);
        }
        if (array_key_exists('status', $data)) {
            $item->status = (int) $data['status'] === 1 ? Status::ENABLED : Status::DISABLED;
        }

        $item->save();

        $this->refreshCategoryCount($oldCategoryId);
        $this->refreshCategoryCount((int) $item->category_id);
        $this->filter->rebuildDictionary();

        return $item->fresh();
    }

    /**
     * 批量更新词条状态（启用/禁用），返回影响条数。
     *
     * @param array<int,int> $ids
     */
    public function batchUpdateStatus(array $ids, int $status): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));
        if ($ids === []) {
            return 0;
        }

        $affected = SensitiveWord::whereIn('id', $ids)
            ->update(['status' => $status === 1 ? Status::ENABLED->value : Status::DISABLED->value]);

        $this->refreshAllCategoryCounts();
        $this->filter->rebuildDictionary();

        return $affected;
    }

    /**
     * 删除词条（单个或批量），返回删除条数。
     *
     * @param array<int,int> $ids
     */
    public function deleteWords(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));
        if ($ids === []) {
            return 0;
        }

        $categoryIds = SensitiveWord::whereIn('id', $ids)->pluck('category_id')->unique()->all();
        $deleted = SensitiveWord::whereIn('id', $ids)->delete();

        foreach ($categoryIds as $categoryId) {
            $this->refreshCategoryCount((int) $categoryId);
        }
        $this->filter->rebuildDictionary();

        return $deleted;
    }

    /**
     * 按条件批量删除（清空某来源词库等场景）。
     *
     * @param array<string,mixed> $conditions source/category_id/status 等精确匹配条件
     * @return int 删除条数
     */
    public function deleteWordsBy(array $conditions): int
    {
        $query = SensitiveWord::query();
        foreach ($conditions as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $query->where($field, $value);
        }

        $deleted = $query->delete();
        $this->refreshAllCategoryCounts();
        $this->filter->rebuildDictionary();

        return $deleted;
    }

    // ══════════════════════════════════════════════════════════════
    // 二、批量导入导出
    // ══════════════════════════════════════════════════════════════

    /**
     * 解析词库文本为词条数组（每行一词，支持「词,动作,级别,分类」格式）。
     *
     * 支持的行格式（逗号或竖线分隔，# 或 // 开头为注释）：
     * ```
     * 赌博
     * 赌博网站,block,3
     * 代开发票|replace|2|广告
     * ```
     * 第 2 段为动作（block/replace/warn，或中文 禁止/替换/提醒），
     * 第 3 段为级别（1-3），第 4 段为分类名称或分类 code（不存在时自动创建）。
     *
     * @return array<int,array{word:string,action:string,level:int,category:string}>
     */
    public function parseWordLines(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $result = [];
        $seen = [];

        foreach ($lines as $line) {
            $line = trim($line);
            // 去除 UTF-8 BOM（Windows 记事本导出的词库常带 BOM）
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//')) {
                continue;
            }

            $parts = preg_split('/[,|，\t]/u', $line) ?: [$line];
            $parts = array_map(fn ($p) => trim((string) $p), $parts);

            try {
                $word = $this->normalizeInputWord($parts[0] ?? '');
            } catch (Throwable $e) {
                continue; // 空词或超长词直接跳过
            }

            $lower = $this->buildMatchKey($word);
            if (isset($seen[$lower])) {
                continue; // 文件内重复
            }
            $seen[$lower] = true;

            $result[] = [
                'word' => $word,
                'action' => $this->normalizeAction($parts[1] ?? ''),
                'level' => $this->normalizeLevel($parts[2] ?? 1),
                'category' => (string) ($parts[3] ?? ''),
            ];

            if (count($result) >= self::IMPORT_MAX_ROWS) {
                break;
            }
        }

        return $result;
    }

    /**
     * 导入词条（分批写入，已存在的归一化键按策略处理）。
     *
     * @param array<int,array{word:string,action:string,level:int,category:string}> $rows parseWordLines 的输出
     * @param string $source  来源标记（import=文件导入 library=在线词库）
     * @param string $mode    冲突处理：skip=跳过已存在 update=更新动作为非空的字段 overwrite=整条覆盖
     * @param int    $defaultCategoryId 未声明分类时归入的分类 ID（0=未分类）
     * @return array{total:int,inserted:int,updated:int,skipped:int}
     */
    public function importRows(array $rows, string $source, string $mode = 'skip', int $defaultCategoryId = 0): array
    {
        $stat = ['total' => count($rows), 'inserted' => 0, 'updated' => 0, 'skipped' => 0];
        if ($rows === []) {
            return $stat;
        }

        // 分类名 → ID 映射：一次性取出现有分类，导入过程中新建的分类增量登记
        $categoryMap = SensitiveCategory::pluck('id', 'code')->all()
            + SensitiveCategory::pluck('id', 'name')->all();

        DB::beginTransaction();
        try {
            foreach (array_chunk($rows, 500) as $chunk) {
                foreach ($chunk as $row) {
                    $categoryId = $defaultCategoryId;
                    if (($row['category'] ?? '') !== '') {
                        $key = trim((string) $row['category']);
                        if (! isset($categoryMap[$key])) {
                            $categoryMap[$key] = (int) $this->createCategory([
                                'name' => $key,
                                'code' => $this->buildCategoryCode($key, $categoryMap),
                                'source' => 'import',
                            ])->id;
                        }
                        $categoryId = (int) $categoryMap[$key];
                    }

                    $lower = $this->buildMatchKey($row['word']);
                    $existing = SensitiveWord::where('word_lower', $lower)->first();

                    if ($existing === null) {
                        SensitiveWord::create([
                            'word' => $row['word'],
                            'word_lower' => $lower,
                            'category_id' => $categoryId,
                            'action' => $row['action'],
                            'level' => $row['level'],
                            'source' => $source,
                            'status' => Status::ENABLED->value,
                        ]);
                        $stat['inserted']++;
                        continue;
                    }

                    if ($mode === 'skip') {
                        $stat['skipped']++;
                        continue;
                    }

                    if ($mode === 'overwrite') {
                        $existing->update([
                            'word' => $row['word'],
                            'category_id' => $categoryId,
                            'action' => $row['action'],
                            'level' => $row['level'],
                            'source' => $source,
                            'status' => Status::ENABLED->value,
                        ]);
                        $stat['updated']++;
                        continue;
                    }

                    // update 模式：仅补齐空动作/分类，保留管理员的手工调整
                    $patch = ['source' => $source];
                    if ($existing->action === '' && $row['action'] !== '') {
                        $patch['action'] = $row['action'];
                    }
                    if ((int) $existing->category_id === 0 && $categoryId > 0) {
                        $patch['category_id'] = $categoryId;
                    }
                    $existing->update($patch);
                    $stat['updated']++;
                }
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('[cmspro.sensitivewords] 词条导入失败', ['error' => $e->getMessage()]);

            throw new RuntimeException('词条导入失败：' . $e->getMessage());
        }

        $this->refreshAllCategoryCounts();
        $this->filter->rebuildDictionary();

        return $stat;
    }

    /**
     * 导出词条为文本（每行「词,动作,级别,分类code」，可被 parseWordLines 再次导入）。
     *
     * @param array<string,mixed> $conditions 过滤条件（source/category_id/status）
     */
    public function exportWords(array $conditions = []): string
    {
        $query = SensitiveWord::query();
        foreach ($conditions as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $query->where($field, $value);
        }

        $categoryCodes = SensitiveCategory::pluck('code', 'id')->all();

        $lines = [
            '# CmsPro 敏感词库导出文件',
            '# 格式：敏感词,动作(block/replace/warn),级别(1-3),分类code',
            '# 导出时间：' . date('Y-m-d H:i:s'),
        ];

        $query->orderBy('id')->chunk(1000, function ($words) use (&$lines, $categoryCodes) {
            foreach ($words as $word) {
                $lines[] = implode(',', [
                    $word->word,
                    (string) $word->action,
                    (string) $word->level,
                    $categoryCodes[(int) $word->category_id] ?? '',
                ]);
            }
        });

        return implode("\n", $lines) . "\n";
    }

    // ══════════════════════════════════════════════════════════════
    // 三、分类 CRUD
    // ══════════════════════════════════════════════════════════════

    /**
     * 分类列表（含词条计数），按 sort 升序。
     *
     * @return array<int,array<string,mixed>>
     */
    public function listCategories(bool $onlyEnabled = false): array
    {
        $query = SensitiveCategory::query();
        if ($onlyEnabled) {
            $query->enabled();
        }

        return $query->orderBy('sort')->orderBy('id')->get()->map(function (SensitiveCategory $item) {
            $arr = $item->toArray();
            $arr['status'] = $item->status->value;

            return $arr;
        })->all();
    }

    /**
     * 新增分类。
     *
     * @param array<string,mixed> $data name/code/default_action/description/sort/status/source
     * @throws RuntimeException 名称或标识重复时
     */
    public function createCategory(array $data): SensitiveCategory
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('分类名称不能为空');
        }

        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            $code = $this->buildCategoryCode($name, []);
        }
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
            throw new RuntimeException('分类标识仅允许小写字母、数字和下划线，且以字母开头');
        }

        if (SensitiveCategory::where('code', $code)->orWhere('name', $name)->exists()) {
            throw new RuntimeException('分类名称或标识已存在');
        }

        return SensitiveCategory::create([
            'name' => mb_substr($name, 0, 50),
            'code' => mb_substr($code, 0, 50),
            'default_action' => $this->normalizeAction($data['default_action'] ?? ''),
            'description' => mb_substr((string) ($data['description'] ?? ''), 0, 255),
            'sort' => (int) ($data['sort'] ?? 0),
            'is_system' => isset($data['is_system']) ? (int) $data['is_system'] : 0,
            'status' => isset($data['status']) ? (int) $data['status'] : Status::ENABLED->value,
        ]);
    }

    /**
     * 更新分类。
     *
     * @param array<string,mixed> $data
     * @throws RuntimeException 分类不存在或标识冲突时
     */
    public function updateCategory(int $id, array $data): SensitiveCategory
    {
        $item = SensitiveCategory::find($id);
        if ($item === null) {
            throw new RuntimeException('分类不存在');
        }

        if (array_key_exists('name', $data) && trim((string) $data['name']) !== '') {
            $name = trim((string) $data['name']);
            if (SensitiveCategory::where('name', $name)->where('id', '!=', $id)->exists()) {
                throw new RuntimeException('分类名称已存在');
            }
            $item->name = mb_substr($name, 0, 50);
        }

        // code 是稳定标识，系统内置分类禁止修改，避免在线词库归类失效
        if (array_key_exists('code', $data) && (int) $item->is_system !== 1) {
            $code = trim((string) $data['code']);
            if ($code !== '' && $code !== $item->code) {
                if (! preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
                    throw new RuntimeException('分类标识仅允许小写字母、数字和下划线，且以字母开头');
                }
                if (SensitiveCategory::where('code', $code)->where('id', '!=', $id)->exists()) {
                    throw new RuntimeException('分类标识已存在');
                }
                $item->code = $code;
            }
        }

        if (array_key_exists('default_action', $data)) {
            $item->default_action = $this->normalizeAction($data['default_action']);
        }
        if (array_key_exists('description', $data)) {
            $item->description = mb_substr((string) $data['description'], 0, 255);
        }
        if (array_key_exists('sort', $data)) {
            $item->sort = (int) $data['sort'];
        }
        if (array_key_exists('status', $data)) {
            $item->status = (int) $data['status'] === 1 ? Status::ENABLED : Status::DISABLED;
        }

        $item->save();
        $this->filter->rebuildDictionary();

        return $item->fresh();
    }

    /**
     * 删除分类（系统内置分类与仍有词条的分类不允许删除）。
     *
     * @throws RuntimeException
     */
    public function deleteCategory(int $id): void
    {
        $item = SensitiveCategory::find($id);
        if ($item === null) {
            throw new RuntimeException('分类不存在');
        }
        if ((int) $item->is_system === 1) {
            throw new RuntimeException('系统内置分类不允许删除');
        }
        if (SensitiveWord::where('category_id', $id)->exists()) {
            throw new RuntimeException('该分类下仍有词条，请先转移或删除词条');
        }

        $item->delete();
    }

    /**
     * 重算指定分类的词条计数。
     */
    public function refreshCategoryCount(int $categoryId): void
    {
        if ($categoryId <= 0) {
            return;
        }

        try {
            SensitiveCategory::where('id', $categoryId)->update([
                'word_count' => SensitiveWord::where('category_id', $categoryId)->count(),
            ]);
        } catch (Throwable $e) {
            // 计数为冗余展示字段，失败不影响主流程
        }
    }

    /**
     * 重算全部分类的词条计数。
     */
    public function refreshAllCategoryCounts(): void
    {
        try {
            $counts = SensitiveWord::query()
                ->selectRaw('category_id, COUNT(*) AS c')
                ->groupBy('category_id')
                ->pluck('c', 'category_id')
                ->all();

            foreach (SensitiveCategory::pluck('id') as $id) {
                SensitiveCategory::where('id', $id)->update(['word_count' => (int) ($counts[(int) $id] ?? 0)]);
            }
        } catch (Throwable $e) {
            Log::warning('[cmspro.sensitivewords] 分类计数刷新失败', ['error' => $e->getMessage()]);
        }
    }

    // ══════════════════════════════════════════════════════════════
    // 四、命中日志
    // ══════════════════════════════════════════════════════════════

    /**
     * 分页查询命中日志。
     *
     * @param array<string,mixed> $params source_app/scene/action/keyword/start_date/end_date
     */
    public function listHitLogs(array $params): LengthAwarePaginator
    {
        $query = \App\Apps\CmsproSensitivewords\Models\SensitiveHitLog::query();

        if (! empty($params['source_app'])) {
            $query->where('source_app', (string) $params['source_app']);
        }
        if (! empty($params['scene'])) {
            $query->where('scene', (string) $params['scene']);
        }
        if (! empty($params['action'])) {
            $query->where('action', (string) $params['action']);
        }
        if (! empty($params['keyword'])) {
            $keyword = (string) $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('words', 'like', '%' . $keyword . '%')
                    ->orWhere('content', 'like', '%' . $keyword . '%');
            });
        }
        if (! empty($params['start_date'])) {
            $query->where('create_time', '>=', $params['start_date'] . ' 00:00:00');
        }
        if (! empty($params['end_date'])) {
            $query->where('create_time', '<=', $params['end_date'] . ' 23:59:59');
        }

        $perPage = (int) ($params['limit'] ?? $params['per_page'] ?? 15);
        $page = (int) ($params['page'] ?? 1);

        return $query->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 删除命中日志（按 ID 批量删除）。
     *
     * @param array<int,int> $ids
     */
    public function deleteHitLogs(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));

        return $ids === [] ? 0 : \App\Apps\CmsproSensitivewords\Models\SensitiveHitLog::whereIn('id', $ids)->delete();
    }

    /**
     * 命中日志概览统计（页头卡片数据源）。
     *
     * @return array<string,int>
     */
    public function logStats(): array
    {
        try {
            $today = date('Y-m-d 00:00:00');

            return [
                'total' => \App\Apps\CmsproSensitivewords\Models\SensitiveHitLog::count(),
                'today' => \App\Apps\CmsproSensitivewords\Models\SensitiveHitLog::where('create_time', '>=', $today)->count(),
                'block' => \App\Apps\CmsproSensitivewords\Models\SensitiveHitLog::where('action', SensitiveWord::ACTION_BLOCK)->count(),
                'word_total' => SensitiveWord::count(),
                'word_enabled' => SensitiveWord::enabled()->count(),
                'category_total' => SensitiveCategory::count(),
            ];
        } catch (Throwable $e) {
            return ['total' => 0, 'today' => 0, 'block' => 0, 'word_total' => 0, 'word_enabled' => 0, 'category_total' => 0];
        }
    }

    // ══════════════════════════════════════════════════════════════
    // 五、内部工具
    // ══════════════════════════════════════════════════════════════

    /**
     * 规整输入的敏感词：去空白、去 BOM、校验长度。
     *
     * @throws RuntimeException 词条为空或超长时
     */
    protected function normalizeInputWord(mixed $word): string
    {
        $word = trim((string) $word);
        $word = preg_replace('/^\xEF\xBB\xBF/', '', $word) ?? $word;
        // 去除首尾的引号（从表格粘贴常带引号）
        $word = trim($word, "\"'“”‘’ \t");

        if ($word === '') {
            throw new RuntimeException('敏感词不能为空');
        }
        if (mb_strlen($word) > self::WORD_MAX_LENGTH) {
            throw new RuntimeException('敏感词长度不得超过 ' . self::WORD_MAX_LENGTH . ' 个字符');
        }

        return $word;
    }

    /**
     * 生成归一化匹配键（word_lower，仅用于去重）。
     *
     * 关键设计：word_lower 采用「固定归一化」（始终忽略大小写 + 全角转半角 +
     * 跳过干扰符），与运行时可配置的匹配策略解耦。原因是 word_lower 带唯一索引，
     * 职责是防止同一词被重复录入；若它随匹配策略配置变化，管理员切换开关后
     * 去重基准会漂移，导致「ABC/abc 时而算重复时而算两条」的不一致。
     *
     * 引擎建树用的是运行时配置归一化，命中回查用词条 ID（见 SensitiveWordService），
     * 因此 word_lower 是否等于引擎归一化结果并不影响过滤正确性，只影响去重稳定性。
     */
    public function buildMatchKey(string $word): string
    {
        // 固定归一化：三项策略全开，保证去重键在任何配置下都稳定一致
        $chars = (new SensitiveWordEngine(true, true, true))->normalizeWord($word);

        $key = implode('', $chars);
        if ($key === '') {
            throw new RuntimeException('敏感词「' . $word . '」归一化后为空，无法作为匹配键');
        }

        return mb_substr($key, 0, self::WORD_MAX_LENGTH);
    }

    /**
     * 规整动作值：接受英文标识与中文名称，非法值归一为空（跟随上级策略）。
     */
    protected function normalizeAction(mixed $action): string
    {
        $action = trim((string) $action);

        return match ($action) {
            'block', '禁止', '拦截', 'reject' => SensitiveWord::ACTION_BLOCK,
            'replace', '替换', 'mask' => SensitiveWord::ACTION_REPLACE,
            'warn', '提醒', '警告', 'notice' => SensitiveWord::ACTION_WARN,
            default => '',
        };
    }

    /**
     * 规整级别值（限定 1-3）。
     */
    protected function normalizeLevel(mixed $level): int
    {
        $level = (int) $level;

        return in_array($level, [1, 2, 3], true) ? $level : 1;
    }

    /**
     * 由分类名生成合法 code（中文名转拼音不可靠，统一用时间戳 + 序号兜底）。
     *
     * @param array<string,int> $existing 已占用的 code => id 映射
     */
    protected function buildCategoryCode(string $name, array $existing): string
    {
        // 名称本身即合法标识（英文分类）时直接使用
        $slug = mb_strtolower(trim($name));
        if (preg_match('/^[a-z][a-z0-9_]*$/', $slug) && ! isset($existing[$slug])
            && ! SensitiveCategory::where('code', $slug)->exists()) {
            return $slug;
        }

        $base = 'cat_' . substr(md5($name), 0, 8);
        $code = $base;
        $seq = 1;
        while (isset($existing[$code]) || SensitiveCategory::where('code', $code)->exists()) {
            $code = $base . '_' . (++$seq);
        }

        return $code;
    }
}
