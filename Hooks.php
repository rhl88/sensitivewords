<?php

namespace App\Apps\CmsproSensitivewords;

use App\Apps\CmsproSensitivewords\Services\SensitiveWordService;
use App\Services\HookManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 敏感词过滤应用 · 钩子注册器
 *
 * 分两类职责：
 *
 * 一、监听系统钩子（受配置开关控制，默认关闭以免未经确认就改动其他模块行为）
 *   - content.before_create / content.before_update：过滤系统内容模块的标题与正文
 *   - user.before_register：检测注册提交的昵称与用户名
 *
 * 二、暴露自定义钩子点（其他应用扩展本应用的入口）
 *   - Filter cmspro.sensitivewords.whitelist     ：注入白名单词，按场景放行指定词
 *   - Filter cmspro.sensitivewords.before_filter ：过滤前预处理文本（如剥离 HTML）
 *   - Filter cmspro.sensitivewords.after_filter  ：过滤后再加工结果
 *   - Action cmspro.sensitivewords.hit           ：命中后通知（推送审核、发通知等）
 *
 * 注册时必须传第 4 个参数 app_id，应用禁用/卸载时框架据此自动摘除全部钩子。
 */
class Hooks
{
    /** 应用 ID —— 钩子归属标记 */
    public const APP_ID = 'cmspro.sensitivewords';

    public function register(HookManager $hooks): void
    {
        // ══ 一、监听系统钩子（按配置开关决定是否挂载）══════════════
        if ($this->configEnabled('hook_content_filter')) {
            $hooks->registerFilter('content.before_create', [$this, 'filterContentData'], 20, self::APP_ID);
            $hooks->registerFilter('content.before_update', [$this, 'filterContentData'], 20, self::APP_ID);
        }

        if ($this->configEnabled('hook_user_register')) {
            $hooks->registerFilter('user.before_register', [$this, 'filterRegisterData'], 20, self::APP_ID);
        }

        // ══ 二、本应用生命周期（清理词典缓存，保证卸载/禁用后不残留）══
        $hooks->registerAction('app.disabled', [$this, 'onAppDisabled'], 10, self::APP_ID);

        // ══ 三、暴露自定义钩子点（挂一个自身实现，形成闭环示范）═════
        // 白名单：默认空数组，其他应用注册 Filter 追加即可
        $hooks->registerFilter('cmspro.sensitivewords.whitelist', [$this, 'defaultWhitelist'], 10, self::APP_ID);
    }

    // ──────────────────────────────────────────────────────────────
    // 系统钩子回调（Filter 必须返回加工后的值）
    // ──────────────────────────────────────────────────────────────

    /**
     * 过滤系统内容模块的提交数据（content.before_create / before_update）。
     *
     * 只对文本类字段（title / summary / content / description）执行替换，
     * 其他字段原样保留，避免误改附件、配置等非文本数据。
     *
     * @param mixed $data 内容数据数组
     * @return mixed 过滤后的数据
     */
    public function filterContentData($data)
    {
        if (! is_array($data)) {
            return $data;
        }

        return $this->filterFields($data, ['title', 'summary', 'content', 'description'], 'content.save', self::APP_ID);
    }

    /**
     * 过滤前台用户注册数据（user.before_register）。
     *
     * 注册场景只做检测不做替换：命中禁止级词条时抛出异常阻断注册，
     * 提示文案由 SensitiveWordService 给出（含具体命中词）。
     *
     * @param mixed $data 注册数据数组
     * @return mixed 原样返回的数据
     *
     * @throws \RuntimeException 命中禁止级词条时
     */
    public function filterRegisterData($data)
    {
        if (! is_array($data)) {
            return $data;
        }

        try {
            $service = app(SensitiveWordService::class);
            foreach (['nickname', 'username', 'name'] as $field) {
                $value = $data[$field] ?? null;
                if (! is_string($value) || $value === '') {
                    continue;
                }

                $error = $service->validateText($value, 'user.register', self::APP_ID);
                if ($error !== null) {
                    throw new \RuntimeException($error);
                }
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('[cmspro.sensitivewords] 注册数据检测异常，已放行', ['error' => $e->getMessage()]);
        }

        return $data;
    }

    // ──────────────────────────────────────────────────────────────
    // Action 回调
    // ──────────────────────────────────────────────────────────────

    /**
     * 应用被禁用时清理词典编译缓存。
     *
     * 禁用后辅助函数会因 isReady()/总开关判定而放行，但编译缓存文件仍留在
     * storage 下；此处主动清理，避免重新启用后读到过期词条快照。
     */
    public function onAppDisabled($appId = null): void
    {
        if ($appId !== null && $appId !== self::APP_ID) {
            return;
        }

        try {
            app(SensitiveWordService::class)->rebuildDictionary();
        } catch (Throwable $e) {
            // 清理失败不影响禁用流程
        }
    }

    // ──────────────────────────────────────────────────────────────
    // 自定义钩子点的默认实现
    // ──────────────────────────────────────────────────────────────

    /**
     * 默认白名单（返回空数组，由其他应用追加）。
     *
     * @param array<int,string> $whitelist
     * @return array<int,string>
     */
    public function defaultWhitelist($whitelist = [], string $scene = '', string $sourceApp = ''): array
    {
        return is_array($whitelist) ? $whitelist : [];
    }

    // ──────────────────────────────────────────────────────────────
    // 内部工具
    // ──────────────────────────────────────────────────────────────

    /**
     * 批量过滤数组中的指定文本字段。
     *
     * @param array<string,mixed> $data
     * @param array<int,string>   $fields
     * @return array<string,mixed>
     */
    protected function filterFields(array $data, array $fields, string $scene, string $sourceApp): array
    {
        try {
            if (! function_exists('cmspro_sensitivewords_filter_array')) {
                return $data;
            }

            return cmspro_sensitivewords_filter_array($data, $fields, $scene, $sourceApp);
        } catch (Throwable $e) {
            Log::warning('[cmspro.sensitivewords] 字段过滤异常，已返回原数据', ['error' => $e->getMessage()]);

            return $data;
        }
    }

    /**
     * 判断某配置开关是否开启（表不存在等异常时返回 false，即不挂载钩子）。
     */
    protected function configEnabled(string $key): bool
    {
        try {
            if (! function_exists('cmspro_sensitivewords_config')) {
                return false;
            }

            return (string) cmspro_sensitivewords_config($key, '0') === '1';
        } catch (Throwable $e) {
            return false;
        }
    }
}
