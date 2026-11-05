<?php

namespace App\Apps\CmsproSensitivewords\Tests;

use App\Apps\CmsproSensitivewords\Models\SensitiveCategory;
use App\Apps\CmsproSensitivewords\Models\SensitiveWord;
use App\Apps\CmsproSensitivewords\Services\SensitiveWordManageService;
use App\Apps\CmsproSensitivewords\Services\SensitiveWordService;
use App\Enums\Status;
use App\Models\AdminRole;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 敏感词过滤应用 · 测试基类
 *
 * 设计说明：
 * 1. 应用迁移已做 SQLite 兼容（表备注语句用驱动判断包裹），故直接 require
 *    迁移文件建表，与 Install::runMigrations() 的兜底逻辑一致，最贴近真实安装
 * 2. 不调用完整 Install::install()：其 seedDefaultConfigs() 依赖系统 config 表，
 *    而测试聚焦过滤能力，配置读取走 Config/sensitivewords.php 默认值即可
 * 3. ServiceProvider 仅对已安装应用注册，测试环境手动注册以加载路由、视图与验证规则
 * 4. 词典编译缓存写入 storage/app_cmspro_sensitivewords/，tearDown 统一清理
 */
abstract class SensitivewordsTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAppTables();
        $this->seedSystemCategories();
        $this->seedConfigGroups();
        $this->registerAppProvider();
    }

    protected function tearDown(): void
    {
        // 清理测试期间写入的词典编译缓存与词库包目录，避免污染真实 storage
        $dir = storage_path('app_cmspro_sensitivewords');
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    /**
     * 按 manifest 声明创建配置组（模拟真实安装流程的 seedDefaultConfigs）。
     *
     * 必须建组：应用内多处写配置项时以 group_code 定位所属组
     * （如 SensitiveLibraryService::saveBuiltinHash 写 builtin_library_hash 到
     * library_settings 组），组不存在会静默跳过写入，导致指纹未落库、
     * 增量导入判定失效。真实安装时该组由 Install::seedDefaultConfigs() 创建。
     */
    protected function seedConfigGroups(): void
    {
        if (! Schema::hasTable('config_groups')) {
            return;
        }

        $manifestPath = base_path('app/Apps/CmsproSensitivewords/manifest.json');
        if (! is_file($manifestPath)) {
            return;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        foreach (($manifest['config_groups'] ?? []) as $index => $group) {
            \App\Models\ConfigGroup::firstOrCreate(
                ['code' => 'app_cmspro_sensitivewords_' . $group['name']],
                [
                    'name' => (string) $group['title'],
                    'app_id' => 'cmspro.sensitivewords',
                    'sort' => $index,
                    'status' => 1,
                ]
            );
        }
    }

    /**
     * require 应用迁移文件建表（幂等，SQLite 兼容）。
     */
    protected function createAppTables(): void
    {
        $files = glob(base_path('app/Apps/CmsproSensitivewords/Migrations/*.php')) ?: [];
        sort($files);

        foreach ($files as $file) {
            $migration = require $file;
            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }
        }
    }

    /**
     * 建立测试用的内置分类（含不同默认动作，用于验证「分类级动作」）。
     */
    protected function seedSystemCategories(): void
    {
        $categories = [
            ['code' => 'fraud', 'name' => '诈骗违禁', 'default_action' => 'block', 'sort' => 10],
            ['code' => 'ad', 'name' => '广告推广', 'default_action' => 'replace', 'sort' => 20],
            ['code' => 'abuse', 'name' => '辱骂攻击', 'default_action' => 'warn', 'sort' => 30],
            ['code' => 'official', 'name' => '官方词库', 'default_action' => '', 'sort' => 40],
        ];

        foreach ($categories as $category) {
            SensitiveCategory::firstOrCreate(
                ['code' => $category['code']],
                array_merge($category, ['is_system' => 1, 'status' => Status::ENABLED->value])
            );
        }
    }

    /**
     * 手动注册应用 ServiceProvider（加载路由 / 视图命名空间 / helpers / 验证规则）。
     */
    protected function registerAppProvider(): void
    {
        $provider = $this->app->register(\App\Apps\CmsproSensitivewords\ServiceProvider::class);
        if ($provider && method_exists($provider, 'boot')) {
            $this->app->call([$provider, 'boot']);
        }

        // 手动注册的 Provider 晚于路由名称索引重建，刷新以支持 route() 查找
        $this->app->make('router')->getRoutes()->refreshNameLookups();
    }

    /**
     * 构造并登录一个超管管理员（super_admin 角色放行全部权限）。
     */
    protected function actingAdmin(bool $login = true): AdminUser
    {
        $admin = AdminUser::create([
            'username' => 'admin_' . uniqid(),
            'password' => bcrypt('123456'),
            'name' => '测试管理员',
            'status' => 1,
        ]);

        $role = AdminRole::firstOrCreate(
            ['code' => 'super_admin'],
            [
                'name' => '超级管理员',
                'description' => '系统内置超级管理员',
                'parent_id' => 0,
                'sort' => 0,
                'status' => Status::ENABLED,
            ]
        );
        $admin->roles()->attach($role->id);

        if ($login) {
            $this->actingAs($admin, 'admin');
        }

        return $admin;
    }

    /**
     * 快捷新增词条（走管理服务，自动生成 word_lower 并重建词典）。
     *
     * @param array<string,mixed> $attrs category_id/action/level/status/remark/source
     */
    protected function seedWord(string $word, array $attrs = []): SensitiveWord
    {
        return app(SensitiveWordManageService::class)->createWord(array_merge([
            'word' => $word,
            'category_id' => 0,
            'action' => '',
            'level' => 1,
            'status' => 1,
        ], $attrs));
    }

    /**
     * 按 code 取内置分类 ID。
     */
    protected function categoryId(string $code): int
    {
        return (int) SensitiveCategory::where('code', $code)->value('id');
    }

    /**
     * 运行时设置应用配置（不依赖数据库 config 表，走 config()->set + 清缓存）。
     *
     * cmspro_sensitivewords_config() 会先查 ConfigItem（测试环境无该表则降级），
     * 再回退 config('apps.cmspro.sensitivewords.*')，故此处 set 后清缓存即可生效。
     */
    protected function setAppConfig(string $key, string $value): void
    {
        config()->set('apps.cmspro.sensitivewords.' . $key, $value);

        if (function_exists('cmspro_sensitivewords_forget_config_cache')) {
            cmspro_sensitivewords_forget_config_cache([$key]);
        }
    }

    /**
     * 取过滤服务单例。
     */
    protected function filterService(): SensitiveWordService
    {
        return app(SensitiveWordService::class);
    }
}
