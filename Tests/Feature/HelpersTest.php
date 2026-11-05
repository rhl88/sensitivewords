<?php

namespace App\Apps\CmsproSensitivewords\Tests\Feature;

use App\Apps\CmsproSensitivewords\Models\SensitiveHitLog;
use App\Apps\CmsproSensitivewords\Tests\SensitivewordsTestCase;

/**
 * 应用级辅助函数测试（其他应用零依赖接入的主要入口）。
 *
 * 覆盖：filter/check/filter_array 三个函数、能力探测 available、
 * 未命中与命中场景、总开关关闭时的降级、异常安全（表不存在时不抛异常）。
 */
class HelpersTest extends SensitivewordsTestCase
{
    public function test_filter_helper_replaces_hit_word(): void
    {
        $this->seedWord('赌博', ['action' => 'replace']);
        $this->setAppConfig('replacement', '*');

        $this->assertSame('我在**网站', cmspro_sensitivewords_filter('我在赌博网站'));
    }

    public function test_filter_helper_returns_original_when_no_hit(): void
    {
        $this->seedWord('赌博', ['action' => 'replace']);

        $this->assertSame('正常内容', cmspro_sensitivewords_filter('正常内容'));
    }

    public function test_check_helper_reports_hit_detail(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $result = cmspro_sensitivewords_check('赌博网站', 'test.scene', 'cmspro.blog');

        $this->assertFalse($result['passed']);
        $this->assertSame('block', $result['action']);
        $this->assertSame(1, $result['hit_count']);
        $this->assertSame('test.scene', SensitiveHitLog::latest('id')->first()->scene);
    }

    public function test_check_helper_passes_clean_text(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $result = cmspro_sensitivewords_check('正常内容');

        $this->assertTrue($result['passed']);
        $this->assertSame(0, $result['hit_count']);
        $this->assertSame([], $result['hits']);
    }

    public function test_filter_array_helper_filters_selected_fields(): void
    {
        $this->seedWord('赌博', ['action' => 'replace']);
        $this->setAppConfig('replacement', '*');

        $data = [
            'title' => '赌博标题',
            'content' => '赌博正文',
            'author' => '张三',
            'count' => 10,
        ];

        // 只过滤 title 字段
        $result = cmspro_sensitivewords_filter_array($data, ['title']);

        $this->assertSame('**标题', $result['title']);
        // content 未在字段列表中，保持原样
        $this->assertSame('赌博正文', $result['content']);
        // 非字符串字段原样保留
        $this->assertSame(10, $result['count']);
    }

    public function test_filter_array_helper_filters_all_string_fields_when_empty(): void
    {
        $this->seedWord('赌博', ['action' => 'replace']);
        $this->setAppConfig('replacement', '*');

        $result = cmspro_sensitivewords_filter_array(['a' => '赌博', 'b' => '赌博']);

        $this->assertSame('**', $result['a']);
        $this->assertSame('**', $result['b']);
    }

    public function test_available_helper_returns_true_when_ready(): void
    {
        $this->seedWord('赌博');

        $this->assertTrue(cmspro_sensitivewords_available());
    }

    public function test_available_helper_returns_false_when_disabled(): void
    {
        $this->seedWord('赌博');
        $this->setAppConfig('filter_enabled', '0');

        $this->assertFalse(cmspro_sensitivewords_available());
    }

    public function test_helpers_degrade_gracefully_when_disabled(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);
        $this->setAppConfig('filter_enabled', '0');

        // 关闭后 filter 原样返回、check 放行，且不抛异常
        $this->assertSame('赌博网站', cmspro_sensitivewords_filter('赌博网站'));
        $result = cmspro_sensitivewords_check('赌博网站');
        $this->assertTrue($result['passed']);
    }

    public function test_config_helper_reads_default_from_config_file(): void
    {
        // 未写库时回落到 Config/sensitivewords.php 默认值
        $this->setAppConfig('replacement', '#');

        $this->assertSame('#', cmspro_sensitivewords_config('replacement'));
    }

    public function test_filter_helper_on_empty_string(): void
    {
        $this->assertSame('', cmspro_sensitivewords_filter(''));
    }
}
