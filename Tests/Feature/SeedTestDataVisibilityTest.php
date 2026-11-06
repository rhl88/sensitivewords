<?php

namespace App\Apps\CmsproSensitivewords\Tests\Feature;

use App\Apps\CmsproSensitivewords\Models\SensitiveCategory;
use App\Apps\CmsproSensitivewords\Models\SensitiveHitLog;
use App\Apps\CmsproSensitivewords\Models\SensitiveWord;
use App\Apps\CmsproSensitivewords\Tests\SensitivewordsTestCase;
use App\Enums\Status;
use Illuminate\Support\Facades\Artisan;

/**
 * 后台列表接口对测试数据的可见性验证。
 *
 * seed-testdata 命令的价值在于「后台各列表页有数据可筛选」，故需验证
 * 词条列表 / 命中日志列表 / 分类列表接口确实能返回测试数据，
 * 且各筛选项（动作、级别、来源、状态、时间）过滤结果正确。
 */
class SeedTestDataVisibilityTest extends SensitivewordsTestCase
{
    private const COMMAND = 'cmspro:sensitivewords:seed-testdata';

    private const WORDS_API = '/admin/cmspro/sensitivewords/api/words';

    private const LOGS_API = '/admin/cmspro/sensitivewords/api/hit-logs';

    private const CATEGORY_API = '/admin/cmspro/sensitivewords/api/categories';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan(self::COMMAND)->assertExitCode(0);
    }

    // ══ 词条列表 ══════════════════════════════════════════════

    public function test_word_list_contains_seeded_words(): void
    {
        $this->actingAdmin();

        // count 为总数；data 仅当页（默认 limit=15），故显式传 limit 取全量再核对内容
        $response = $this->getJson(self::WORDS_API . '?keyword=' . urlencode('【测试】') . '&limit=50');

        $response->assertOk();
        $this->assertSame(20, $response->json('count'));

        $data = $response->json('data');
        $this->assertCount(20, $data);

        // 每行均为测试词条且为 manual 来源
        foreach ($data as $row) {
            $this->assertStringStartsWith('【测试】', (string) $row['word']);
            $this->assertSame(SensitiveWord::SOURCE_MANUAL, $row['source']);
        }
    }

    public function test_word_list_default_pagination_limit(): void
    {
        $this->actingAdmin();

        // 默认 limit=15：总数 20 但当页只返回 15，验证分页未被 seed 破坏
        $response = $this->getJson(self::WORDS_API . '?keyword=' . urlencode('【测试】'));

        $response->assertOk();
        $this->assertSame(20, $response->json('count'));
        $this->assertCount(15, $response->json('data'));
    }

    public function test_word_list_filters_by_action(): void
    {
        $this->actingAdmin();

        foreach (['block' => 8, 'replace' => 7, 'warn' => 5] as $action => $expected) {
            $response = $this->getJson(self::WORDS_API . '?action=' . $action . '&keyword=' . urlencode('【测试】'));

            $response->assertOk();
            $this->assertSame($expected, $response->json('count'), "action={$action} 筛选结果不符");
        }
    }

    public function test_word_list_filters_by_source_and_status(): void
    {
        $this->actingAdmin();

        // 来源筛选：manual 只有测试词条
        $response = $this->getJson(self::WORDS_API . '?source=manual');
        $response->assertOk();
        $this->assertSame(20, $response->json('count'));

        // 状态筛选：禁用词条可单独筛出（此前库中无任何禁用词，该筛选形同虚设）
        $disabled = $this->getJson(self::WORDS_API . '?status=0');
        $disabled->assertOk();
        $this->assertSame(3, $disabled->json('count'));
    }

    public function test_word_list_filters_by_category(): void
    {
        $this->actingAdmin();

        $category = SensitiveCategory::where('code', 'demo_site_banned')->first();
        $this->assertNotNull($category);

        $response = $this->getJson(self::WORDS_API . '?category_id=' . $category->id);

        $response->assertOk();
        $this->assertSame(8, $response->json('count'));
    }

    public function test_word_list_returns_datetime_in_required_format(): void
    {
        $this->actingAdmin();

        $response = $this->getJson(self::WORDS_API . '?keyword=' . urlencode('【测试】'));
        $response->assertOk();

        $row = $response->json('data.0');
        $this->assertArrayHasKey('create_time', $row);
        // 日期规范：Y-m-d H:i:s，禁止 ISO 8601
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row['create_time']);
        $this->assertStringNotContainsString('T', (string) $row['create_time']);
    }

    // ══ 命中日志列表 ══════════════════════════════════════════

    public function test_hit_log_list_contains_seeded_logs(): void
    {
        $this->actingAdmin();

        $response = $this->getJson(self::LOGS_API);

        $response->assertOk();
        $this->assertGreaterThanOrEqual(30, $response->json('count'));
    }

    public function test_hit_log_list_filters_by_source_app(): void
    {
        $this->actingAdmin();

        $response = $this->getJson(self::LOGS_API . '?source_app=cmspro.forum');

        $response->assertOk();
        $this->assertGreaterThan(0, $response->json('count'));

        foreach ($response->json('data') as $row) {
            $this->assertSame('cmspro.forum', $row['source_app']);
        }
    }

    public function test_hit_log_list_filters_by_action(): void
    {
        $this->actingAdmin();

        $response = $this->getJson(self::LOGS_API . '?action=warn');

        $response->assertOk();
        $this->assertGreaterThan(0, $response->json('count'));

        foreach ($response->json('data') as $row) {
            $this->assertSame('warn', $row['action']);
        }
    }

    public function test_hit_log_datetime_format(): void
    {
        $this->actingAdmin();

        $response = $this->getJson(self::LOGS_API);
        $response->assertOk();

        foreach ($response->json('data') as $row) {
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
                (string) $row['create_time'],
                '日志 create_time 必须为 Y-m-d H:i:s'
            );
        }
    }

    // ══ 分类列表 ══════════════════════════════════════════════

    public function test_category_list_contains_custom_categories(): void
    {
        $this->actingAdmin();

        $response = $this->getJson(self::CATEGORY_API);

        $response->assertOk();
        $data = $response->json('data');

        $codes = array_column(is_array($data) ? $data : [], 'code');
        $this->assertContains('demo_site_banned', $codes);
        $this->assertContains('demo_industry', $codes);

        // 自定义分类须可与系统分类区分（is_system=0）
        foreach ($data as $row) {
            if (str_starts_with((string) $row['code'], 'demo_')) {
                $this->assertSame(0, (int) $row['is_system']);
                $this->assertGreaterThan(0, (int) $row['word_count']);
            }
        }
    }

    // ══ 未登录拦截仍生效（填充数据不得绕过权限）══════════════════

    public function test_apis_still_reject_guest(): void
    {
        foreach ([self::WORDS_API, self::LOGS_API, self::CATEGORY_API] as $url) {
            $response = $this->getJson($url);
            $this->assertNotSame(200, $response->getStatusCode(), "未登录访问 {$url} 不应成功");
        }
    }

    // ══ 清理后列表恢复原状 ══════════════════════════════════════

    public function test_lists_return_to_baseline_after_clean(): void
    {
        Artisan::call(self::COMMAND, ['--clean' => true]);

        $this->assertSame(0, SensitiveWord::where('word', 'like', '【测试】%')->count());
        $this->assertSame(0, SensitiveHitLog::where('scene', 'like', 'demo.%')->count());

        $this->actingAdmin();

        // 词条列表不再有测试数据
        $response = $this->getJson(self::WORDS_API . '?source=manual');
        $response->assertOk();
        $this->assertSame(0, $response->json('count'));

        // 日志列表清空
        $logs = $this->getJson(self::LOGS_API);
        $logs->assertOk();
        $this->assertSame(0, $logs->json('count'));
    }
}
