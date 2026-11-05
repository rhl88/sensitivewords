<?php

namespace App\Apps\CmsproSensitivewords\Tests\Feature;

use App\Apps\CmsproSensitivewords\Models\SensitiveHitLog;
use App\Apps\CmsproSensitivewords\Models\SensitiveWord;
use App\Apps\CmsproSensitivewords\Services\SensitiveWordService;
use App\Apps\CmsproSensitivewords\Tests\SensitivewordsTestCase;
use App\Services\HookManager;

/**
 * 核心过滤服务测试。
 *
 * 覆盖：基础检测/替换、动作三级决策（词条 → 分类 → 全局）、命中日志与计数、
 * 白名单钩子、前后置过滤钩子、总开关降级、试检测无副作用、日期格式规范。
 */
class FilterServiceTest extends SensitivewordsTestCase
{
    public function test_check_returns_passed_when_no_hit(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $result = $this->filterService()->check('今天天气不错');

        $this->assertTrue($result['passed']);
        $this->assertSame(0, $result['hit_count']);
        $this->assertSame('none', $result['action']);
    }

    public function test_check_blocks_when_word_action_is_block(): void
    {
        $this->seedWord('赌博', ['action' => 'block', 'level' => 3]);

        $result = $this->filterService()->check('我在赌博网站下注');

        $this->assertFalse($result['passed']);
        $this->assertSame('block', $result['action']);
        $this->assertSame(1, $result['hit_count']);
        $this->assertSame('赌博', $result['hits'][0]['word']);
        $this->assertStringContainsString('赌博', $result['message']);
    }

    public function test_filter_replaces_hit_word_with_mask(): void
    {
        $this->seedWord('赌博', ['action' => 'replace']);
        $this->setAppConfig('replacement', '*');

        $filtered = $this->filterService()->filter('我在赌博网站下注');

        $this->assertSame('我在**网站下注', $filtered);
    }

    public function test_filter_uses_custom_replacement_char(): void
    {
        $this->seedWord('赌博', ['action' => 'replace']);
        $this->setAppConfig('replacement', '#');

        $this->assertSame('我在##网站', $this->filterService()->filter('我在赌博网站'));
    }

    public function test_action_falls_back_to_category_default(): void
    {
        // 词条不设 action，分类默认 block → 命中即拦截
        $this->seedWord('六合彩', ['category_id' => $this->categoryId('fraud'), 'action' => '']);

        $result = $this->filterService()->check('买六合彩');

        $this->assertFalse($result['passed']);
        $this->assertSame('block', $result['action']);
    }

    public function test_action_falls_back_to_global_default(): void
    {
        // 词条与分类（official 分类 default_action 为空）都不设 → 走全局配置
        $this->seedWord('测试词', ['category_id' => $this->categoryId('official'), 'action' => '']);
        $this->setAppConfig('default_action', 'warn');

        $result = $this->filterService()->check('这是测试词');

        // warn 动作放行但记录，passed=true
        $this->assertTrue($result['passed']);
        $this->assertSame('warn', $result['action']);
    }

    public function test_word_action_overrides_category_default(): void
    {
        // 分类默认 block，但词条显式设为 replace → 以词条为准
        $this->seedWord('代开发票', ['category_id' => $this->categoryId('fraud'), 'action' => 'replace']);

        $result = $this->filterService()->check('代开发票');

        $this->assertTrue($result['passed']);
        $this->assertSame('replace', $result['action']);
    }

    public function test_warn_action_keeps_original_text_but_logs(): void
    {
        $this->seedWord('敏感提醒词', ['action' => 'warn']);

        $text = '含敏感提醒词的句子';
        $filtered = $this->filterService()->filter($text);

        // warn 不替换原文
        $this->assertSame($text, $filtered);
        // 但写入了命中日志
        $this->assertDatabaseHas('app_cmspro_sensitivewords_hit_logs', [
            'words' => '敏感提醒词',
            'action' => 'warn',
        ]);
    }

    public function test_check_writes_hit_log_with_context(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $this->filterService()->check('赌博网站', 'comment.create', 'cmspro.blog');

        $log = SensitiveHitLog::latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('comment.create', $log->scene);
        $this->assertSame('cmspro.blog', $log->source_app);
        $this->assertSame('block', $log->action);
        $this->assertSame(1, $log->word_count);
        // 日期格式规范：Y-m-d H:i:s，禁止 ISO 8601
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $log->create_time->format('Y-m-d H:i:s'));
    }

    public function test_hit_log_can_be_disabled_by_config(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);
        $this->setAppConfig('log_enabled', '0');

        $this->filterService()->check('赌博网站', 'test.scene');

        $this->assertSame(0, SensitiveHitLog::count());
    }

    public function test_content_snapshot_truncated_by_config(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);
        $this->setAppConfig('log_content_length', '10');

        $longText = str_repeat('前缀', 20) . '赌博' . str_repeat('后缀', 20);
        $this->filterService()->check($longText, 'test.scene');

        $log = SensitiveHitLog::latest('id')->first();
        // 快照被截断（10 字 + 省略号）
        $this->assertLessThanOrEqual(13, mb_strlen((string) $log->content));
    }

    public function test_hit_count_accumulates_when_stat_enabled(): void
    {
        $word = $this->seedWord('赌博', ['action' => 'replace']);
        $this->setAppConfig('stat_hit_count', '1');

        $this->filterService()->check('赌博一', 'test');
        $this->filterService()->check('赌博二', 'test');

        $this->assertSame(2, (int) $word->fresh()->hit_count);
        $this->assertNotNull($word->fresh()->last_hit_time);
    }

    public function test_hit_count_not_accumulated_when_stat_disabled(): void
    {
        $word = $this->seedWord('赌博', ['action' => 'replace']);
        $this->setAppConfig('stat_hit_count', '0');

        $this->filterService()->check('赌博', 'test');

        $this->assertSame(0, (int) $word->fresh()->hit_count);
    }

    public function test_validate_text_returns_message_on_block(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $error = $this->filterService()->validateText('赌博网站');
        $this->assertNotNull($error);
        $this->assertStringContainsString('赌博', $error);

        // 未命中时返回 null
        $this->assertNull($this->filterService()->validateText('正常内容'));
    }

    public function test_filter_disabled_by_master_switch_returns_original(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);
        $this->setAppConfig('filter_enabled', '0');

        $service = $this->filterService();
        $this->assertSame('赌博网站', $service->filter('赌博网站'));
        $this->assertTrue($service->check('赌博网站')['passed']);
    }

    public function test_whitelist_hook_exempts_word(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        // 其他应用注册白名单 Filter，放行「赌博」
        app(HookManager::class)->registerFilter(
            'cmspro.sensitivewords.whitelist',
            fn ($whitelist) => array_merge($whitelist, ['赌博']),
            20,
            'cmspro.other'
        );

        $result = $this->filterService()->check('赌博网站');

        $this->assertTrue($result['passed']);
        $this->assertSame(0, $result['hit_count']);
    }

    public function test_before_and_after_filter_hooks_are_applied(): void
    {
        $this->seedWord('赌博', ['action' => 'replace']);
        $hooks = app(HookManager::class);

        // 前置钩子：把「du博」也当作待过滤（此处仅演示钩子被调用）
        $hooks->registerFilter(
            'cmspro.sensitivewords.before_filter',
            fn ($text) => str_replace('DU博', '赌博', $text),
            20,
            'cmspro.other'
        );
        // 后置钩子：过滤后追加标记
        $hooks->registerFilter(
            'cmspro.sensitivewords.after_filter',
            fn ($filtered) => $filtered . '[已过滤]',
            20,
            'cmspro.other'
        );

        $result = $this->filterService()->filter('DU博网站');

        $this->assertSame('**网站[已过滤]', $result);
    }

    public function test_inspect_has_no_side_effects(): void
    {
        $word = $this->seedWord('赌博', ['action' => 'replace']);

        $result = $this->filterService()->inspect('赌博网站');

        $this->assertSame(1, $result['hit_count']);
        $this->assertSame('**网站', $result['filtered']);
        // 试检测不写日志、不累加计数
        $this->assertSame(0, SensitiveHitLog::count());
        $this->assertSame(0, (int) $word->fresh()->hit_count);
    }

    public function test_disabled_word_is_not_matched(): void
    {
        $this->seedWord('赌博', ['action' => 'block', 'status' => 0]);

        $this->assertTrue($this->filterService()->check('赌博网站')['passed']);
    }

    public function test_multiple_hits_all_replaced(): void
    {
        $this->seedWord('赌博', ['action' => 'replace']);
        $this->seedWord('色情', ['action' => 'replace']);

        $filtered = $this->filterService()->filter('赌博与色情');

        $this->assertSame('**与**', $filtered);
    }

    public function test_empty_text_short_circuits(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $this->assertSame('', $this->filterService()->filter(''));
        $this->assertTrue($this->filterService()->check('')['passed']);
    }
}
