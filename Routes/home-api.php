<?php

/**
 * 敏感词过滤应用 · 前端公开 API 路由
 *
 * 挂载信息（由 ServiceProvider::registerRoutes() 指定）：
 * - 前缀：api/cmspro/sensitivewords
 * - 中间件：['web', 'throttle:60,1']（限流 60 次/分钟，防刷）
 * - 路由名前缀：cmspro.sensitivewords.api.
 *
 * 面向非 PHP 调用方（前端页面、小程序、第三方系统）提供 HTTP 检测/过滤能力。
 * PHP 侧调用请直接用辅助函数 cmspro_sensitivewords_filter()/check() 或 Service，无需走 HTTP。
 *
 * 响应格式统一使用 App\Http\Responses\ApiResponse：
 * { code: 0, message: 'success', data: {...}, timestamp: 1700000000 }
 */

use App\Apps\CmsproSensitivewords\Controllers\HomeApi\FilterApiController;
use Illuminate\Support\Facades\Route;

// 服务状态探测（应用是否启用、词典是否就绪、生效词条数）
Route::get('/status', [FilterApiController::class, 'status'])->name('status');

// 文本检测（返回是否放行、命中详情与替换后文本）
Route::post('/check', [FilterApiController::class, 'check'])->name('check');

// 文本过滤（仅返回替换后文本）
Route::post('/filter', [FilterApiController::class, 'filter'])->name('filter');
