<?php

namespace App\Apps\CmsproSensitivewords\Controllers\Admin;

use App\Apps\CmsproSensitivewords\Services\SensitiveWordManageService;
use App\Apps\CmsproSensitivewords\Services\SensitiveWordService;
use App\Http\Responses\ApiResponse;
use App\Models\ConfigGroup;
use App\Models\ConfigItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * 后台 · 过滤设置控制器
 *
 * 读写 manifest 声明的四组配置（过滤设置 / 日志设置 / 钩子接入 / 词库设置）。
 *
 * ⚠️ group_code 一致性（开发文档第十四章第 9 条踩坑记录）：写库时以
 * code + group_id 双条件匹配，group_id 取 manifest 声明的
 * app_cmspro_sensitivewords_{group_name} 对应的配置组，避免出现
 * 「manifest 标准组 + 应用自创组」两套并存导致升级时唯一键冲突。
 */
class SettingController extends BaseController
{
    /** 配置项 code 前缀 */
    private const CONFIG_PREFIX = 'app_cmspro_sensitivewords_';

    /**
     * 配置项 → 所属配置组 name 映射（必须与 manifest.json 的 config_groups 一致）。
     *
     * @var array<string,string>
     */
    private const ITEM_GROUPS = [
        // filter_settings 组
        'filter_enabled' => 'filter_settings',
        'default_action' => 'filter_settings',
        'replacement' => 'filter_settings',
        'ignore_case' => 'filter_settings',
        'skip_symbols' => 'filter_settings',
        'full_width_to_half' => 'filter_settings',
        // log_settings 组
        'log_enabled' => 'log_settings',
        'log_content_length' => 'log_settings',
        'log_keep_days' => 'log_settings',
        'stat_hit_count' => 'log_settings',
        // hook_settings 组
        'hook_content_filter' => 'hook_settings',
        'hook_user_register' => 'hook_settings',
        // library_settings 组（library_version 由系统维护，只读不可改）
        'remote_update_enabled' => 'library_settings',
        'remote_update_mode' => 'library_settings',
        'library_category_id' => 'library_settings',
    ];

    /** 允许保存的配置项白名单（library_version 等系统维护项不在其中） */
    private const ALLOWED_KEYS = [
        'filter_enabled', 'default_action', 'replacement', 'ignore_case',
        'skip_symbols', 'full_width_to_half',
        'log_enabled', 'log_content_length', 'log_keep_days', 'stat_hit_count',
        'hook_content_filter', 'hook_user_register',
        'remote_update_enabled', 'remote_update_mode', 'library_category_id',
    ];

    public function __construct(
        protected SensitiveWordService $filter,
        protected SensitiveWordManageService $manage
    ) {
    }

    /**
     * 设置页。
     */
    public function index()
    {
        return view('cmspro.sensitivewords::Admin.settings.index', [
            'stats' => $this->manage->logStats(),
        ]);
    }

    /**
     * 读取当前配置（含只读的 library_version 与运行态统计）。
     */
    public function getConfig(): JsonResponse
    {
        if (! $this->hasPermission('manage')) {
            return $this->forbidden('查看过滤设置');
        }

        $keys = array_merge(self::ALLOWED_KEYS, ['library_version']);
        $config = [];

        try {
            foreach ($keys as $key) {
                $value = ConfigItem::where('code', self::CONFIG_PREFIX . $key)->value('value');
                $config[$key] = $value === null ? '' : (string) $value;
            }
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '配置读取失败：' . $e->getMessage()));
        }

        return response()->json(ApiResponse::success([
            'config' => $config,
            'runtime' => [
                'ready' => $this->filter->isReady(),
                'enabled' => $this->filter->isEnabled(),
                'word_total' => $this->filter->wordTotal(),
            ],
        ]));
    }

    /**
     * 保存配置（白名单校验 + group_id 精确匹配 + 保存后清缓存）。
     */
    public function updateConfig(Request $request): JsonResponse
    {
        if (! $this->hasPermission('setting.edit')) {
            return $this->forbidden('修改过滤设置');
        }

        $validated = $request->validate([
            'filter_enabled' => 'nullable|string|in:0,1',
            'default_action' => 'nullable|string|in:block,replace,warn',
            'replacement' => 'nullable|string|max:5',
            'ignore_case' => 'nullable|string|in:0,1',
            'skip_symbols' => 'nullable|string|in:0,1',
            'full_width_to_half' => 'nullable|string|in:0,1',
            'log_enabled' => 'nullable|string|in:0,1',
            'log_content_length' => 'nullable|integer|min:0|max:2000',
            'log_keep_days' => 'nullable|integer|min:0|max:3650',
            'stat_hit_count' => 'nullable|string|in:0,1',
            'hook_content_filter' => 'nullable|string|in:0,1',
            'hook_user_register' => 'nullable|string|in:0,1',
            'remote_update_enabled' => 'nullable|string|in:0,1',
            'remote_update_mode' => 'nullable|string|in:merge,replace',
            'library_category_id' => 'nullable|integer|min:0',
        ], [], [
            'replacement' => '替换掩码字符',
            'log_content_length' => '原文快照长度',
            'log_keep_days' => '日志保留天数',
        ]);

        // 只保存白名单内的键，防止越权写入其他配置项
        $payload = array_intersect_key($validated, array_flip(self::ALLOWED_KEYS));
        if ($payload === []) {
            return response()->json(ApiResponse::error(40001, '没有需要保存的配置项'));
        }

        // 替换掩码为空时回落到 *，否则过滤后会变成「什么都没替换」
        if (array_key_exists('replacement', $payload) && trim((string) $payload['replacement']) === '') {
            $payload['replacement'] = '*';
        }

        try {
            $groupIds = ConfigGroup::where('app_id', 'cmspro.sensitivewords')
                ->pluck('id', 'code')
                ->all();

            // manifest 中各配置项的结构定义（title/type/tips/sort），新建配置项时回填
            $manifestItems = $this->manifestItemDefinitions();

            foreach ($payload as $key => $value) {
                $code = self::CONFIG_PREFIX . $key;
                $groupCode = self::CONFIG_PREFIX . (self::ITEM_GROUPS[$key] ?? 'filter_settings');
                $groupId = (int) ($groupIds[$groupCode] ?? 0);

                if ($groupId <= 0) {
                    // 配置组尚未注册（应用未走完安装流程）：跳过写库，避免产生无主配置项
                    continue;
                }

                // code + group_id 双条件匹配，杜绝把记录改挂到其他组造成脏数据
                $item = ConfigItem::where('code', $code)->where('group_id', $groupId)->first();

                if ($item !== null) {
                    $item->update(['value' => (string) $value]);
                    continue;
                }

                // 清理历史残留在其他组下的同 code 记录后再新建
                ConfigItem::where('code', $code)->where('group_id', '!=', $groupId)->delete();

                $definition = $manifestItems[$key] ?? [];
                ConfigItem::create([
                    'code' => $code,
                    'group_id' => $groupId,
                    'name' => (string) ($definition['title'] ?? $code),
                    'type' => (string) ($definition['type'] ?? 'text'),
                    'value' => (string) $value,
                    'tips' => (string) ($definition['tips'] ?? ''),
                    'sort' => (int) ($definition['sort'] ?? 90),
                    'status' => 1,
                ]);
            }

            // 配置变更后立即清缓存，保证本次请求之后的过滤行为按新配置执行；
            // 匹配策略（大小写/全角/干扰符）会影响词典归一化，需重建词典
            cmspro_sensitivewords_forget_config_cache();
            $this->filter->rebuildDictionary();

            return response()->json(ApiResponse::success(null, '配置保存成功'));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '配置保存失败：' . $e->getMessage()));
        }
    }

    /**
     * 重建词典缓存（词库异常或手工改库后使用）。
     */
    public function rebuildDictionary(): JsonResponse
    {
        if (! $this->hasPermission('setting.edit')) {
            return $this->forbidden('重建词典缓存');
        }

        try {
            $this->filter->rebuildDictionary();
            $total = $this->filter->wordTotal();

            return response()->json(ApiResponse::success(
                ['word_total' => $total],
                '词典缓存已重建，当前生效词条 ' . $total . ' 条'
            ));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '重建失败：' . $e->getMessage()));
        }
    }

    /**
     * 从 manifest.json 读取各配置项的结构定义（name → [title,type,tips,sort]）。
     *
     * 新建配置项（DB 中缺失时）需要按 manifest 的标题与类型回填，保持与
     * 安装时 seedDefaultConfigs() 写入的结构一致。manifest 不可读时返回空数组，
     * 调用方已对每个字段做了 ?? 兜底。
     *
     * @return array<string,array<string,mixed>>
     */
    protected function manifestItemDefinitions(): array
    {
        try {
            $path = base_path('app/Apps/CmsproSensitivewords/manifest.json');
            if (! is_file($path)) {
                return [];
            }

            $manifest = json_decode((string) file_get_contents($path), true);
            $definitions = [];

            foreach (($manifest['config_groups'] ?? []) as $group) {
                foreach (($group['items'] ?? []) as $index => $item) {
                    if (! isset($item['name'])) {
                        continue;
                    }
                    $definitions[$item['name']] = [
                        'title' => $item['title'] ?? $item['name'],
                        'type' => $item['type'] ?? 'text',
                        'tips' => $item['tips'] ?? '',
                        'sort' => $index,
                    ];
                }
            }

            return $definitions;
        } catch (Throwable $e) {
            return [];
        }
    }
}
