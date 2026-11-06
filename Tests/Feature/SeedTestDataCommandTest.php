<?php

namespace App\Apps\CmsproSensitivewords\Tests\Feature;

use App\Apps\CmsproSensitivewords\Models\SensitiveCategory;
use App\Apps\CmsproSensitivewords\Models\SensitiveHitLog;
use App\Apps\CmsproSensitivewords\Models\SensitiveWord;
use App\Apps\CmsproSensitivewords\Tests\SensitivewordsTestCase;
use App\Enums\Status;

/**
 * 测试数据填充命令测试（cmspro:sensitivewords:seed-testdata）。
 *
 * 命令职责：为功能测试填充三类演示数据——自定义分类（code 前缀 demo_）、
 * 手工词条（词名前缀【测试】、source=manual）、命中日志（scene 前缀 demo.、
 * 近 7 天分布），并支持 --clean 按前缀精确回滚。
 *
 * 守护要点：
 * 1. 三类数据落库且覆盖各枚举维度（动作/级别/状态/来源应用），列表页筛选才有数据可测；
 * 2. 词典即时生效：seed 后测试词条能被过滤命中，无需手工重建缓存；
 * 3. --clean 精确回滚：只删带特征前缀的数据，library 词条与系统分类必须原样保留；
 * 4. 防呆：已有测试数据时拒绝重复填充，避免数据堆积；
 * 5. 日期规范：create_time 一律 Y-m-d H:i:s，禁止 ISO 8601（01 规范第 1 条）。
 */
class SeedTestDataCommandTest extends SensitivewordsTestCase
{
    private const COMMAND = 'cmspro:sensitivewords:seed-testdata';

    // ══ 一、填充落库 ══════════════════════════════════════════════

    public function test_command_seeds_all_three_data_types(): void
    {
        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('测试数据填充完成')
            ->assertExitCode(0);

        $this->assertGreaterThan(0, SensitiveWord::where('source', SensitiveWord::SOURCE_MANUAL)->count());
        $this->assertGreaterThan(0, SensitiveCategory::where('code', 'like', 'demo_%')->count());
        $this->assertGreaterThan(0, SensitiveHitLog::where('scene', 'like', 'demo.%')->count());
    }

    public function test_seeded_words_cover_actions_levels_and_status(): void
    {
        $this->artisan(self::COMMAND)->assertExitCode(0);

        $words = SensitiveWord::where('word', 'like', '【测试】%')->get();
        $this->assertGreaterThanOrEqual(20, $words->count());

        // 三种动作都要有，列表页「动作」筛选才有数据可测
        foreach (['block', 'replace', 'warn'] as $action) {
            $this->assertGreaterThan(0, $words->where('action', $action)->count(), "缺少 action={$action} 的测试词条");
        }

        // 级别 1-3 覆盖
        foreach ([1, 2, 3] as $level) {
            $this->assertGreaterThan(0, $words->where('level', $level)->count(), "缺少 level={$level} 的测试词条");
        }

        // 含禁用词条，「状态」筛选与开关切换才有对象
        $this->assertGreaterThan(0, $words->where('status', Status::DISABLED)->count(), '缺少禁用状态的测试词条');

        // 全部为 manual 来源（来源筛选可测）
        $this->assertSame($words->count(), $words->where('source', SensitiveWord::SOURCE_MANUAL)->count());
    }

    public function test_seeded_words_are_valid_utf8_and_normalized_unique(): void
    {
        // 回归防护：曾因 trim 字节剥离产生非法 UTF-8 词条
        $this->artisan(self::COMMAND)->assertExitCode(0);

        $words = SensitiveWord::where('word', 'like', '【测试】%')->pluck('word')->all();

        $keys = [];
        foreach ($words as $word) {
            $this->assertTrue(mb_check_encoding($word, 'UTF-8'), "词条非法 UTF-8：{$word}");
            $lower = SensitiveWord::where('word', $word)->value('word_lower');
            $this->assertTrue(mb_check_encoding((string) $lower, 'UTF-8'), "word_lower 非法 UTF-8：{$lower}");
            $this->assertNotContains($lower, $keys, "归一化键冲突：{$word}");
            $keys[] = $lower;
        }
    }

    public function test_seeded_categories_are_custom_with_accurate_counts(): void
    {
        $this->artisan(self::COMMAND)->assertExitCode(0);

        $categories = SensitiveCategory::where('code', 'like', 'demo_%')->get();
        $this->assertGreaterThanOrEqual(2, $categories->count());

        foreach ($categories as $category) {
            $this->assertSame(0, (int) $category->is_system, '测试分类不得标记为系统分类');
            $this->assertSame(Status::ENABLED, $category->status);

            // 冗余计数与实际词条一致（列表页展示不能失真）
            $actual = SensitiveWord::where('category_id', $category->id)->count();
            $this->assertSame($actual, (int) $category->word_count, "分类 {$category->code} 计数不一致");
        }
    }

    public function test_seeded_hit_logs_span_recent_days_with_valid_datetime(): void
    {
        $this->artisan(self::COMMAND)->assertExitCode(0);

        $logs = SensitiveHitLog::where('scene', 'like', 'demo.%')->get();
        $this->assertGreaterThanOrEqual(30, $logs->count());

        $earliest = null;
        foreach ($logs as $log) {
            // 日期规范：Y-m-d H:i:s，禁止 ISO 8601
            $raw = $log->getRawOriginal('create_time');
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
                (string) $raw,
                'create_time 必须为 Y-m-d H:i:s 格式'
            );

            $time = strtotime((string) $raw);
            $this->assertNotFalse($time);
            // 分布在近 7 天内（日志页按时间筛选/清理才有意义）
            $this->assertGreaterThan(time() - 8 * 86400, $time, '日志时间超出近 7 天窗口');
            $this->assertLessThanOrEqual(time() + 60, $time, '日志时间不得晚于当前');

            if ($earliest === null || $time < $earliest) {
                $earliest = $time;
            }

            // 关键字段非空
            $this->assertNotSame('', $log->source_app);
            $this->assertNotSame('', $log->action);
            $this->assertNotSame('', $log->words);
            $this->assertGreaterThan(0, $log->word_count);
        }

        // 时间确有分布（不是全部同一秒），统计图才有形态
        $distinctDays = $logs->map(fn ($l) => substr((string) $l->getRawOriginal('create_time'), 0, 10))->unique()->count();
        $this->assertGreaterThan(1, $distinctDays, '日志时间应跨多天分布');
    }

    public function test_seeded_hit_logs_cover_multiple_sources_and_actions(): void
    {
        $this->artisan(self::COMMAND)->assertExitCode(0);

        $logs = SensitiveHitLog::where('scene', 'like', 'demo.%')->get();

        // 多来源应用（日志页「来源应用」筛选可测）
        $this->assertGreaterThan(1, $logs->pluck('source_app')->unique()->count());

        // 多动作（block/replace/warn 都有）
        foreach (['block', 'replace', 'warn'] as $action) {
            $this->assertGreaterThan(0, $logs->where('action', $action)->count(), "缺少 action={$action} 的日志");
        }
    }

    // ══ 二、词典即时生效 ══════════════════════════════════════════

    public function test_seeded_words_take_effect_in_filtering(): void
    {
        $this->artisan(self::COMMAND)->assertExitCode(0);

        // 取一条 block 动作的启用测试词条验证拦截
        $word = SensitiveWord::where('word', 'like', '【测试】%')
            ->where('action', 'block')
            ->where('status', 1)
            ->value('word');
        $this->assertNotNull($word, '应存在启用的 block 测试词条');

        $result = $this->filterService()->check("这句话包含{$word}用于验证");

        $this->assertFalse($result['passed']);
        $this->assertSame('block', $result['action']);
    }

    // ══ 三、防呆与幂等 ══════════════════════════════════════════

    public function test_repeated_seed_is_rejected_with_hint(): void
    {
        $this->artisan(self::COMMAND)->assertExitCode(0);

        $before = SensitiveWord::where('word', 'like', '【测试】%')->count();

        // 二次执行应防呆拒绝（避免数据堆积），并提示先 --clean
        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('--clean')
            ->assertExitCode(0);

        $this->assertSame($before, SensitiveWord::where('word', 'like', '【测试】%')->count(), '重复执行不得叠加数据');
    }

    // ══ 四、--clean 精确回滚 ══════════════════════════════════════

    public function test_clean_removes_seeded_data_only(): void
    {
        // 预埋「非测试数据」：library 词条与系统分类，验证 --clean 不误删
        $this->seedWord('官方词条保留验证', ['action' => 'block']);
        SensitiveWord::where('word', '官方词条保留验证')->update(['source' => SensitiveWord::SOURCE_LIBRARY]);

        $this->artisan(self::COMMAND)->assertExitCode(0);
        $this->assertGreaterThan(0, SensitiveWord::where('word', 'like', '【测试】%')->count());

        $this->artisan(self::COMMAND, ['--clean' => true])
            ->expectsOutputToContain('已清除')
            ->assertExitCode(0);

        // 三类测试数据全清
        $this->assertSame(0, SensitiveWord::where('word', 'like', '【测试】%')->count());
        $this->assertSame(0, SensitiveCategory::where('code', 'like', 'demo_%')->count());
        $this->assertSame(0, SensitiveHitLog::where('scene', 'like', 'demo.%')->count());

        // 非测试数据原样保留
        $this->assertNotNull(SensitiveWord::where('word', '官方词条保留验证')->first());
        $this->assertGreaterThan(0, SensitiveCategory::where('is_system', 1)->count());
    }

    public function test_clean_without_seed_data_is_safe(): void
    {
        // 无测试数据时 --clean 不应报错
        $this->artisan(self::COMMAND, ['--clean' => true])
            ->assertExitCode(0);

        $this->assertSame(0, SensitiveWord::where('word', 'like', '【测试】%')->count());
    }

    public function test_clean_removes_dictionary_effect(): void
    {
        $this->artisan(self::COMMAND)->assertExitCode(0);

        $word = SensitiveWord::where('word', 'like', '【测试】%')->where('action', 'block')->where('status', 1)->value('word');
        $this->assertFalse($this->filterService()->check("包含{$word}的句子")['passed']);

        $this->artisan(self::COMMAND, ['--clean' => true])->assertExitCode(0);

        // 清理后词典同步重建，测试词条不再命中
        $this->assertTrue($this->filterService()->check("包含{$word}的句子")['passed']);
    }

    public function test_seed_after_clean_works_again(): void
    {
        $this->artisan(self::COMMAND)->assertExitCode(0);
        $this->artisan(self::COMMAND, ['--clean' => true])->assertExitCode(0);

        // 清理后可再次填充（闭环可重复使用）
        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('测试数据填充完成')
            ->assertExitCode(0);

        $this->assertGreaterThan(0, SensitiveWord::where('word', 'like', '【测试】%')->count());
    }
}
