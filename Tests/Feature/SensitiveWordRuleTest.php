<?php

namespace App\Apps\CmsproSensitivewords\Tests\Feature;

use App\Apps\CmsproSensitivewords\Rules\SensitiveWordRule;
use App\Apps\CmsproSensitivewords\Tests\SensitivewordsTestCase;
use Illuminate\Support\Facades\Validator;

/**
 * 敏感词验证规则测试（其他应用接入的主要方式之一）。
 *
 * 覆盖两种接入形态：
 * 1. 字符串规则 sensitive_word（Validator::extend 注册，零依赖）
 * 2. 对象规则 SensitiveWordRule（Laravel 11+ ValidationRule 接口）
 *
 * 并验证：命中禁止级失败、命中替换级通过、能力不可用时放行、带场景参数。
 */
class SensitiveWordRuleTest extends SensitivewordsTestCase
{
    public function test_string_rule_fails_on_block_word(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $validator = Validator::make(['content' => '我在赌博'], ['content' => 'sensitive_word']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content', $validator->errors()->toArray());
    }

    public function test_string_rule_passes_on_clean_text(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $validator = Validator::make(['content' => '正常内容'], ['content' => 'sensitive_word']);

        $this->assertTrue($validator->passes());
    }

    public function test_string_rule_passes_on_replace_word(): void
    {
        // 替换级词条不阻断表单提交（只替换不拦截）
        $this->seedWord('傻瓜', ['action' => 'replace']);

        $validator = Validator::make(['content' => '你是傻瓜'], ['content' => 'sensitive_word']);

        $this->assertTrue($validator->passes());
    }

    public function test_string_rule_accepts_scene_parameters(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $validator = Validator::make(
            ['content' => '赌博'],
            ['content' => 'sensitive_word:comment.create,cmspro.blog']
        );

        $this->assertTrue($validator->fails());
        // 场景参数应写入命中日志
        $this->assertDatabaseHas('app_cmspro_sensitivewords_hit_logs', [
            'scene' => 'comment.create',
            'source_app' => 'cmspro.blog',
        ]);
    }

    public function test_string_rule_passes_when_capability_unavailable(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);
        // 关闭总开关 → 能力不可用 → 规则放行（不阻断调用方业务）
        $this->setAppConfig('filter_enabled', '0');

        $validator = Validator::make(['content' => '赌博'], ['content' => 'sensitive_word']);

        $this->assertTrue($validator->passes());
    }

    public function test_string_rule_passes_empty_value(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $validator = Validator::make(['content' => ''], ['content' => 'sensitive_word']);

        $this->assertTrue($validator->passes());
    }

    public function test_object_rule_fails_on_block_word(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $validator = Validator::make(
            ['content' => '我在赌博'],
            ['content' => [new SensitiveWordRule('test.scene', 'cmspro.blog')]]
        );

        $this->assertTrue($validator->fails());
    }

    public function test_object_rule_passes_on_clean_text(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $validator = Validator::make(
            ['content' => '正常内容'],
            ['content' => [new SensitiveWordRule()]]
        );

        $this->assertTrue($validator->passes());
    }

    public function test_object_rule_passes_when_capability_unavailable(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);
        $this->setAppConfig('filter_enabled', '0');

        $validator = Validator::make(
            ['content' => '赌博'],
            ['content' => [new SensitiveWordRule()]]
        );

        $this->assertTrue($validator->passes());
    }
}
