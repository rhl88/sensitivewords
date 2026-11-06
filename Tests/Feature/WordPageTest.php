<?php

namespace App\Apps\CmsproSensitivewords\Tests\Feature;

use App\Apps\CmsproSensitivewords\Tests\SensitivewordsTestCase;

/**
 * 词条管理页视图测试。
 *
 * 守护批量导入弹窗的下拉框渲染结构（回归防护）：
 * Layui `form.render(type, filter)` 只渲染 `.layui-form` 容器内的元素，
 * 弹窗模板曾缺少 `.layui-form` 包裹，导致 3 个 select（默认动作/重复处理×2）
 * 在弹窗中不被渲染成 layui 下拉样式，且 `success` 回调误把模板 ID
 * 当作 lay-filter 传入（`form.render(null, 'importTpl')`）而空转。
 *
 * 修复：模板根节点改为 `<div class="layui-form" lay-filter="importForm">`，
 * 回调改调 `form.render(null, 'importForm')`。本测试断言该结构不回退。
 */
class WordPageTest extends SensitivewordsTestCase
{
    private const PAGE_URL = '/admin/cmspro/sensitivewords/words';

    public function test_words_page_renders(): void
    {
        $this->actingAdmin();

        $response = $this->get(self::PAGE_URL);

        $response->assertOk();
        $response->assertSee('批量导入词条', false);
    }

    public function test_import_dialog_has_layui_form_wrapper(): void
    {
        $this->actingAdmin();

        $content = $this->get(self::PAGE_URL)->getContent();

        // 弹窗模板根节点必须是 .layui-form 且带 lay-filter="importForm"
        $this->assertMatchesRegularExpression(
            '/id="importTpl"[\s\S]*?<div\s+class="layui-form"\s+lay-filter="importForm"/',
            $content,
            '批量导入弹窗模板根节点缺少 .layui-form[lay-filter=importForm] 包裹，下拉框将不被 Layui 渲染'
        );
    }

    public function test_import_dialog_contains_three_selects(): void
    {
        $this->actingAdmin();

        $content = $this->get(self::PAGE_URL)->getContent();

        // 3 个下拉框：默认动作、粘贴文本的重复处理、上传文件的重复处理
        foreach (['importAction', 'importMode', 'importFileMode'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $content, "弹窗缺少下拉框 #{$id}");
        }
    }

    public function test_render_callback_uses_correct_lay_filter(): void
    {
        $this->actingAdmin();

        $content = $this->get(self::PAGE_URL)->getContent();

        // 回调须用真实 lay-filter 值 importForm，而非模板 ID importTpl
        $this->assertStringContainsString("form.render(null, 'importForm')", $content);
        $this->assertStringNotContainsString("form.render(null, 'importTpl')", $content, '不得再用模板 ID 作为 lay-filter（会空转）');
    }

    public function test_import_dialog_wrapped_in_verbatim(): void
    {
        $this->actingAdmin();

        $content = $this->get(self::PAGE_URL)->getContent();

        // text/html 模板块必须位于 @verbatim ... @endverbatim 之间（编译后 verbatim 内容原样保留）
        $this->assertMatchesRegularExpression(
            '/<script type="text\/html" id="importTpl">[\s\S]*?<\/script>/',
            $content,
            '批量导入模板块结构异常'
        );
    }
}
