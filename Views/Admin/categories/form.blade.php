<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>分类表单</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    @include('admin.partials.permission-script')
    <style>
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; }
        *, *::before, *::after { box-sizing: border-box; }
        .layui-form { height: 100%; }
        .layui-form > .layui-row { height: calc(100vh - 52px); overflow-y: auto; padding: 20px 15px; margin: 0; }
        .bottom { height: 52px; background: #fff; border-top: 1px solid #e6e6e6; text-align: right; }
        .bottom .button-container { display: inline-block; padding: 10px 15px; }
        .bottom .button-container button { margin-left: 10px; }
    </style>
</head>
<body>
<form class="layui-form" lay-filter="categoryForm" id="categoryForm">
    <input type="hidden" id="id" name="id" value="{{ $id }}">
    <div class="layui-row">
        <div class="layui-col-md12">
            <div class="layui-form-item">
                <label class="layui-form-label">分类名称</label>
                <div class="layui-input-block">
                    <input type="text" id="name" name="name" lay-verify="required" placeholder="如：广告推广" class="layui-input" autocomplete="off" maxlength="50" value="{{ $category->name ?? '' }}">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">分类标识</label>
                <div class="layui-input-block">
                    <input type="text" id="code" name="code" placeholder="小写字母/数字/下划线，留空自动生成" class="layui-input" autocomplete="off" maxlength="50" value="{{ $category->code ?? '' }}" {{ ($category && $category->is_system === 1) ? 'readonly style=background:#f2f2f2;' : '' }}>
                    <div class="layui-form-mid layui-word-aux">系统内置分类的标识不可修改（在线词库归类依赖它）</div>
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">默认动作</label>
                <div class="layui-input-block">
                    <select name="default_action" id="default_action">
                        <option value="">跟随全局默认动作</option>
                        <option value="block">禁止</option>
                        <option value="replace">替换</option>
                        <option value="warn">提醒</option>
                    </select>
                    <div class="layui-form-mid layui-word-aux">该分类下未单独指定动作的词条将采用此动作</div>
                </div>
            </div>
            <div class="layui-form-item layui-form-text">
                <label class="layui-form-label">分类说明</label>
                <div class="layui-input-block">
                    <textarea name="description" id="description" placeholder="可选" class="layui-textarea" maxlength="255">{{ $category->description ?? '' }}</textarea>
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">排序</label>
                <div class="layui-input-block">
                    <input type="number" id="sort" name="sort" placeholder="越小越靠前" class="layui-input" autocomplete="off" value="{{ $category->sort ?? 0 }}">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">状态</label>
                <div class="layui-input-block">
                    <input type="checkbox" name="status" id="status" lay-skin="switch" lay-text="启用|禁用" {{ (!$category || $category->status->value === 1) ? 'checked' : '' }}>
                </div>
            </div>
        </div>
    </div>
    <div class="bottom">
        <div class="button-container">
            <button type="submit" class="layui-btn layui-btn-normal layui-btn-sm" lay-submit lay-filter="save">
                <i class="layui-icon layui-icon-ok"></i> 提交
            </button>
            <button type="reset" class="layui-btn layui-btn-primary layui-btn-sm">
                <i class="layui-icon layui-icon-refresh"></i> 清空
            </button>
        </div>
    </div>
</form>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
layui.use(['form', 'layer', 'jquery'], function () {
    var form = layui.form, layer = layui.layer, $ = layui.jquery;

    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' } });

    var editId = parseInt(new URLSearchParams(window.location.search).get('id') || '0', 10);
    var isEdit = editId > 0;
    var base = '/admin/cmspro/sensitivewords/api/categories';

    // 服务端已渲染默认值，编辑态回填下拉选中项
    @if ($category)
    $('#default_action').val('{{ $category->default_action }}');
    @endif
    form.render();

    form.on('submit(save)', function (data) {
        var field = data.field;
        var payload = {
            name: field.name,
            default_action: field.default_action || '',
            description: field.description || '',
            sort: parseInt(field.sort || '0', 10),
            status: field.status ? 1 : 0
        };
        // code 仅新增或自建分类可改；系统内置分类不提交 code，避免被误改
        var codeField = field.code;
        if (codeField && !$('#code').prop('readonly')) payload.code = codeField;

        var url = isEdit ? base + '/' + editId : base;
        var method = isEdit ? 'PUT' : 'POST';

        $.ajax({
            url: url, type: method, dataType: 'json',
            contentType: 'application/json', data: JSON.stringify(payload),
            success: function (res) {
                if (res.code === 0) {
                    layer.msg(isEdit ? '编辑成功' : '新增成功', { icon: 1, time: 800 }, function () {
                        var index = parent.layer.getFrameIndex(window.name);
                        parent.layer.close(index);
                        if (parent.layui && parent.layui.table) parent.layui.table.reload('categoryTable');
                    });
                } else {
                    layer.msg(res.message || '操作失败', { icon: 2, time: 2000 });
                }
            },
            error: function (xhr) {
                var msg = '请求失败';
                try { msg = (JSON.parse(xhr.responseText) || {}).message || msg; } catch (e) {}
                layer.msg(msg, { icon: 2, time: 2000 });
            }
        });
        return false;
    });
});
</script>
</body>
</html>
