<?php

/**
 * 敏感词过滤应用 · 应用级辅助函数
 *
 * 由 ServiceProvider::register() 通过 require_once 加载，全部函数以
 * function_exists 包裹保证重复加载幂等，函数名统一 cmspro_sensitivewords_ 前缀。
 *
 * 面向其他应用提供两个「零依赖入口」：
 * - cmspro_sensitivewords_filter()：替换敏感词，返回处理后的文本
 * - cmspro_sensitivewords_check() ：检测敏感词，返回命中详情
 *
 * 二者内部均先做「应用是否可用」判定（表是否存在、总开关是否开启），
 * 不可用时原样返回输入文本，因此其他应用可以无条件调用而无需先判断
 * 本应用是否安装——这正是「可自由为任意应用提供拓展」的落地方式。
 */

use App\Models\ConfigItem;
use Illuminate\Support\Facades\Cache;

if (! function_exists('cmspro_sensitivewords_config')) {
    /**
     * 读取本应用配置值（优先数据库 config_items，回退 Config/sensitivewords.php）
     *
     * 配置项 code 统一为 app_cmspro_sensitivewords_{name}。
     * 数据库不可用（安装前、迁移中）时静默降级到应用默认配置文件。
     *
     * @param string $key     配置键名（不含前缀），如 filter_enabled
     * @param mixed  $default 兜底默认值
     * @return mixed 配置值
     */
    function cmspro_sensitivewords_config(string $key, mixed $default = null): mixed
    {
        $cacheKey = 'cmspro_sensitivewords:config:' . $key;

        return Cache::remember($cacheKey, 600, function () use ($key, $default) {
            try {
                $value = ConfigItem::where('code', 'app_cmspro_sensitivewords_' . $key)->value('value');
                if ($value !== null && $value !== '') {
                    return $value;
                }
            } catch (\Throwable $e) {
                // 表不存在或数据库异常：降级到配置文件默认值
            }

            return config('apps.cmspro.sensitivewords.' . $key, $default);
        });
    }
}

if (! function_exists('cmspro_sensitivewords_forget_config_cache')) {
    /**
     * 清除本应用配置缓存
     *
     * 设置页保存配置、安装/升级/卸载后调用，确保立即读到新值。
     *
     * @param array<int,string>|null $keys 需要清理的配置键名列表，null 表示清理全部已知键
     */
    function cmspro_sensitivewords_forget_config_cache(?array $keys = null): void
    {
        $keys = $keys ?? [
            'filter_enabled', 'default_action', 'replacement', 'ignore_case',
            'skip_symbols', 'full_width_to_half',
            'log_enabled', 'log_content_length', 'log_keep_days', 'stat_hit_count',
            'hook_content_filter', 'hook_user_register',
            'remote_update_enabled', 'remote_update_mode', 'library_category_id',
            'library_version', 'builtin_library_hash',
        ];

        foreach ($keys as $key) {
            Cache::forget('cmspro_sensitivewords:config:' . $key);
        }
    }
}

if (! function_exists('cmspro_sensitivewords_available')) {
    /**
     * 判断敏感词过滤能力当前是否可用
     *
     * 可用条件：应用类存在 + 词条表存在 + 过滤总开关开启。
     * 其他应用调用前可用它做能力探测（如后台展示「敏感词过滤：已启用」）。
     * 任何异常均返回 false，绝不抛出，保证调用方主流程安全。
     */
    function cmspro_sensitivewords_available(): bool
    {
        try {
            if (! class_exists(\App\Apps\CmsproSensitivewords\Services\SensitiveWordService::class)) {
                return false;
            }

            if ((string) cmspro_sensitivewords_config('filter_enabled', '1') !== '1') {
                return false;
            }

            return app(\App\Apps\CmsproSensitivewords\Services\SensitiveWordService::class)->isReady();
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (! function_exists('cmspro_sensitivewords_filter')) {
    /**
     * 过滤文本中的敏感词（替换为掩码字符）
     *
     * 其他应用最常用的入口：一行代码即可对任意文本做敏感词替换。
     * 过滤能力不可用（应用未安装/已禁用/总开关关闭）时原样返回输入文本。
     *
     * @param string      $text    待过滤文本
     * @param string|null $scene   业务场景标识（写入命中日志，如 comment.create）
     * @param string|null $sourceApp 来源应用 ID（写入命中日志，如 cmspro.blog）
     * @return string 过滤后的文本
     */
    function cmspro_sensitivewords_filter(string $text, ?string $scene = null, ?string $sourceApp = null): string
    {
        if ($text === '' || ! cmspro_sensitivewords_available()) {
            return $text;
        }

        try {
            return app(\App\Apps\CmsproSensitivewords\Services\SensitiveWordService::class)
                ->filter($text, $scene ?? '', $sourceApp ?? '');
        } catch (\Throwable $e) {
            // 过滤失败不阻断调用方主流程，返回原文
            return $text;
        }
    }
}

if (! function_exists('cmspro_sensitivewords_check')) {
    /**
     * 检测文本是否含敏感词，返回命中详情
     *
     * 与 filter 的区别：不修改文本，只报告命中情况，供调用方自行决定处理方式。
     * 能力不可用时返回 passed=true 且 hits 为空。
     *
     * @param string      $text      待检测文本
     * @param string|null $scene     业务场景标识
     * @param string|null $sourceApp 来源应用 ID
     * @return array{
     *     passed:bool,action:string,hits:array<int,array{word:string,start:int,end:int,level:int,action:string}>,
     *     hit_count:int,message:string
     * } passed=true 表示可放行；action 为最终执行动作（block/replace/warn/none）
     */
    function cmspro_sensitivewords_check(string $text, ?string $scene = null, ?string $sourceApp = null): array
    {
        $empty = ['passed' => true, 'action' => 'none', 'hits' => [], 'hit_count' => 0, 'message' => ''];

        if ($text === '' || ! cmspro_sensitivewords_available()) {
            return $empty;
        }

        try {
            return app(\App\Apps\CmsproSensitivewords\Services\SensitiveWordService::class)
                ->check($text, $scene ?? '', $sourceApp ?? '');
        } catch (\Throwable $e) {
            return $empty;
        }
    }
}

if (! function_exists('cmspro_sensitivewords_filter_array')) {
    /**
     * 批量过滤数组中的字符串字段
     *
     * 表单提交场景常用：一次调用过滤多个字段，非字符串值原样保留。
     *
     * @param array<string,mixed> $data      待过滤数据（如 $request->validated()）
     * @param array<int,string>   $fields    需要过滤的字段名，空数组表示过滤全部字符串字段
     * @param string|null         $scene     业务场景标识
     * @param string|null         $sourceApp 来源应用 ID
     * @return array<string,mixed> 过滤后的数据
     */
    function cmspro_sensitivewords_filter_array(array $data, array $fields = [], ?string $scene = null, ?string $sourceApp = null): array
    {
        if (! cmspro_sensitivewords_available()) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            if ($fields !== [] && ! in_array((string) $key, $fields, true)) {
                continue;
            }
            $data[$key] = cmspro_sensitivewords_filter($value, $scene, $sourceApp);
        }

        return $data;
    }
}
