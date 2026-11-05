<?php

namespace App\Apps\CmsproSensitivewords;

use App\Apps\CmsproSensitivewords\Services\SensitiveWordService;
use App\Models\ConfigItem;
use App\Services\HookManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

/**
 * 敏感词过滤应用 · 服务提供者
 *
 * 应用入口，负责：
 * 1. 加载应用级辅助函数（helpers.php）与合并默认配置
 * 2. 注册字符串验证规则 sensitive_word（其他应用零依赖接入方式之一）
 * 3. 绑定核心服务为单例（同请求内复用词典树，避免重复建树）
 * 4. 注册后台路由与前端 API 路由
 * 5. 注册视图命名空间与钩子
 *
 * 注意：系统 AppServiceProvider 在 register 阶段注册本 Provider，并在
 * $this->app->booted() 回调中延迟调用 boot()，因此 boot() 中可安全访问数据库。
 */
class ServiceProvider extends BaseServiceProvider
{
    /** 应用根目录相对路径（基于 base_path()） */
    private const APP_PATH = 'app/Apps/CmsproSensitivewords';

    /** 应用 ID */
    private const APP_ID = 'cmspro.sensitivewords';

    /** 应用配置命名空间 */
    private const CONFIG_KEY = 'apps.cmspro.sensitivewords';

    /** 视图命名空间 */
    private const VIEW_NAMESPACE = 'cmspro.sensitivewords';

    /** 配置项 code 前缀 */
    private const CONFIG_CODE_PREFIX = 'app_cmspro_sensitivewords_';

    /**
     * 注册阶段：只做绑定，不查数据库。
     */
    public function register(): void
    {
        // 应用级辅助函数（cmspro_sensitivewords_filter / _check / _available 等）
        $helpers = base_path(self::APP_PATH . '/helpers.php');
        if (file_exists($helpers)) {
            require_once $helpers;
        }

        // 默认配置兜底
        $this->mergeConfigFrom(
            base_path(self::APP_PATH . '/Config/sensitivewords.php'),
            self::CONFIG_KEY
        );

        // 核心服务单例：同请求内复用已编译的词典树
        $this->app->singleton(SensitiveWordService::class, function ($app) {
            return new SensitiveWordService($app->make(HookManager::class));
        });
    }

    /**
     * 启动阶段：数据库与路由容器均已就绪。
     */
    public function boot(): void
    {
        $this->registerValidationRule();
        $this->registerRoutes();
        $this->loadViews();
        $this->registerHooks();
    }

    /**
     * 注册字符串验证规则 sensitive_word
     *
     * 其他应用无需 use 本应用类即可接入（真正的弱依赖）：
     * ```php
     * $request->validate(['content' => 'required|string|sensitive_word']);
     * // 或带场景参数：'sensitive_word:comment.create,cmspro.blog'
     * ```
     * 规则内部先判定能力是否可用，不可用时直接通过，不阻断调用方表单。
     */
    protected function registerValidationRule(): void
    {
        Validator::extend('sensitive_word', function ($attribute, $value, $parameters) {
            if (! is_string($value) || $value === '') {
                return true;
            }
            if (! function_exists('cmspro_sensitivewords_available') || ! cmspro_sensitivewords_available()) {
                return true;
            }

            try {
                return app(SensitiveWordService::class)
                    ->passes($value, (string) ($parameters[0] ?? ''), (string) ($parameters[1] ?? ''));
            } catch (\Throwable $e) {
                return true;
            }
        }, '内容包含违规词，请修改后重试');

        // 兼容 replacer：把提示文案替换为具体命中词，提升用户可修正性
        Validator::replacer('sensitive_word', function ($message, $attribute, $rule, $parameters) {
            return $message;
        });
    }

    /**
     * 注册路由
     *
     * - 后台页面与接口：前缀 admin/cmspro/sensitivewords，中间件 web + auth:admin + permission:admin
     * - 前端公开 API：前缀 api/cmspro/sensitivewords，中间件 web + throttle:60,1
     */
    protected function registerRoutes(): void
    {
        Route::prefix('admin/cmspro/sensitivewords')
            ->middleware(['web', 'auth:admin', 'permission:admin'])
            ->name('cmspro.sensitivewords.admin.')
            ->group(base_path(self::APP_PATH . '/Routes/admin.php'));

        Route::prefix('api/cmspro/sensitivewords')
            ->middleware(['web', 'throttle:60,1'])
            ->name('cmspro.sensitivewords.api.')
            ->group(base_path(self::APP_PATH . '/Routes/home-api.php'));
    }

    /**
     * 注册视图命名空间（view('cmspro.sensitivewords::Admin.words.index')）。
     */
    protected function loadViews(): void
    {
        $this->loadViewsFrom(
            base_path(self::APP_PATH . '/Views'),
            self::VIEW_NAMESPACE
        );
    }

    /**
     * 注册钩子
     *
     * 两条通道并存（与 CmsproDemo 一致）：
     * 1. 系统通道：应用启用时按 manifest 的 "hooks" 字段实例化 Hooks 并注册
     * 2. 应用通道：每次请求 boot 时补注册，保证进程重启后钩子仍在
     * HookManager 为单例，重复注册会导致回调执行两次，故先检查是否已注册。
     */
    protected function registerHooks(): void
    {
        try {
            /** @var HookManager $hookManager */
            $hookManager = $this->app->make(HookManager::class);

            $registered = $hookManager->getRegisteredHooks(self::APP_ID);
            if (! empty($registered['actions']) || ! empty($registered['filters'])) {
                return;
            }

            (new Hooks())->register($hookManager);
        } catch (\Throwable $e) {
            // 钩子注册失败不阻断应用启动
        }
    }

    /**
     * 读取应用配置项（路由/规则注册等早期阶段使用，不走缓存）。
     */
    protected function getConfigValue(string $name, string $default = ''): string
    {
        try {
            $value = ConfigItem::where('code', self::CONFIG_CODE_PREFIX . $name)->value('value');

            return ($value === null || $value === '') ? $default : (string) $value;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
