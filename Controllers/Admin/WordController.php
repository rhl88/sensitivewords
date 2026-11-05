<?php

namespace App\Apps\CmsproSensitivewords\Controllers\Admin;

use App\Apps\CmsproSensitivewords\Models\SensitiveCategory;
use App\Apps\CmsproSensitivewords\Models\SensitiveWord;
use App\Apps\CmsproSensitivewords\Services\SensitiveLibraryService;
use App\Apps\CmsproSensitivewords\Services\SensitiveWordManageService;
use App\Apps\CmsproSensitivewords\Services\SensitiveWordService;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * 后台 · 词条管理控制器
 *
 * 承载词条 CRUD、批量启停删除、批量导入、词库导出、在线检测试用。
 * 页面方法只返回视图，数据由前端 AJAX 拉取；写操作一律先做后端权限校验。
 */
class WordController extends BaseController
{
    public function __construct(
        protected SensitiveWordManageService $manage,
        protected SensitiveWordService $filter,
        protected SensitiveLibraryService $library
    ) {
    }

    // ══════════════════════════════════════════════════════════════
    // 一、页面路由
    // ══════════════════════════════════════════════════════════════

    /**
     * 词条管理列表页。
     */
    public function index()
    {
        return view('cmspro.sensitivewords::Admin.words.index');
    }

    /**
     * 词条表单页（新增/编辑共用，iframe 弹窗加载）。
     */
    public function form(Request $request)
    {
        return view('cmspro.sensitivewords::Admin.words.form', [
            'id' => (int) $request->input('id', 0),
            'categories' => $this->manage->listCategories(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // 二、数据接口
    // ══════════════════════════════════════════════════════════════

    /**
     * 词条分页列表（Layui table 数据源）。
     */
    public function list(Request $request): JsonResponse
    {
        if (! $this->hasPermission('word.view')) {
            return $this->forbidden('查看敏感词条');
        }

        $paginator = $this->manage->listWords([
            'keyword' => $request->input('keyword'),
            'category_id' => $request->input('category_id'),
            'action' => $request->input('action'),
            'source' => $request->input('source'),
            'level' => $request->input('level'),
            'status' => $request->input('status'),
            'limit' => (int) $request->input('limit', 15),
            'page' => (int) $request->input('page', 1),
        ]);

        return $this->tableResponse($paginator, function (SensitiveWord $word) {
            return [
                'id' => $word->id,
                'word' => $word->word,
                'word_lower' => $word->word_lower,
                'category_id' => $word->category_id,
                'category_name' => $word->category?->name ?? '未分类',
                'action' => (string) $word->action,
                'level' => $word->level,
                'source' => (string) $word->source,
                'hit_count' => $word->hit_count,
                'remark' => (string) $word->remark,
                'status' => $word->status->value,
                // 显式 format，避免 Carbon 对象被序列化为 ISO 8601
                'last_hit_time' => $word->last_hit_time?->format('Y-m-d H:i:s'),
                'create_time' => $word->create_time?->format('Y-m-d H:i:s'),
            ];
        });
    }

    /**
     * 词条详情（表单回填用）。
     */
    public function show($id): JsonResponse
    {
        if (! $this->hasPermission('word.view')) {
            return $this->forbidden('查看敏感词条');
        }

        $word = SensitiveWord::with('category:id,name,code')->find((int) $id);
        if ($word === null) {
            return response()->json(ApiResponse::error(40401, '词条不存在'));
        }

        $data = $word->toArray();
        $data['status'] = $word->status->value;
        $data['create_time'] = $word->create_time?->format('Y-m-d H:i:s');
        $data['last_hit_time'] = $word->last_hit_time?->format('Y-m-d H:i:s');

        return response()->json(ApiResponse::success($data));
    }

    /**
     * 新增词条。
     */
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission('word.create')) {
            return $this->forbidden('新增敏感词条');
        }

        $validated = $request->validate([
            'word' => 'required|string|max:191',
            'category_id' => 'nullable|integer|min:0',
            'action' => 'nullable|string|in:block,replace,warn,',
            'level' => 'nullable|integer|in:1,2,3',
            'remark' => 'nullable|string|max:255',
            'status' => 'nullable|integer|in:0,1',
        ], [
            'word.required' => '敏感词不能为空',
        ], [
            'word' => '敏感词',
            'category_id' => '所属分类',
        ]);

        try {
            $word = $this->manage->createWord($validated);

            return response()->json(ApiResponse::success(
                ['id' => $word->id, 'word' => $word->word],
                '词条新增成功'
            ));
        } catch (RuntimeException $e) {
            return response()->json(ApiResponse::error(40001, $e->getMessage()));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '词条新增失败：' . $e->getMessage()));
        }
    }

    /**
     * 更新词条。
     */
    public function update(Request $request, $id): JsonResponse
    {
        if (! $this->hasPermission('word.edit')) {
            return $this->forbidden('编辑敏感词条');
        }

        $validated = $request->validate([
            'word' => 'nullable|string|max:191',
            'category_id' => 'nullable|integer|min:0',
            'action' => 'nullable|string|in:block,replace,warn,',
            'level' => 'nullable|integer|in:1,2,3',
            'remark' => 'nullable|string|max:255',
            'status' => 'nullable|integer|in:0,1',
        ], [], [
            'word' => '敏感词',
        ]);

        try {
            $word = $this->manage->updateWord((int) $id, $validated);

            return response()->json(ApiResponse::success(
                ['id' => $word->id, 'word' => $word->word],
                '词条更新成功'
            ));
        } catch (RuntimeException $e) {
            return response()->json(ApiResponse::error(40001, $e->getMessage()));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '词条更新失败：' . $e->getMessage()));
        }
    }

    /**
     * 删除词条（支持单个与批量）。
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        if (! $this->hasPermission('word.delete')) {
            return $this->forbidden('删除敏感词条');
        }

        $ids = $this->resolveIds($request, $id);
        if ($ids === []) {
            return response()->json(ApiResponse::error(40001, '请选择要删除的词条'));
        }

        try {
            $deleted = $this->manage->deleteWords($ids);

            return response()->json(ApiResponse::success(['deleted' => $deleted], '已删除 ' . $deleted . ' 个词条'));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '删除失败：' . $e->getMessage()));
        }
    }

    /**
     * 批量启用/禁用词条。
     */
    public function batchStatus(Request $request): JsonResponse
    {
        if (! $this->hasPermission('word.edit')) {
            return $this->forbidden('修改词条状态');
        }

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|min:1',
            'status' => 'required|integer|in:0,1',
        ], [
            'ids.required' => '请选择要操作的词条',
        ]);

        try {
            $affected = $this->manage->batchUpdateStatus($validated['ids'], (int) $validated['status']);

            return response()->json(ApiResponse::success(
                ['affected' => $affected],
                ($validated['status'] === 1 ? '已启用 ' : '已禁用 ') . $affected . ' 个词条'
            ));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '操作失败：' . $e->getMessage()));
        }
    }

    /**
     * 单条词条状态切换（列表页开关用，仅更新状态字段）。
     */
    public function toggleStatus(Request $request, $id): JsonResponse
    {
        if (! $this->hasPermission('word.edit')) {
            return $this->forbidden('修改词条状态');
        }

        $validated = $request->validate([
            'status' => 'required|integer|in:0,1',
        ]);

        try {
            $word = $this->manage->updateWord((int) $id, ['status' => (int) $validated['status']]);

            return response()->json(ApiResponse::success(
                ['id' => $word->id, 'status' => $word->status->value],
                $validated['status'] === 1 ? '已启用' : '已禁用'
            ));
        } catch (RuntimeException $e) {
            return response()->json(ApiResponse::error(40001, $e->getMessage()));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '操作失败：' . $e->getMessage()));
        }
    }

    // ══════════════════════════════════════════════════════════════
    // 三、导入导出与在线检测
    // ══════════════════════════════════════════════════════════════

    /**
     * 上传词库文件导入词条（txt/csv/zip）。
     */
    public function importWords(Request $request): JsonResponse
    {
        if (! $this->hasPermission('word.import')) {
            return $this->forbidden('导入词库');
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:txt,csv,zip,dic'],
            'mode' => ['nullable', 'string', 'in:skip,update,overwrite'],
            'category_id' => ['nullable', 'integer', 'min:0'],
        ], [
            'file.required' => '请选择词库文件',
            'file.max' => '词库文件不得超过 20MB',
        ], [
            'file' => '词库文件',
        ]);

        try {
            $result = $this->library->importFromFile(
                $request->file('file'),
                (string) ($validated['mode'] ?? 'skip'),
                (int) ($validated['category_id'] ?? 0)
            );

            return response()->json(ApiResponse::success($result, sprintf(
                '导入完成：新增 %d，更新 %d，跳过 %d',
                $result['inserted'],
                $result['updated'],
                $result['skipped']
            )));
        } catch (RuntimeException $e) {
            return response()->json(ApiResponse::error(40001, $e->getMessage()));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '导入失败：' . $e->getMessage()));
        }
    }

    /**
     * 批量粘贴导入（文本域直接粘贴词条，无需制作文件）。
     */
    public function importText(Request $request): JsonResponse
    {
        if (! $this->hasPermission('word.import')) {
            return $this->forbidden('导入词库');
        }

        $validated = $request->validate([
            'content' => 'required|string|max:5242880',
            'mode' => 'nullable|string|in:skip,update,overwrite',
            'category_id' => 'nullable|integer|min:0',
            'action' => 'nullable|string|in:block,replace,warn,',
            'level' => 'nullable|integer|in:1,2,3',
        ], [
            'content.required' => '请粘贴词条内容',
        ], [
            'content' => '词条内容',
        ]);

        try {
            $content = (string) $validated['content'];

            // 统一动作与级别：粘贴导入的词条常来自同一批治理需求
            $defaultAction = (string) ($validated['action'] ?? '');
            $defaultLevel = (int) ($validated['level'] ?? 1);
            if ($defaultAction !== '' || $defaultLevel !== 1) {
                $content = $this->appendDefaultsToLines($content, $defaultAction, $defaultLevel);
            }

            $result = $this->library->importFromText(
                $content,
                SensitiveWord::SOURCE_IMPORT,
                (string) ($validated['mode'] ?? 'skip'),
                'import',
                (int) ($validated['category_id'] ?? 0)
            );

            return response()->json(ApiResponse::success($result, sprintf(
                '导入完成：新增 %d，更新 %d，跳过 %d',
                $result['inserted'],
                $result['updated'],
                $result['skipped']
            )));
        } catch (RuntimeException $e) {
            return response()->json(ApiResponse::error(40001, $e->getMessage()));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '导入失败：' . $e->getMessage()));
        }
    }

    /**
     * 导出词库（按来源/分类过滤，输出 txt 下载）。
     */
    public function exportWords(Request $request)
    {
        if (! $this->hasPermission('word.import')) {
            return $this->forbidden('导出词库');
        }

        try {
            $conditions = array_filter([
                'source' => (string) $request->input('source', ''),
                'category_id' => $request->input('category_id', ''),
                'status' => $request->input('status', ''),
            ], fn ($v) => $v !== '' && $v !== null);

            $result = $this->library->exportToFile($conditions);

            return response($result['content'], 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $result['file_name'] . '"',
            ]);
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '导出失败：' . $e->getMessage()));
        }
    }

    /**
     * 在线检测试用（后台验证过滤效果）。
     *
     * 场景标识固定为 admin.test，命中记录会进入命中日志，便于管理员验证
     * 「检测 → 记日志 → 查日志」的完整链路。
     */
    public function test(Request $request): JsonResponse
    {
        if (! $this->hasPermission('word.view')) {
            return $this->forbidden('使用检测功能');
        }

        $validated = $request->validate([
            'text' => 'required|string|max:10000',
            'scene' => 'nullable|string|max:64',
        ], [
            'text.required' => '请输入待检测文本',
        ], [
            'text' => '待检测文本',
        ]);

        try {
            $text = (string) $validated['text'];
            $scene = (string) ($validated['scene'] ?? 'admin.test');

            $result = $this->filter->check($text, $scene, 'cmspro.sensitivewords');

            return response()->json(ApiResponse::success([
                'passed' => $result['passed'],
                'action' => $result['action'],
                'message' => $result['message'],
                'hit_count' => $result['hit_count'],
                'hits' => $result['hits'],
                'filtered' => $result['filtered'],
                'word_total' => $this->filter->wordTotal(),
            ]));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '检测失败：' . $e->getMessage()));
        }
    }

    /**
     * 下拉选项数据（分类列表，供搜索栏与表单使用）。
     */
    public function categoryOptions(): JsonResponse
    {
        $categories = SensitiveCategory::query()->orderBy('sort')->orderBy('id')->get(['id', 'name', 'code']);

        return response()->json(ApiResponse::success($categories));
    }

    // ══════════════════════════════════════════════════════════════
    // 四、内部工具
    // ══════════════════════════════════════════════════════════════

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

    /**
     * 为粘贴文本的每一行补齐默认动作与级别（已有声明的行不覆盖）。
     *
     * parseWordLines 按「词,动作,级别,分类」解析，此处只在缺段时补位。
     */
    protected function appendDefaultsToLines(string $content, string $action, int $level): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $result = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//')) {
                $result[] = $line;
                continue;
            }

            $parts = preg_split('/[,|，\t]/u', $trimmed) ?: [$trimmed];
            $parts = array_map(fn ($p) => trim((string) $p), $parts);

            // 已声明动作的行保持原样
            if (count($parts) >= 2 && $parts[1] !== '') {
                $result[] = $line;
                continue;
            }

            // 仅裁剪尾部连续空段，保留中间空段以维持「词,动作,级别,分类」的字段位置，
            // 避免动作为空时级别被误读为动作（与 Versionsvr parseWords 同口径修复）
            $segments = [$parts[0], $action, $level > 1 ? (string) $level : '', (string) ($parts[3] ?? '')];
            while (count($segments) > 1 && $segments[count($segments) - 1] === '') {
                array_pop($segments);
            }

            $result[] = implode(',', $segments);
        }

        return implode("\n", $result);
    }
}
