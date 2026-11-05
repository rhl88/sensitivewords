<?php

namespace App\Apps\CmsproSensitivewords;

use App\Apps\CmsproSensitivewords\Models\SensitiveCategory;
use App\Apps\CmsproSensitivewords\Services\SensitiveLibraryService;
use App\Models\ConfigGroup;
use App\Models\ConfigItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 敏感词过滤应用 · 安装 / 卸载 / 升级脚本
 *
 * ┌ install()   安装：迁移兜底 → 配置三向同步 → 内置分类 → 内置基础词库 → 清缓存
 * ├ upgrade()   升级：迁移兜底 → 配置三向同步 → 补齐内置分类 → 清理超期日志 → 清缓存
 * └ uninstall() 卸载：回滚迁移（删表）→ 清理配置 → 清理运行时目录 → 清缓存
 *
 * 关键规范（开发文档第十二章）：
 * - uninstall() 禁止 Schema::dropIfExists 手动删表，统一走迁移 down()
 * - 迁移兜底必须写入 migrations 表记录，防止 artisan migrate 重复执行
 * - 配置同步以 manifest.json 为唯一基准做三向同步，已有项保留用户设置的 value
 * - 所有动作幂等，重复执行结果一致
 */
class Install
{
    /** 应用 ID（与 manifest.json 的 id 一致） */
    private const APP_ID = 'cmspro.sensitivewords';

    /** 应用 ID 的下划线形式（配置 code 前缀用） */
    private const APP_ID_NORMALIZED = 'cmspro_sensitivewords';

    /** 日志前缀（便于在 laravel.log 中检索本应用安装过程） */
    private const LOG_TAG = '[cmspro.sensitivewords][install] ';

    /** 运行时目录（相对 storage/，与 manifest runtime_files 声明一致） */
    private const RUNTIME_DIRS = [
        'app_cmspro_sensitivewords/wordpack',
        'app_cmspro_sensitivewords/dictionary',
        'app_cmspro_sensitivewords/temp',
    ];

    /** 系统内置分类（安装时创建，禁止后台删除） */
    private const SYSTEM_CATEGORIES = [
        ['code' => 'politics', 'name' => '涉政违规', 'default_action' => 'block', 'description' => '涉及政治敏感、违法违规的词条', 'sort' => 10],
        ['code' => 'porn', 'name' => '色情低俗', 'default_action' => 'block', 'description' => '涉黄、低俗、性暗示类词条', 'sort' => 20],
        ['code' => 'abuse', 'name' => '辱骂攻击', 'default_action' => 'replace', 'description' => '人身攻击、辱骂、歧视类词条', 'sort' => 30],
        ['code' => 'ad', 'name' => '广告推广', 'default_action' => 'replace', 'description' => '垃圾广告、导流、推广类词条', 'sort' => 40],
        ['code' => 'fraud', 'name' => '诈骗违禁', 'default_action' => 'block', 'description' => '诈骗、赌博、违禁品交易类词条', 'sort' => 50],
        ['code' => 'builtin', 'name' => '内置基础词库', 'default_action' => 'replace', 'description' => '系统内置分类，用于归类内置基础词库词条', 'sort' => 60],
        ['code' => 'official', 'name' => '官方词库', 'default_action' => 'replace', 'description' => '系统内置分类，用于归类在线同步的官方词库词条', 'sort' => 70],
        ['code' => 'imported', 'name' => '文件导入', 'default_action' => '', 'description' => '系统内置分类，用于归类文件导入的词条', 'sort' => 80],
    ];

    // ══════════════════════════════════════════════════════════════
    // 一、生命周期入口
    // ══════════════════════════════════════════════════════════════

    public function install(): void
    {
        $this->runMigrations();
        $this->createRuntimeDirs();
        $this->seedDefaultConfigs();
        $this->seedSystemCategories();
        $this->seedBuiltinWords();
        $this->clearAppCache();

        Log::info(self::LOG_TAG . '安装完成');
    }

    public function uninstall(): void
    {
        $this->rollbackMigrations();
        $this->removeConfigs();
        $this->removeRuntimeDirs();
        $this->clearAppCache();

        Log::info(self::LOG_TAG . '卸载完成');
    }

    public function upgrade(string $fromVersion, string $toVersion): void
    {
        $this->runMigrations();
        $this->createRuntimeDirs();
        $this->seedDefaultConfigs();
        $this->seedSystemCategories();
        // 升级时增量补齐内置词库：文件指纹变化（词库扩充）才导入，
        // skip 模式保留管理员对既有词条的手工调整
        $this->seedBuiltinWords();
        $this->cleanExpiredLogs();
        $this->clearAppCache();

        Log::info(self::LOG_TAG . "升级完成：{$fromVersion} → {$toVersion}");
    }

    // ══════════════════════════════════════════════════════════════
    // 二、数据库迁移（兜底机制）
    // ══════════════════════════════════════════════════════════════

    /**
     * 执行本应用迁移文件。
     *
     * 兜底原因：系统安装流程依赖路径解析执行应用迁移，Windows 等环境下
     * 可能因路径分隔符问题未执行。这里手工 require 迁移文件并调用 up()，
     * 执行成功后写入 migrations 表记录，防止 artisan migrate 重复执行。
     */
    protected function runMigrations(): void
    {
        $files = glob(__DIR__ . '/Migrations/*.php') ?: [];
        sort($files); // 按文件名（时间戳）升序，保证分类表先于词条表建立

        foreach ($files as $file) {
            $migrationName = pathinfo($file, PATHINFO_FILENAME);

            if (DB::table('migrations')->where('migration', $migrationName)->exists()) {
                continue;
            }

            $migration = require $file;
            if (! is_object($migration) || ! method_exists($migration, 'up')) {
                continue;
            }

            try {
                $migration->up();

                DB::table('migrations')->insert([
                    'migration' => $migrationName,
                    'batch' => (int) DB::table('migrations')->max('batch') + 1,
                ]);
            } catch (Throwable $e) {
                Log::error(self::LOG_TAG . '迁移执行失败', [
                    'file' => basename($file),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 回滚本应用迁移文件（逆序，先删从表再删主表）。
     */
    protected function rollbackMigrations(): void
    {
        $files = glob(__DIR__ . '/Migrations/*.php') ?: [];
        sort($files);

        foreach (array_reverse($files) as $file) {
            $migrationName = pathinfo($file, PATHINFO_FILENAME);

            if (! DB::table('migrations')->where('migration', $migrationName)->exists()) {
                continue;
            }

            $migration = require $file;
            if (! is_object($migration) || ! method_exists($migration, 'down')) {
                continue;
            }

            try {
                $migration->down();

                DB::table('migrations')->where('migration', $migrationName)->delete();
            } catch (Throwable $e) {
                Log::error(self::LOG_TAG . '迁移回滚失败', [
                    'file' => basename($file),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ══════════════════════════════════════════════════════════════
    // 三、配置项三向同步
    // ══════════════════════════════════════════════════════════════

    /**
     * 以 manifest.json 为基准同步配置组与配置项。
     *
     * - manifest 有 + DB 无 → 新增（写入 manifest 的 value 默认值）
     * - manifest 有 + DB 有 → 仅更新结构（name/type/tips/sort/status），保留用户设置的 value
     * - manifest 无 + DB 有 → 删除（清理历史残留，防止设置页出现幽灵字段）
     */
    protected function seedDefaultConfigs(): void
    {
        $manifest = $this->readManifest();
        if ($manifest === null) {
            return;
        }

        $manifestCodes = [];

        foreach (($manifest['config_groups'] ?? []) as $groupIndex => $groupDef) {
            $group = ConfigGroup::updateOrCreate(
                ['code' => 'app_' . self::APP_ID_NORMALIZED . '_' . $groupDef['name']],
                [
                    'name' => (string) $groupDef['title'],
                    'app_id' => self::APP_ID,
                    'sort' => (int) $groupIndex,
                    'status' => 1,
                ]
            );

            foreach (($groupDef['items'] ?? []) as $index => $item) {
                $code = 'app_' . self::APP_ID_NORMALIZED . '_' . $item['name'];
                $manifestCodes[] = $code;

                $existing = ConfigItem::where('code', $code)->first();

                if ($existing !== null) {
                    // 已存在：只同步结构，绝不覆盖用户在设置页保存的 value
                    $existing->update([
                        'group_id' => $group->id,
                        'name' => (string) $item['title'],
                        'type' => (string) ($item['type'] ?? 'text'),
                        'tips' => (string) ($item['tips'] ?? ''),
                        'sort' => $index,
                        'status' => 1,
                    ]);

                    continue;
                }

                ConfigItem::create([
                    'code' => $code,
                    'group_id' => $group->id,
                    'name' => (string) $item['title'],
                    'type' => (string) ($item['type'] ?? 'text'),
                    'value' => (string) ($item['value'] ?? ''),
                    'tips' => (string) ($item['tips'] ?? ''),
                    'sort' => $index,
                    'status' => 1,
                ]);
            }
        }

        // 清理 manifest 中已删除的配置项（library_version 在 manifest 中声明，故不会被误删）
        ConfigItem::where('code', 'like', 'app_' . self::APP_ID_NORMALIZED . '_%')
            ->whereNotIn('code', $manifestCodes)
            ->delete();

        ConfigGroup::where('app_id', self::APP_ID)
            ->whereDoesntHave('items')
            ->delete();
    }

    /**
     * 卸载时全量清理本应用配置（先删项再删组，并兜底按 code 前缀清理）。
     */
    protected function removeConfigs(): void
    {
        try {
            $groupIds = ConfigGroup::where('app_id', self::APP_ID)->pluck('id')->all();

            if ($groupIds !== []) {
                ConfigItem::whereIn('group_id', $groupIds)->delete();
            }

            ConfigItem::where('code', 'like', 'app_' . self::APP_ID_NORMALIZED . '_%')->delete();
            ConfigGroup::where('app_id', self::APP_ID)->delete();
        } catch (Throwable $e) {
            Log::warning(self::LOG_TAG . '清理配置失败', ['error' => $e->getMessage()]);
        }
    }

    // ══════════════════════════════════════════════════════════════
    // 四、内置分类与基础词库
    // ══════════════════════════════════════════════════════════════

    /**
     * 创建系统内置分类（幂等：按 code 判断，已存在只补齐名称与说明）。
     */
    protected function seedSystemCategories(): void
    {
        try {
            foreach (self::SYSTEM_CATEGORIES as $category) {
                $existing = SensitiveCategory::where('code', $category['code'])->first();

                if ($existing !== null) {
                    $existing->update([
                        'name' => $category['name'],
                        'description' => $category['description'],
                        'sort' => $category['sort'],
                        'is_system' => 1,
                    ]);

                    continue;
                }

                SensitiveCategory::create([
                    'name' => $category['name'],
                    'code' => $category['code'],
                    'default_action' => $category['default_action'],
                    'description' => $category['description'],
                    'sort' => $category['sort'],
                    'is_system' => 1,
                    'status' => 1,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning(self::LOG_TAG . '内置分类创建失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 导入内置基础词库（幂等：已有 builtin 来源词条时跳过）。
     */
    protected function seedBuiltinWords(): void
    {
        try {
            $result = app(SensitiveLibraryService::class)->importBuiltinWords();

            if (($result['inserted'] ?? 0) > 0) {
                Log::info(self::LOG_TAG . '内置基础词库导入完成', ['inserted' => $result['inserted']]);
            }
        } catch (Throwable $e) {
            Log::warning(self::LOG_TAG . '内置基础词库导入失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 升级时清理超期命中日志（按 log_keep_days 配置）。
     */
    protected function cleanExpiredLogs(): void
    {
        try {
            app(\App\Apps\CmsproSensitivewords\Services\SensitiveWordService::class)->cleanExpiredLogs();
        } catch (Throwable $e) {
            // 日志清理失败不影响升级
        }
    }

    // ══════════════════════════════════════════════════════════════
    // 五、运行时目录与缓存
    // ══════════════════════════════════════════════════════════════

    /**
     * 创建运行时目录（storage 下，属 manifest runtime_files 声明范围）。
     *
     * 注意：禁止创建 public/apps/{appId}/ 下的目录，否则框架的
     * createAssetSymlink() 检测到目标已存在会跳过资源发布。
     */
    protected function createRuntimeDirs(): void
    {
        foreach (self::RUNTIME_DIRS as $dir) {
            $path = storage_path($dir);
            if (! File::isDirectory($path)) {
                try {
                    File::makeDirectory($path, 0755, true);
                } catch (Throwable $e) {
                    Log::warning(self::LOG_TAG . '运行时目录创建失败', [
                        'dir' => $dir,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * 清理运行时目录（卸载时调用，词库包与词典缓存均属可重建产物）。
     */
    protected function removeRuntimeDirs(): void
    {
        $baseDir = storage_path('app_cmspro_sensitivewords');

        if (File::isDirectory($baseDir)) {
            try {
                File::deleteDirectory($baseDir);
            } catch (Throwable $e) {
                Log::warning(self::LOG_TAG . '运行时目录清理失败', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * 清理本应用运行时缓存（配置缓存 + 词典编译缓存）。
     */
    protected function clearAppCache(): void
    {
        try {
            if (function_exists('cmspro_sensitivewords_forget_config_cache')) {
                cmspro_sensitivewords_forget_config_cache();
            }

            app(\App\Apps\CmsproSensitivewords\Services\SensitiveWordService::class)->rebuildDictionary();
        } catch (Throwable $e) {
            Log::warning(self::LOG_TAG . '清理缓存失败', ['error' => $e->getMessage()]);
        }
    }

    // ══════════════════════════════════════════════════════════════
    // 六、内部工具
    // ══════════════════════════════════════════════════════════════

    /**
     * 读取并解析 manifest.json。
     *
     * @return array<string,mixed>|null
     */
    protected function readManifest(): ?array
    {
        $path = __DIR__ . '/manifest.json';

        if (! file_exists($path)) {
            Log::error(self::LOG_TAG . 'manifest.json 不存在', ['path' => $path]);

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            Log::error(self::LOG_TAG . 'manifest.json 解析失败', ['path' => $path]);

            return null;
        }

        return $decoded;
    }
}
