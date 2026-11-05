<?php

namespace App\Apps\CmsproSensitivewords\Rules;

use App\Apps\CmsproSensitivewords\Services\SensitiveWordService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 敏感词验证规则（Laravel 11+ ValidationRule 接口实现）。
 *
 * 其他应用在表单校验中直接追加本规则即可接入敏感词检测，
 * 命中「禁止」级词条时验证失败并返回具体命中词提示：
 *
 * ```php
 * use App\Apps\CmsproSensitivewords\Rules\SensitiveWordRule;
 *
 * $request->validate([
 *     'content' => ['required', 'string', new SensitiveWordRule('comment.create', 'cmspro.blog')],
 * ]);
 * ```
 *
 * 若不想直接依赖类（弱依赖场景），可改用字符串规则 sensitive_word
 * （由 ServiceProvider 通过 Validator::extend 注册，效果等同）。
 */
class SensitiveWordRule implements ValidationRule
{
    public function __construct(
        protected string $scene = '',
        protected string $sourceApp = ''
    ) {
    }

    /**
     * 执行验证：命中禁止级词条时通过 $fail 返回提示。
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        // 能力不可用（应用未安装/被禁用/总开关关闭）时直接放行，不阻断调用方业务
        if (! function_exists('cmspro_sensitivewords_available') || ! cmspro_sensitivewords_available()) {
            return;
        }

        try {
            $error = app(SensitiveWordService::class)->validateText($value, $this->scene, $this->sourceApp);
            if ($error !== null) {
                $fail($error);
            }
        } catch (\Throwable $e) {
            // 检测异常时放行，避免验证层故障阻断全站表单
        }
    }
}
