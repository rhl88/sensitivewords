<?php

namespace App\Apps\CmsproSensitivewords\Controllers\Admin;

use App\Apps\CmsproSensitivewords\Models\SensitiveHitLog;
use App\Apps\CmsproSensitivewords\Services\SensitiveWordManageService;
use App\Apps\CmsproSensitivewords\Services\SensitiveWordService;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * 后台 · 命中日志控制器
 *
 * 提供命中记录的查询、概览统计与清理（按 ID 删除 / 按天数清理 / 全量清空）。
 * 日志属旁路数据，本控制器所有写操作均不影响过滤主流程。
 */
class HitLogController extends BaseController
{
    public function __construct(
        protected SensitiveWordManageService $manage,
        protected SensitiveWordService $filter
    ) {
    }

    /**
     * 命中日志页。
     */
    public function index()
    {
        return view('cmspro.sensitivewords::Admin.hit-logs.index', [
            'stats' => $this->manage->logStats(),
        ]);
    }

    /**
     * 日志分页列表（Layui table 数据源）。
     */
    public function list(Request $request): JsonResponse
    {
        if (! $this->hasPermission('log.view')) {
            return $this->forbidden('查看命中日志');
        }

        $paginator = $this->manage->listHitLogs([
            'source_app' => $request->input('source_app'),
            'scene' => $request->input('scene'),
            'action' => $request->input('action'),
            'keyword' => $request->input('keyword'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'limit' => (int) $request->input('limit', 15),
            'page' => (int) $request->input('page', 1),
        ]);

        return $this->tableResponse($paginator, function (SensitiveHitLog $log) {
            return [
                'id' => $log->id,
                'source_app' => (string) $log->source_app,
                'scene' => (string) $log->scene,
                'words' => (string) $log->words,
                'word_count' => (int) $log->word_count,
                'action' => (string) $log->action,
                'content' => (string) $log->content,
                'request_path' => (string) $log->request_path,
                'ip' => (string) $log->ip,
                'user_id' => (int) $log->user_id,
                'admin_id' => (int) $log->admin_id,
                'create_time' => $log->create_time?->format('Y-m-d H:i:s'),
            ];
        });
    }

    /**
     * 日志概览统计（页头卡片）。
     */
    public function stats(): JsonResponse
    {
        if (! $this->hasPermission('log.view')) {
            return $this->forbidden('查看命中日志');
        }

        return response()->json(ApiResponse::success($this->manage->logStats()));
    }

    /**
     * 删除指定日志（按 ID 批量）。
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        if (! $this->hasPermission('log.delete')) {
            return $this->forbidden('删除命中日志');
        }

        $ids = $this->resolveIds($request, $id);
        if ($ids === []) {
            return response()->json(ApiResponse::error(40001, '请选择要删除的日志'));
        }

        try {
            $deleted = $this->manage->deleteHitLogs($ids);

            return response()->json(ApiResponse::success(['deleted' => $deleted], '已删除 ' . $deleted . ' 条日志'));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '删除失败：' . $e->getMessage()));
        }
    }

    /**
     * 按保留天数清理超期日志。
     */
    public function cleanExpired(): JsonResponse
    {
        if (! $this->hasPermission('log.delete')) {
            return $this->forbidden('清理命中日志');
        }

        try {
            $deleted = $this->filter->cleanExpiredLogs();

            return response()->json(ApiResponse::success(
                ['deleted' => $deleted],
                $deleted > 0 ? '已清理 ' . $deleted . ' 条超期日志' : '没有需要清理的超期日志'
            ));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '清理失败：' . $e->getMessage()));
        }
    }

    /**
     * 清空全部命中日志（高危操作，需二次确认）。
     */
    public function truncate(Request $request): JsonResponse
    {
        if (! $this->hasPermission('log.delete')) {
            return $this->forbidden('清空命中日志');
        }

        // 二次确认：必须显式传入 confirm=1，防止误触清空
        if ((string) $request->input('confirm') !== '1') {
            return response()->json(ApiResponse::error(40001, '清空操作需要二次确认'));
        }

        try {
            $deleted = SensitiveHitLog::query()->delete();

            return response()->json(ApiResponse::success(['deleted' => $deleted], '已清空全部命中日志'));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '清空失败：' . $e->getMessage()));
        }
    }

    /**
     * 解析待操作的 ID 集合（兼容路径单个 ID 与请求体 ids 数组）。
     *
     * @return array<int,int>
     */
    protected function resolveIds(Request $request, $id): array
    {
        $ids = $request->input('ids');

        if (is_array($ids) && $ids !== []) {
            return array_values(array_filter(array_map('intval', $ids), fn ($v) => $v > 0));
        }

        if (is_string($ids) && trim($ids) !== '') {
            return array_values(array_filter(array_map('intval', explode(',', $ids)), fn ($v) => $v > 0));
        }

        $single = (int) $id;

        return $single > 0 ? [$single] : [];
    }
}
