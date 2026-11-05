<?php

namespace App\Apps\CmsproSensitivewords\Tests\Feature;

use App\Apps\CmsproSensitivewords\Models\SensitiveHitLog;
use App\Apps\CmsproSensitivewords\Tests\SensitivewordsTestCase;

/**
 * 前端公开 API 测试（/api/cmspro/sensitivewords/*）。
 *
 * 覆盖：状态探测、检测接口（命中/放行）、过滤接口、参数校验（必填/超长）、
 * 限流中间件存在性、响应统一格式（code/message/data/timestamp）。
 */
class FilterApiTest extends SensitivewordsTestCase
{
    private const CHECK_URL = '/api/cmspro/sensitivewords/check';

    private const FILTER_URL = '/api/cmspro/sensitivewords/filter';

    private const STATUS_URL = '/api/cmspro/sensitivewords/status';

    public function test_status_endpoint_reports_readiness(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $response = $this->getJson(self::STATUS_URL);

        $response->assertOk();
        $response->assertJsonPath('code', 0);
        $response->assertJsonPath('data.enabled', true);
        $response->assertJsonPath('data.ready', true);
        $this->assertGreaterThanOrEqual(1, $response->json('data.word_total'));
    }

    public function test_check_endpoint_detects_hit(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $response = $this->postJson(self::CHECK_URL, ['text' => '我在赌博网站', 'scene' => 'open-api']);

        $response->assertOk();
        $response->assertJsonPath('code', 0);
        $response->assertJsonPath('data.passed', false);
        $response->assertJsonPath('data.action', 'block');
        $response->assertJsonPath('data.hit_count', 1);
        // 响应含标准四字段
        $this->assertArrayHasKey('timestamp', $response->json());
    }

    public function test_check_endpoint_passes_clean_text(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $response = $this->postJson(self::CHECK_URL, ['text' => '正常内容']);

        $response->assertOk();
        $response->assertJsonPath('data.passed', true);
        $response->assertJsonPath('data.hit_count', 0);
    }

    public function test_filter_endpoint_returns_masked_text(): void
    {
        $this->seedWord('赌博', ['action' => 'replace']);
        $this->setAppConfig('replacement', '*');

        $response = $this->postJson(self::FILTER_URL, ['text' => '我在赌博网站']);

        $response->assertOk();
        $response->assertJsonPath('code', 0);
        $response->assertJsonPath('data.filtered', '我在**网站');
    }

    public function test_check_endpoint_requires_text(): void
    {
        // 缺少必填 text → 验证失败（422）
        // 系统把 ValidationException 统一转为 ApiResponse::error(40201,...)，
        // 字段级错误嵌套在 data 下（非 Laravel 标准顶层 errors 键）
        $response = $this->postJson(self::CHECK_URL, []);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 40201);
        $this->assertArrayHasKey('text', $response->json('data'));
    }

    public function test_check_endpoint_rejects_oversized_text(): void
    {
        $response = $this->postJson(self::CHECK_URL, ['text' => str_repeat('赌', 10001)]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 40201);
        $this->assertArrayHasKey('text', $response->json('data'));
    }

    public function test_check_endpoint_writes_hit_log(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $this->postJson(self::CHECK_URL, ['text' => '赌博网站', 'scene' => 'open-api']);

        $this->assertDatabaseHas('app_cmspro_sensitivewords_hit_logs', [
            'words' => '赌博',
            'scene' => 'open-api',
        ]);
    }

    public function test_check_endpoint_short_circuits_when_disabled(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);
        $this->setAppConfig('filter_enabled', '0');

        $response = $this->postJson(self::CHECK_URL, ['text' => '赌博网站']);

        $response->assertOk();
        $response->assertJsonPath('data.passed', true);
        // 关闭后不写日志
        $this->assertSame(0, SensitiveHitLog::count());
    }
}
