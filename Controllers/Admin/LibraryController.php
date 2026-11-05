<?php

namespace App\Apps\CmsproSensitivewords\Controllers\Admin;

use App\Apps\CmsproSensitivewords\Models\SensitiveWord;
use App\Apps\CmsproSensitivewords\Services\SensitiveLibraryService;
use App\Apps\CmsproSensitivewords\Services\SensitiveWordManageService;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * 后台 · 词库维护控制器
 *
 * 词库维护页聚合三类操作：
 * 1. 词库信息概览（各来源词条数、内置词库、本地版本、存储目录状态）
 * 2. 在线词库更新（对接版本服务 Versionsvr：检查版本 → 下载 → 校验 → 落库）
 * 3. 本地词库管理（导入内置基础词库、按来源清理、重建词典缓存）
 */
class LibraryController extends BaseController
{
    public function __construct(
        protected SensitiveLibraryService $library,
        protected SensitiveWordManageService $manage
    ) {
    }

    /**
     * 词库维护页。
     */
    public function index()
    {
        return view('cmspro.sensitivewords::Admin.library.index', [
            'info' => $this->library->libraryInfo(),
        ]);
    }

    /**
     * 词库信息（页头统计卡片数据源）。
     */
    public function info(): JsonResponse
    {
        if (! $this->hasPermission('manage')) {
            return $this->forbidden('查看词库信息');
        }

        try {
            return response()->json(ApiResponse::success($this->library->libraryInfo()));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '词库信息读取失败：' . $e->getMessage()));
        }
    }

    /**
     * 检查远端最新词库版本。
     */
    public function checkRemote(): JsonResponse
    {
        if (! $this->hasPermission('library.update')) {
            return $this->forbidden('检查在线词库');
        }

        try {
            $info = $this->library->checkRemoteLibrary();

            // available=false 时把不可用原因作为业务提示返回（非异常，code 仍为 0）
            return response()->json(ApiResponse::success($info, $info['available'] ? 'ok' : ($info['message'] ?: '版本服务不可用')));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '版本检查失败：' . $e->getMessage()));
        }
    }

    /**
     * 同步官方词库（下载 + 校验 + 落库）。
     */
    public function syncRemote(Request $request): JsonResponse
    {
        if (! $this->hasPermission('library.update')) {
            return $this->forbidden('同步在线词库');
        }

        $validated = $request->validate([
            'version' => 'nullable|string|max:32',
            'mode' => 'nullable|string|in:merge,replace',
        ], [], [
            'version' => '词库版本',
        ]);

        try {
            $result = $this->library->syncRemoteLibrary(
                $validated['version'] ?? null,
                $validated['mode'] ?? null
            );

            return response()->json(ApiResponse::success($result, sprintf(
                '词库同步完成（v%s）：新增 %d，更新 %d，跳过 %d%s',
                $result['version'],
                $result['inserted'],
                $result['updated'],
                $result['skipped'],
                $result['removed'] > 0 ? '，清除旧官方词条 ' . $result['removed'] : ''
            )));
        } catch (RuntimeException $e) {
            return response()->json(ApiResponse::error(50002, $e->getMessage()));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '词库同步失败：' . $e->getMessage()));
        }
    }

    /**
     * 导入内置基础词库（应用自带的示范词库）。
     */
    public function importBuiltin(): JsonResponse
    {
        if (! $this->hasPermission('word.import')) {
            return $this->forbidden('导入内置词库');
        }

        try {
            $result = $this->library->importBuiltinWords();

            if (($result['inserted'] ?? 0) === 0 && ($result['updated'] ?? 0) === 0) {
                return response()->json(ApiResponse::success($result, '内置基础词库已存在，无需重复导入'));
            }

            return response()->json(ApiResponse::success($result, sprintf(
                '内置基础词库导入完成：新增 %d，跳过 %d',
                $result['inserted'],
                $result['skipped']
            )));
        } catch (RuntimeException $e) {
            return response()->json(ApiResponse::error(40001, $e->getMessage()));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '导入失败：' . $e->getMessage()));
        }
    }

    /**
     * 导出本地词库。
     *
     * 支持两种格式：
     * - format=zip（默认）：版本服务发布包（words.txt + meta.json），可直接上传到
     *   Versionsvr「敏感词库」页发布，供其他站点在线同步
     * - format=txt：纯文本词库，可再导入本应用或其他站点
     *
     * @param Request $request sources（多选来源，逗号分隔）、category_id、status、version、format
     */
    public function export(Request $request)
    {
        if (! $this->hasPermission('word.import')) {
            return $this->forbidden('导出词库');
        }

        $validated = $request->validate([
            'sources' => 'nullable|string|max:100',
            'category_id' => 'nullable|integer|min:0',
            'status' => 'nullable|integer|in:0,1',
            'version' => 'nullable|string|max:32',
            'format' => 'nullable|string|in:zip,txt',
        ], [
            'version.max' => '版本号不得超过 32 个字符',
        ], [
            'sources' => '词库来源',
            'version' => '版本号',
        ]);

        $conditions = array_filter([
            'sources' => (string) ($validated['sources'] ?? ''),
            'category_id' => $validated['category_id'] ?? '',
            'status' => $validated['status'] ?? '',
        ], fn ($v) => $v !== '' && $v !== null);

        $format = $validated['format'] ?? 'zip';

        try {
            if ($format === 'txt') {
                $result = $this->library->exportToFile($conditions);

                return response($result['content'], 200, [
                    'Content-Type' => 'text/plain; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="' . $result['file_name'] . '"',
                ]);
            }

            $package = $this->library->buildReleasePackage($conditions, $validated['version'] ?? null);

            return response($package['content'], 200, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="' . $package['file_name'] . '"',
                'X-Word-Count' => (string) $package['word_count'],
                'X-Checksum-Sha256' => $package['checksum'],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(ApiResponse::error(40001, $e->getMessage()), 400);
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '导出失败：' . $e->getMessage()), 500);
        }
    }

    /**
     * 按来源清理词条（如清空在线词库、清空导入词库，不影响手工词条）。
     */
    public function clearBySource(Request $request): JsonResponse
    {
        if (! $this->hasPermission('word.delete')) {
            return $this->forbidden('清理词库');
        }

        $validated = $request->validate([
            'source' => 'required|string|in:builtin,import,library',
            'confirm' => 'required|string|in:1',
        ], [
            'source.required' => '请选择要清理的词库来源',
            'confirm.required' => '清理操作需要二次确认',
        ], [
            'source' => '词库来源',
        ]);

        // builtin 为应用自带词库，清理后可通过「导入内置词库」恢复；
        // library 为在线同步词库，清理后可重新同步；两者均不允许误清手工词条
        try {
            $deleted = $this->manage->deleteWordsBy(['source' => $validated['source']]);

            return response()->json(ApiResponse::success(
                ['deleted' => $deleted],
                '已清理 ' . $deleted . ' 条「' . $this->sourceLabel($validated['source']) . '」词条'
            ));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '清理失败：' . $e->getMessage()));
        }
    }

    /**
     * 来源中文标签。
     */
    protected function sourceLabel(string $source): string
    {
        return match ($source) {
            SensitiveWord::SOURCE_BUILTIN => '内置基础词库',
            SensitiveWord::SOURCE_IMPORT => '文件导入',
            SensitiveWord::SOURCE_LIBRARY => '官方在线词库',
            default => $source,
        };
    }
}
