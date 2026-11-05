<?php

namespace App\Apps\CmsproSensitivewords\Controllers\Admin;

use App\Apps\CmsproSensitivewords\Models\SensitiveCategory;
use App\Apps\CmsproSensitivewords\Services\SensitiveWordManageService;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * 后台 · 词条分类管理控制器
 *
 * 分类用于组织词条并承载「分类级默认动作」：词条未指定动作时回落到分类的
 * default_action，分类也为空时再回落到全局配置。系统内置分类（is_system=1）
 * 由安装脚本创建，禁止删除、禁止修改 code。
 */
class CategoryController extends BaseController
{
    public function __construct(protected SensitiveWordManageService $manage)
    {
    }

    /**
     * 分类管理页。
     */
    public function index()
    {
        return view('cmspro.sensitivewords::Admin.categories.index');
    }

    /**
     * 分类表单页（新增/编辑共用，iframe 弹窗加载）。
     */
    public function form(Request $request)
    {
        $id = (int) $request->input('id', 0);

        return view('cmspro.sensitivewords::Admin.categories.form', [
            'id' => $id,
            'category' => $id > 0 ? SensitiveCategory::find($id) : null,
        ]);
    }

    /**
     * 分类列表（Layui table 数据源，非分页——分类数量有限）。
     */
    public function list(Request $request): JsonResponse
    {
        if (! $this->hasPermission('category.manage')) {
            return $this->forbidden('查看词条分类');
        }

        $categories = $this->manage->listCategories();

        return response()->json([
            'code' => 0,
            'msg' => '',
            'count' => count($categories),
            'data' => $categories,
        ]);
    }

    /**
     * 分类详情（表单回填用）。
     */
    public function show($id): JsonResponse
    {
        if (! $this->hasPermission('category.manage')) {
            return $this->forbidden('查看词条分类');
        }

        $category = SensitiveCategory::find((int) $id);
        if ($category === null) {
            return response()->json(ApiResponse::error(40401, '分类不存在'));
        }

        $data = $category->toArray();
        $data['status'] = $category->status->value;
        $data['word_count'] = (int) $category->word_count;

        return response()->json(ApiResponse::success($data));
    }

    /**
     * 新增分类。
     */
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission('category.manage')) {
            return $this->forbidden('新增词条分类');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'nullable|string|max:50|regex:/^[a-z][a-z0-9_]*$/',
            'default_action' => 'nullable|string|in:block,replace,warn,',
            'description' => 'nullable|string|max:255',
            'sort' => 'nullable|integer|min:0|max:9999',
            'status' => 'nullable|integer|in:0,1',
        ], [
            'name.required' => '分类名称不能为空',
            'code.regex' => '分类标识仅允许小写字母、数字和下划线，且以字母开头',
        ], [
            'name' => '分类名称',
            'code' => '分类标识',
        ]);

        try {
            $category = $this->manage->createCategory($validated);

            return response()->json(ApiResponse::success(
                ['id' => $category->id, 'code' => $category->code],
                '分类新增成功'
            ));
        } catch (RuntimeException $e) {
            return response()->json(ApiResponse::error(40001, $e->getMessage()));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '分类新增失败：' . $e->getMessage()));
        }
    }

    /**
     * 更新分类。
     */
    public function update(Request $request, $id): JsonResponse
    {
        if (! $this->hasPermission('category.manage')) {
            return $this->forbidden('编辑词条分类');
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:50',
            'code' => 'nullable|string|max:50|regex:/^[a-z][a-z0-9_]*$/',
            'default_action' => 'nullable|string|in:block,replace,warn,',
            'description' => 'nullable|string|max:255',
            'sort' => 'nullable|integer|min:0|max:9999',
            'status' => 'nullable|integer|in:0,1',
        ], [
            'code.regex' => '分类标识仅允许小写字母、数字和下划线，且以字母开头',
        ], [
            'name' => '分类名称',
        ]);

        try {
            $category = $this->manage->updateCategory((int) $id, $validated);

            return response()->json(ApiResponse::success(
                ['id' => $category->id, 'code' => $category->code],
                '分类更新成功'
            ));
        } catch (RuntimeException $e) {
            return response()->json(ApiResponse::error(40001, $e->getMessage()));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '分类更新失败：' . $e->getMessage()));
        }
    }

    /**
     * 删除分类（系统内置分类与仍有词条的分类不允许删除）。
     */
    public function destroy($id): JsonResponse
    {
        if (! $this->hasPermission('category.manage')) {
            return $this->forbidden('删除词条分类');
        }

        try {
            $this->manage->deleteCategory((int) $id);

            return response()->json(ApiResponse::success(null, '分类已删除'));
        } catch (RuntimeException $e) {
            return response()->json(ApiResponse::error(40001, $e->getMessage()));
        } catch (Throwable $e) {
            return response()->json(ApiResponse::error(50001, '分类删除失败：' . $e->getMessage()));
        }
    }
}
