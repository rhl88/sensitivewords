<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>分类管理</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    @include('admin.partials.permission-script')
</head>
<body>
<div class="layui-card">
    <div class="layui-card-header">
        <i class="fa fa-folder"></i> 词条分类
        <span class="layui-word-aux" style="margin-left:10px;font-size:12px;">分类可设定默认动作，该分类下未单独指定动作的词条将跟随分类默认动作</span>
    </div>
    <div class="layui-card-body">
        <table id="categoryTable" lay-filter="categoryTable"></table>
    </div>
</div>

<script type="text/html" id="toolbarTpl">
@verbatim
<div class="layui-btn-container">
    <button class="layui-btn layui-btn-normal" lay-event="add" data-permission="cmspro.sensitivewords.category.manage">
        <i class="layui-icon layui-icon-add-1"></i> 新增分类
    </button>
    <button class="layui-btn layui-btn-primary" lay-event="refresh">
        <i class="fa fa-refresh"></i> 刷新
    </button>
</div>
@endverbatim
</script>

<script type="text/html" id="actionTpl">
@verbatim
{{# if(d.default_action === 'block'){ }}
<span class="layui-badge">禁止</span>
{{# } else if(d.default_action === 'replace'){ }}
<span class="layui-badge layui-bg-blue">替换</span>
{{# } else if(d.default_action === 'warn'){ }}
<span class="layui-badge layui-bg-orange">提醒</span>
{{# } else { }}
<span class="layui-badge layui-bg-gray">跟随全局</span>
{{# } }}
@endverbatim
</script>

<script type="text/html" id="typeTpl">
@verbatim
{{# if(d.is_system === 1){ }}
<span class="layui-badge layui-bg-cyan">系统内置</span>
{{# } else { }}
<span class="layui-badge layui-bg-gray">自建</span>
{{# } }}
@endverbatim
</script>

<script type="text/html" id="statusTpl">
@verbatim
{{# if(d.status === 1){ }}
<span class="layui-badge layui-bg-green">启用</span>
{{# } else { }}
<span class="layui-badge layui-bg-gray">禁用</span>
{{# } }}
@endverbatim
</script>

<script type="text/html" id="operationTpl">
@verbatim
<a class="layui-btn layui-btn-xs" lay-event="edit" data-permission="cmspro.sensitivewords.category.manage">
    <i class="layui-icon layui-icon-edit"></i> 编辑
</a>
{{# if(d.is_system !== 1){ }}
<a class="layui-btn layui-btn-danger layui-btn-xs" lay-event="del" data-permission="cmspro.sensitivewords.category.manage">
    <i class="layui-icon layui-icon-delete"></i> 删除
</a>
{{# } }}
@endverbatim
</script>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
layui.use(['table', 'form', 'layer', 'jquery'], function () {
    var table = layui.table, form = layui.form, layer = layui.layer, $ = layui.jquery;

    var API = {
        list: '/admin/cmspro/sensitivewords/api/categories',
        form: '/admin/cmspro/sensitivewords/categories/form',
        del: function (id) { return '/admin/cmspro/sensitivewords/api/categories/' + id; }
    };

    form.render();

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        statusCode: { 401: function () { layer.msg('登录已过期', { icon: 2 }, function () { top.location.href = '/admin/login'; }); } }
    });

    function hasPermission(code) {
        if (typeof window.hasPermission === 'function') return window.hasPermission(code);
        if (typeof window.permissions === 'undefined' || !window.permissions) return true;
        if (window.permissions.indexOf('*') !== -1) return true;
        return window.permissions.indexOf(code) !== -1;
    }

    table.render({
        elem: '#categoryTable', url: API.list, method: 'get',
        toolbar: '#toolbarTpl', defaultToolbar: ['filter'], skin: false, even: true, page: false,
        cols: [[
            { title: 'ID', field: 'id', width: 70, align: 'center' },
            { title: '分类名称', field: 'name', minWidth: 130 },
            { title: '标识', field: 'code', minWidth: 120 },
            { title: '默认动作', field: 'default_action', width: 110, align: 'center', templet: '#actionTpl' },
            { title: '词条数', field: 'word_count', width: 90, align: 'center' },
            { title: '类型', field: 'is_system', width: 100, align: 'center', templet: '#typeTpl' },
            { title: '排序', field: 'sort', width: 70, align: 'center' },
            { title: '状态', field: 'status', width: 90, align: 'center', templet: '#statusTpl' },
            { title: '说明', field: 'description', minWidth: 160 },
            { title: '操作', toolbar: '#operationTpl', width: 150, align: 'center', fixed: 'right' }
        ]],
        parseData: function (res) {
            return { code: res.code === 0 ? 0 : res.code, msg: res.msg || res.message, count: res.count, data: res.data };
        }
    });

    table.on('toolbar(categoryTable)', function (obj) {
        if (obj.event === 'add') openForm(0);
        else if (obj.event === 'refresh') table.reload('categoryTable');
    });

    table.on('tool(categoryTable)', function (obj) {
        var data = obj.data;
        if (obj.event === 'edit') openForm(data.id);
        else if (obj.event === 'del') {
            layer.confirm('确认删除分类「' + data.name + '」？', { icon: 3, title: '删除' }, function (idx) {
                layer.close(idx);
                $.ajax({
                    url: API.del(data.id), type: 'DELETE',
                    success: function (res) {
                        if (res.code === 0) { layer.msg(res.message, { icon: 1 }); table.reload('categoryTable'); }
                        else layer.msg(res.message || '删除失败', { icon: 2 });
                    },
                    error: function () { layer.msg('请求失败', { icon: 2 }); }
                });
            });
        }
    });

    function openForm(id) {
        layer.open({
            type: 2, title: id ? '编辑分类' : '新增分类', area: ['620px', '520px'],
            shade: [0.4, '#000'], closeBtn: 1, content: API.form + '?id=' + (id || 0)
        });
    }
});
</script>
</body>
</html>
