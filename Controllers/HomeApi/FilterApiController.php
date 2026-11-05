<?php

namespace App\Apps\CmsproSensitivewords\Controllers\HomeApi;

use App\Apps\CmsproSensitivewords\Services\SensitiveWordService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * 前端公开 API 控制器
 *
 * 面向前端页面、小程序、第三方系统等「非 PHP 调用方」提供 HTTP 检测/过滤能力
 * （PHP 侧调用请直接用辅助函数或 Service，无需走 HTTP）。
 *
 * 挂载：前缀 api/cmspro/sensitivewords，中间件 web + throttle:60,1（限流防刷）。
 *
 * 安全说明：
 * - 检测接口不写敏感日志明细（scene 固定标记为 open-api），避免被当作日志注水入口
 * - 只接受文本参数，返回命中结果与替换后文本，不落库、不回显其他数据
 * - 限流中间件防止被高频调用消耗 CPU（DFA 检测本身很快，但需防恶意刷量）
 */
class FilterApiController extends Controller
{
    public function __construct(protected SensitiveWordService $filter)
    {
    }

    /**
     * 检测文本（POST /api/cmspro/sensitivewords/check）。
     *
     * 请求体：{ "text": "待检测文本", "scene": "可选场景标识" }
     * 响应：passed（是否放行）、action（最终动作）、hits（命中详情）、filtered（替换后文本）
     */
    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string|max:10000',
            'scene' => 'nullable|string|max:64',
        ], [
            'text.required' => '待检测文本不能为空',
            'text.max' => '单次检测文本不得超过 10000 字',
        ], [
            'text' => '待检测文本',
        ]);

        try {
            $scene = (string) ($validated['scene'] ?? 'open-api');
            $result = $this->filter->check((string) $validated['text'], $scene, 'open-api');

            return response()->json(ApiResponse::success([
                'passed' => $result['passed'],
                'action' => $result['action'],
                'message' => $result['message'],
                'hit_count' => $result['hit_count'],
                'hits' => $result['hits'],
                'filtered' => $result['filtered'],
            ]));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '检测失败：' . $e->getMessage()));
        }
    }

    /**
     * 过滤文本（POST /api/cmspro/sensitivewords/filter）。
     *
     * 请求体：{ "text": "待过滤文本", "scene": "可选场景标识" }
     * 响应：filtered（替换后文本）、hit_count（命中数）
     */
    public function filter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string|max:10000',
            'scene' => 'nullable|string|max:64',
        ], [
            'text.required' => '待过滤文本不能为空',
        ], [
            'text' => '待过滤文本',
        ]);

        try {
            $scene = (string) ($validated['scene'] ?? 'open-api');
            $filtered = $this->filter->filter((string) $validated['text'], $scene, 'open-api');

            return response()->json(ApiResponse::success([
                'filtered' => $filtered,
            ]));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '过滤失败：' . $e->getMessage()));
        }
    }

    /**
     * 服务状态（GET /api/cmspro/sensitivewords/status）。
     *
     * 供前端/第三方探测过滤能力是否可用（应用是否启用、词典是否就绪）。
     */
    public function status(): JsonResponse
    {
        return response()->json(ApiResponse::success([
            'enabled' => $this->filter->isEnabled(),
            'ready' => $this->filter->isReady(),
            'word_total' => $this->filter->wordTotal(),
        ]));
    }
}
