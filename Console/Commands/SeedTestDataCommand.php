<?php

namespace App\Apps\CmsproSensitivewords\Console\Commands;

use App\Apps\CmsproSensitivewords\Models\SensitiveCategory;
use App\Apps\CmsproSensitivewords\Models\SensitiveHitLog;
use App\Apps\CmsproSensitivewords\Models\SensitiveWord;
use App\Apps\CmsproSensitivewords\Services\SensitiveLibraryService;
use App\Apps\CmsproSensitivewords\Services\SensitiveWordManageService;
use App\Apps\CmsproSensitivewords\Services\SensitiveWordService;
use App\Enums\Status;
use Illuminate\Console\Command;

/**
 * 功能测试数据填充命令。
 *
 * 为后台各列表页提供可筛选、可操作的演示数据（当前库中词条多为 library
 * 来源、命中日志为空，列表页筛选与统计缺少验证对象）。填充三类数据：
 *
 * | 数据 | 特征前缀 | 用途 |
 * |------|---------|------|
 * | 自定义分类 2 个 | code `demo_` | 验证自定义分类增删改与词条归类 |
 * | 手工词条 20 条 | 词名 `【测试】` | 覆盖 block/replace/warn × 级别 1-3 × 启用/禁用，列表页筛选与状态开关可测 |
 * | 命中日志 30 条 | scene `demo.` | 近 7 天分布、多来源应用、三种动作，日志筛选/清理/统计可测 |
 *
 * 安全设计：
 * - **前缀隔离**：全部测试数据带上述特征前缀，`--clean` 按前缀精确删除，
 *   不触碰 library/builtin 词条与系统分类；
 * - **防呆**：检测到已存在测试数据时拒绝填充并提示先 `--clean`，避免重复堆积；
 * - **词典同步**：填充与清理后均重建词典缓存，过滤能力即时生效/失效；
 * - **日期规范**：create_time 一律 `Y-m-d H:i:s`，禁止 ISO 8601。
 */
class SeedTestDataCommand extends Command
{
    /**
     * 命令签名。
     *
     * @var string
     */
    protected $signature = 'cmspro:sensitivewords:seed-testdata
                            {--clean : 清除已填充的测试数据（按特征前缀精确删除）}';

    /**
     * 命令描述。
     *
     * @var string
     */
    protected $description = '为敏感词应用填充功能测试数据（自定义分类/手工词条/命中日志），支持 --clean 精确回滚';

    /** 测试词条名前缀 */
    private const WORD_PREFIX = '【测试】';

    /** 测试分类 code 前缀 */
    private const CATEGORY_PREFIX = 'demo_';

    /** 测试命中日志 scene 前缀 */
    private const SCENE_PREFIX = 'demo.';

    /**
     * 测试分类定义（code => [名称, 默认动作, 说明]）。
     */
    private const CATEGORIES = [
        'demo_site_banned' => ['【测试】站点违禁', 'block', '测试用自定义分类：站点级违禁表述'],
        'demo_industry' => ['【测试】行业敏感', 'replace', '测试用自定义分类：行业敏感表述'],
    ];

    /**
     * 测试词条定义：词名后缀 => [动作, 级别, 分类code, 是否禁用]。
     *
     * 刻意覆盖三动作 × 三级别 × 启用/禁用，使列表页各筛选项都有数据可测。
     */
    private const WORDS = [
        '违禁词甲' => ['block', 3, 'demo_site_banned', false],
        '违禁词乙' => ['block', 3, 'demo_site_banned', false],
        '违禁词丙' => ['block', 2, 'demo_site_banned', false],
        '违禁词丁' => ['block', 3, 'demo_site_banned', false],
        '内部暗语一' => ['replace', 2, 'demo_site_banned', false],
        '内部暗语二' => ['replace', 2, 'demo_site_banned', false],
        '内部暗语三' => ['replace', 1, 'demo_site_banned', false],
        '广告链接' => ['warn', 1, 'demo_industry', false],
        '推广话术' => ['warn', 1, 'demo_industry', false],
        '营销软文' => ['replace', 2, 'demo_industry', false],
        '刷量渠道' => ['replace', 2, 'demo_industry', false],
        '代刷服务' => ['block', 3, 'demo_industry', false],
        '违规导流' => ['block', 3, 'demo_industry', false],
        '竞品诋毁' => ['warn', 2, 'demo_industry', false],
        '虚假宣传' => ['block', 3, 'demo_industry', false],
        '夸大功效' => ['replace', 2, 'demo_industry', false],
        '诱导话术' => ['warn', 1, 'demo_industry', false],
        '停用语例甲' => ['block', 3, 'demo_site_banned', true],
        '停用语例乙' => ['replace', 2, 'demo_industry', true],
        '停用语例丙' => ['warn', 1, 'demo_industry', true],
    ];

    /**
     * 命中日志的来源应用与场景（循环取用，保证多样性）。
     */
    private const LOG_SOURCES = [
        ['cmspro.blog', 'demo.article.publish', '/blog/article/save'],
        ['cmspro.blog', 'demo.comment.create', '/blog/comment/post'],
        ['cmspro.forum', 'demo.topic.create', '/forum/topic/save'],
        ['cmspro.forum', 'demo.reply.create', '/forum/reply/post'],
        ['cmspro.message', 'demo.message.send', '/message/send'],
    ];

    /** 命中日志条数 */
    private const LOG_COUNT = 30;

    public function handle(
        SensitiveLibraryService $library,
        SensitiveWordManageService $manage,
        SensitiveWordService $filter
    ): int {
        if ($this->option('clean')) {
            return $this->clean($filter);
        }

        // 防呆：已有测试数据时拒绝填充，避免重复堆积难以清理
        $existing = SensitiveWord::where('word', 'like', self::WORD_PREFIX . '%')->count();
        if ($existing > 0) {
            $this->error(sprintf('检测到已存在 %d 条测试词条，请先执行 --clean 清理后再填充，避免数据重复堆积。', $existing));
            $this->line('  php artisan cmspro:sensitivewords:seed-testdata --clean');

            return self::SUCCESS;
        }

        $categories = $this->seedCategories($manage);
        $wordCount = $this->seedWords($library);
        $disabled = $this->disableSampleWords();
        $logCount = $this->seedHitLogs();

        // 词条状态变更后词典需同步（禁用词条不应参与匹配）
        $filter->rebuildDictionary();

        $this->info('测试数据填充完成');
        $this->line(sprintf(
            '  自定义分类：%d 个（code 前缀 %s）',
            count($categories),
            self::CATEGORY_PREFIX
        ));
        $this->line(sprintf(
            '  手工词条：%d 条（含禁用 %d 条，词名前缀 %s）',
            $wordCount,
            $disabled,
            self::WORD_PREFIX
        ));
        $this->line(sprintf(
            '  命中日志：%d 条（近 7 天分布，scene 前缀 %s）',
            $logCount,
            self::SCENE_PREFIX
        ));
        $this->newLine();
        $this->line('词典缓存已重建，测试词条立即参与过滤匹配。');
        $this->comment('清理：php artisan cmspro:sensitivewords:seed-testdata --clean');

        return self::SUCCESS;
    }

    /**
     * 创建测试分类（复用 createCategory，享受其唯一性校验与 code 格式校验）。
     *
     * @return array<int,SensitiveCategory>
     */
    private function seedCategories(SensitiveWordManageService $manage): array
    {
        $created = [];

        foreach (self::CATEGORIES as $code => [$name, $defaultAction, $description]) {
            $created[] = $manage->createCategory([
                'name' => $name,
                'code' => $code,
                'default_action' => $defaultAction,
                'description' => $description,
                'is_system' => 0,
                'sort' => 90,
                'status' => Status::ENABLED->value,
            ]);
        }

        return $created;
    }

    /**
     * 批量填充测试词条。
     *
     * 走 importFromText 批量导入而非逐条 createWord：后者每条都重建词典缓存，
     * 20 条即 20 次全量编译；批量导入只在结束时重建一次。
     */
    private function seedWords(SensitiveLibraryService $library): int
    {
        $lines = [];

        foreach (self::WORDS as $suffix => [$action, $level, $categoryCode]) {
            // 四段式：词,动作,级别,分类code（与导出/导入同口径）
            $lines[] = implode(',', [self::WORD_PREFIX . $suffix, $action, $level, $categoryCode]);
        }

        $stat = $library->importFromText(
            implode("\n", $lines),
            SensitiveWord::SOURCE_MANUAL,
            'skip'
        );

        return (int) $stat['inserted'];
    }

    /**
     * 将部分词条置为禁用，供「状态」筛选与开关切换测试。
     */
    private function disableSampleWords(): int
    {
        $disabled = [];

        foreach (self::WORDS as $suffix => [$action, $level, $categoryCode, $isDisabled]) {
            if ($isDisabled) {
                $disabled[] = self::WORD_PREFIX . $suffix;
            }
        }

        if ($disabled === []) {
            return 0;
        }

        return SensitiveWord::whereIn('word', $disabled)
            ->update(['status' => Status::DISABLED->value]);
    }

    /**
     * 填充近 7 天的命中日志。
     *
     * 时间用确定性算法分布（不依赖随机数），保证测试断言稳定；
     * 一律输出 Y-m-d H:i:s，且只取过去时刻，不产生未来时间。
     */
    private function seedHitLogs(): int
    {
        $rows = [];
        $words = array_keys(self::WORDS);

        for ($i = 0; $i < self::LOG_COUNT; $i++) {
            [$sourceApp, $scene, $path] = self::LOG_SOURCES[$i % count(self::LOG_SOURCES)];

            // 取 1-2 个命中词，动作取第一个命中词的定义动作
            $picked = [$words[$i % count($words)]];
            if ($i % 3 === 0) {
                $picked[] = $words[($i + 7) % count($words)];
            }

            $action = self::WORDS[$picked[0]][0];
            $dayOffset = $i % 7;
            // 每天错开小时，避免全部落在同一时刻
            $hour = 8 + (($i * 3) % 12);
            $minute = ($i * 7) % 60;

            $rows[] = [
                'source_app' => $sourceApp,
                'scene' => $scene,
                'words' => implode(',', array_map(fn ($w) => self::WORD_PREFIX . $w, $picked)),
                'word_count' => count($picked),
                'action' => $action,
                'content' => sprintf('【测试数据】第 %d 条命中样本，包含%s等敏感词。', $i + 1, implode('、', $picked)),
                'request_path' => $path,
                'ip' => sprintf('192.168.%d.%d', ($i % 5) + 1, ($i * 3) % 200 + 10),
                'user_id' => $i % 4 === 0 ? 0 : ($i % 20) + 1,
                'admin_id' => 0,
                // 只取过去时刻：当前时间回退 dayOffset 天，再定位到指定时分
                'create_time' => date('Y-m-d H:i:s', strtotime(sprintf('-%d days', $dayOffset)) - ((int) date('H') * 3600 + (int) date('i') * 60) + $hour * 3600 + $minute * 60),
                'update_time' => null,
            ];
        }

        // update_time 与 create_time 保持一致（列可为空，但冗余列留空会造成排序歧义）
        foreach ($rows as $index => $row) {
            $rows[$index]['update_time'] = $row['create_time'];
        }

        SensitiveHitLog::insert($rows);

        return count($rows);
    }

    /**
     * 清除测试数据（按特征前缀精确删除，不影响业务数据）。
     */
    private function clean(SensitiveWordService $filter): int
    {
        $words = SensitiveWord::where('word', 'like', self::WORD_PREFIX . '%')->count();
        $categories = SensitiveCategory::where('code', 'like', self::CATEGORY_PREFIX . '%')->count();
        $logs = SensitiveHitLog::where('scene', 'like', self::SCENE_PREFIX . '%')->count();

        if ($words === 0 && $categories === 0 && $logs === 0) {
            $this->comment('未发现测试数据，无需清理。');

            return self::SUCCESS;
        }

        // 先删词条再删分类：分类的 word_count 为冗余列，删除词条后一并刷新
        SensitiveWord::where('word', 'like', self::WORD_PREFIX . '%')->delete();
        SensitiveCategory::where('code', 'like', self::CATEGORY_PREFIX . '%')->delete();
        SensitiveHitLog::where('scene', 'like', self::SCENE_PREFIX . '%')->delete();

        // 词条已移除，词典须重建，否则测试词仍会命中
        $filter->rebuildDictionary();

        $this->info(sprintf('已清除测试数据：词条 %d 条、分类 %d 个、命中日志 %d 条。', $words, $categories, $logs));
        $this->line('业务数据（library/builtin 词条、系统分类）未受影响。');

        return self::SUCCESS;
    }
}
