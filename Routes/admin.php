<?php

/**
 * 敏感词过滤应用 · 后台管理路由
 *
 * 挂载信息（由 ServiceProvider::registerRoutes() 指定）：
 * - 前缀：admin/cmspro/sensitivewords
 * - 中间件：['web', 'auth:admin', 'permission:admin']
 * - 路由名前缀：cmspro.sensitivewords.admin.
 *
 * 组织约定：页面路由挂根路径返回 Blade 视图；数据接口统一放 api/ 子前缀返回 JSON。
 */

use App\Apps\CmsproSensitivewords\Controllers\Admin\CategoryController;
use App\Apps\CmsproSensitivewords\Controllers\Admin\HitLogController;
use App\Apps\CmsproSensitivewords\Controllers\Admin\LibraryController;
use App\Apps\CmsproSensitivewords\Controllers\Admin\SettingController;
use App\Apps\CmsproSensitivewords\Controllers\Admin\WordController;
use Illuminate\Support\Facades\Route;

// ══ 一、词条管理 ══════════════════════════════════════════════════
Route::get('/words', [WordController::class, 'index'])->name('words.index');
Route::get('/words/form', [WordController::class, 'form'])->name('words.form');

Route::get('/api/words', [WordController::class, 'list'])->name('words.list');
Route::get('/api/words/{id}', [WordController::class, 'show'])->whereNumber('id')->name('words.show');
Route::post('/api/words', [WordController::class, 'store'])->name('words.store');
Route::put('/api/words/{id}', [WordController::class, 'update'])->whereNumber('id')->name('words.update');
Route::delete('/api/words/{id}', [WordController::class, 'destroy'])->whereNumber('id')->name('words.destroy');
Route::post('/api/words/toggle-status/{id}', [WordController::class, 'toggleStatus'])->whereNumber('id')->name('words.toggle');
Route::post('/api/words/batch-status', [WordController::class, 'batchStatus'])->name('words.batchStatus');
Route::post('/api/words/import-file', [WordController::class, 'importWords'])->name('words.importFile');
Route::post('/api/words/import-text', [WordController::class, 'importText'])->name('words.importText');
Route::get('/api/words/export', [WordController::class, 'exportWords'])->name('words.export');
Route::post('/api/words/test', [WordController::class, 'test'])->name('words.test');
Route::get('/api/category-options', [WordController::class, 'categoryOptions'])->name('categoryOptions');

// ══ 二、分类管理 ══════════════════════════════════════════════════
Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
Route::get('/categories/form', [CategoryController::class, 'form'])->name('categories.form');

Route::get('/api/categories', [CategoryController::class, 'list'])->name('categories.list');
Route::get('/api/categories/{id}', [CategoryController::class, 'show'])->whereNumber('id')->name('categories.show');
Route::post('/api/categories', [CategoryController::class, 'store'])->name('categories.store');
Route::put('/api/categories/{id}', [CategoryController::class, 'update'])->whereNumber('id')->name('categories.update');
Route::delete('/api/categories/{id}', [CategoryController::class, 'destroy'])->whereNumber('id')->name('categories.destroy');

// ══ 三、词库维护 ══════════════════════════════════════════════════
Route::get('/library', [LibraryController::class, 'index'])->name('library.index');

Route::get('/api/library/info', [LibraryController::class, 'info'])->name('library.info');
Route::get('/api/library/check-remote', [LibraryController::class, 'checkRemote'])->name('library.checkRemote');
Route::post('/api/library/sync-remote', [LibraryController::class, 'syncRemote'])->name('library.syncRemote');
Route::post('/api/library/import-builtin', [LibraryController::class, 'importBuiltin'])->name('library.importBuiltin');
Route::post('/api/library/clear-source', [LibraryController::class, 'clearBySource'])->name('library.clearSource');
// 导出本地词库（format=zip 输出版本服务发布包，format=txt 输出纯文本词库）
Route::get('/api/library/export', [LibraryController::class, 'export'])->name('library.export');

// ══ 四、命中日志 ══════════════════════════════════════════════════
Route::get('/hit-logs', [HitLogController::class, 'index'])->name('hitLogs.index');

Route::get('/api/hit-logs', [HitLogController::class, 'list'])->name('hitLogs.list');
Route::get('/api/hit-logs/stats', [HitLogController::class, 'stats'])->name('hitLogs.stats');
Route::delete('/api/hit-logs/{id}', [HitLogController::class, 'destroy'])->whereNumber('id')->name('hitLogs.destroy');
Route::post('/api/hit-logs/clean-expired', [HitLogController::class, 'cleanExpired'])->name('hitLogs.cleanExpired');
Route::post('/api/hit-logs/truncate', [HitLogController::class, 'truncate'])->name('hitLogs.truncate');

// ══ 五、过滤设置 ══════════════════════════════════════════════════
Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');

Route::get('/api/settings', [SettingController::class, 'getConfig'])->name('settings.get');
Route::post('/api/settings', [SettingController::class, 'updateConfig'])->name('settings.update');
Route::post('/api/settings/rebuild-dictionary', [SettingController::class, 'rebuildDictionary'])->name('settings.rebuild');
