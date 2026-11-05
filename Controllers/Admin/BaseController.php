<?php

namespace App\Apps\CmsproSensitivewords\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * 后台控制器基类
 *
 * 统一封装本应用后台控制器的两类公共能力，避免各控制器重复实现：
 * 1. hasPermission()：后端权限校验（安全兜底，前端 data-permission 仅为体验优化）
 * 2. 统一响应：Layui table 数据源格式与系统 ApiResponse 格式
 *
 * 路由参数一律不加 int 类型提示（开发文档第五章：URL 参数始终为字符串，
 * int 提示会触发 TypeError），需要数值时在方法内 (int) 转换。
 */
abstract class BaseController extends Controller
{
    /** 权限码前缀 */
    protected const PERMISSION_PREFIX = 'cmspro.sensitivewords.';

    /**
     * 校验当前管理员是否具备指定权限。
     *
     * 系统 AdminUser::hasPermission() 内部已对 super_admin 角色直接放行。
     *
     * @param string $code 权限码后缀（如 word.create），自动补全应用前缀
     */
    protected function hasPermission(string $code): bool
    {
        /** @var \App\Models\AdminUser|null $user */
        $user = auth('admin')->user();

        if ($user === null) {
            return false;
        }

        return $user->hasPermission(static::PERMISSION_PREFIX . $code);
    }

    /**
     * 无权限时的统一响应。
     */
    protected function forbidden(string $action = '执行该操作'): JsonResponse
    {
        return response()->json(ApiResponse::error(40301, '无权' . $action));
    }

    /**
     * Layui table 数据源响应（{code,msg,count,data}，与系统 ApiResponse 格式不同）。
     *
     * @param \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator
     * @param callable|null $formatter 单行格式化回调，null 表示直接输出模型 toArray()
     */
    protected function tableResponse($paginator, ?callable $formatter = null): JsonResponse
    {
        $items = $paginator->items();

        if ($formatter !== null) {
            $items = array_map($formatter, $items);
        } else {
            $items = array_map(fn ($item) => is_object($item) && method_exists($item, 'toArray') ? $item->toArray() : $item, $items);
        }

        return response()->json([
            'code' => 0,
            'msg' => '',
            'count' => $paginator->total(),
            'data' => array_values($items),
        ]);
    }
}
