<?php

namespace App\Apps\CmsproSensitivewords\Tests\Feature;

use App\Apps\CmsproSensitivewords\Tests\SensitivewordsTestCase;
use App\Models\ConfigGroup;
use App\Models\ConfigItem;

/**
 * 过滤设置页与设置接口测试。
 *
 * 覆盖：设置页可渲染（含 Tab 分类结构与全部配置字段）、配置读取接口、
 * 配置保存（白名单过滤、group_id 精确匹配、越权键被拒）、未登录拦截。
 *
 * 设置页视图于 v1.1.0 改为按类型 Tab 分类，本测试同时守护「Tab 化后字段不丢失」。
 */
class SettingPageTest extends SensitivewordsTestCase
{
    private const PAGE_URL = '/admin/cmspro/sensitivewords/settings';

    private const API_URL = '/admin/cmspro/sensitivewords/api/settings';

    /**
     * 准备配置组与配置项（模拟安装时 seedDefaultConfigs 的落库结果）。
     *
     * 设置接口保存配置时要求 group_code 与 manifest 一致，故先建组再建项。
     */
    private function seedConfigItems(): void
    {
        $groups = [
            'filter_settings' => ['filter_enabled', 'default_action', 'replacement', 'ignore_case', 'skip_symbols', 'full_width_to_half'],
            'log_settings' => ['log_enabled', 'log_content_length', 'log_keep_days', 'stat_hit_count'],
            'hook_settings' => ['hook_content_filter', 'hook_user_register'],
            'library_settings' => ['remote_update_enabled', 'remote_update_mode', 'library_category_id', 'library_version'],
        ];

        foreach ($groups as $groupName => $items) {
            // 组可能已由基类 seedConfigGroups() 按 manifest 创建，
            // 故用 firstOrCreate 幂等复用，避免撞 config_groups.code 唯一键
            $group = ConfigGroup::firstOrCreate(
                ['code' => 'app_cmspro_sensitivewords_' . $groupName],
                [
                    'name' => $groupName,
                    'app_id' => 'cmspro.sensitivewords',
                    'sort' => 0,
                    'status' => 1,
                ]
            );

            foreach ($items as $index => $itemName) {
                ConfigItem::firstOrCreate(
                    ['code' => 'app_cmspro_sensitivewords_' . $itemName],
                    [
                        'group_id' => $group->id,
                        'name' => $itemName,
                        'type' => 'text',
                        'value' => '',
                        'tips' => '',
                        'sort' => $index,
                        'status' => 1,
                    ]
                );
            }
        }
    }

    // ══ 页面渲染 ══════════════════════════════════════════════════

    public function test_settings_page_renders_with_tab_structure(): void
    {
        $this->actingAdmin();

        $response = $this->get(self::PAGE_URL);

        $response->assertOk();
        // Tab 分类结构（v1.1.0 改造）
        $response->assertSee('layui-tab-brief', false);
        $response->assertSee('lay-filter="settingTab"', false);
        $response->assertSee('layui-tab-title', false);
        $response->assertSee('layui-tab-content', false);
        // 四个分页标题
        $response->assertSee('过滤设置', false);
        $response->assertSee('日志设置', false);
        $response->assertSee('钩子接入', false);
        $response->assertSee('词库设置', false);
    }

    public function test_settings_page_contains_all_config_fields(): void
    {
        $this->actingAdmin();

        $response = $this->get(self::PAGE_URL);

        $response->assertOk();

        // Tab 化不得丢字段：15 个可编辑配置项全部在 DOM 中
        $fields = [
            'filter_enabled', 'default_action', 'replacement', 'ignore_case', 'full_width_to_half', 'skip_symbols',
            'log_enabled', 'log_content_length', 'log_keep_days', 'stat_hit_count',
            'hook_content_filter', 'hook_user_register',
            'remote_update_enabled', 'remote_update_mode', 'library_category_id',
        ];

        foreach ($fields as $field) {
            $response->assertSee('name="' . $field . '"', false);
        }
    }

    public function test_settings_page_requires_login(): void
    {
        // 未登录访问设置页应被认证中间件拦截（302 跳登录页）
        $response = $this->get(self::PAGE_URL);

        $response->assertStatus(302);
    }

    // ══ 配置读取 / 保存 ══════════════════════════════════════════════

    public function test_get_config_returns_values_and_runtime(): void
    {
        $this->actingAdmin();
        $this->seedConfigItems();
        ConfigItem::where('code', 'app_cmspro_sensitivewords_replacement')->update(['value' => '#']);

        $response = $this->getJson(self::API_URL);

        $response->assertOk();
        $response->assertJsonPath('code', 0);
        $response->assertJsonPath('data.config.replacement', '#');
        $response->assertJsonStructure(['data' => ['config', 'runtime' => ['ready', 'enabled', 'word_total']]]);
    }

    public function test_update_config_persists_values(): void
    {
        $this->actingAdmin();
        $this->seedConfigItems();

        $response = $this->postJson(self::API_URL, [
            'filter_enabled' => '0',
            'default_action' => 'block',
            'replacement' => '#',
            'log_content_length' => 500,
            'remote_update_mode' => 'replace',
        ]);

        $response->assertOk();
        $response->assertJsonPath('code', 0);

        $this->assertSame('0', ConfigItem::where('code', 'app_cmspro_sensitivewords_filter_enabled')->value('value'));
        $this->assertSame('block', ConfigItem::where('code', 'app_cmspro_sensitivewords_default_action')->value('value'));
        $this->assertSame('#', ConfigItem::where('code', 'app_cmspro_sensitivewords_replacement')->value('value'));
        $this->assertSame('500', ConfigItem::where('code', 'app_cmspro_sensitivewords_log_content_length')->value('value'));
        $this->assertSame('replace', ConfigItem::where('code', 'app_cmspro_sensitivewords_remote_update_mode')->value('value'));
    }

    public function test_update_config_normalizes_empty_replacement(): void
    {
        $this->actingAdmin();
        $this->seedConfigItems();

        // 掩码为空时应回落到 *，否则过滤后等于没替换
        $this->postJson(self::API_URL, ['replacement' => '']);

        $this->assertSame('*', ConfigItem::where('code', 'app_cmspro_sensitivewords_replacement')->value('value'));
    }

    public function test_update_config_rejects_invalid_action(): void
    {
        $this->actingAdmin();
        $this->seedConfigItems();

        $response = $this->postJson(self::API_URL, ['default_action' => 'invalid_action']);

        $response->assertStatus(422);
    }

    public function test_update_config_ignores_non_whitelisted_keys(): void
    {
        $this->actingAdmin();
        $this->seedConfigItems();

        // library_version 由系统维护，不在允许保存的白名单内；越权键应被静默忽略
        $original = ConfigItem::where('code', 'app_cmspro_sensitivewords_library_version')->value('value');

        $this->postJson(self::API_URL, [
            'replacement' => '%',
            'library_version' => '99999999',
        ]);

        $this->assertSame('%', ConfigItem::where('code', 'app_cmspro_sensitivewords_replacement')->value('value'));
        $this->assertSame($original, ConfigItem::where('code', 'app_cmspro_sensitivewords_library_version')->value('value'));
    }

    public function test_rebuild_dictionary_endpoint(): void
    {
        $this->actingAdmin();
        $this->seedWord('赌博', ['action' => 'block']);

        $response = $this->postJson('/admin/cmspro/sensitivewords/api/settings/rebuild-dictionary');

        $response->assertOk();
        $response->assertJsonPath('code', 0);
        $response->assertJsonPath('data.word_total', 1);
    }
}
