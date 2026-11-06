<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>词条管理</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    @include('admin.partials.permission-script')
    <style>
        /* 搜索栏：inline 横排 + 固定控件宽度。
           不用栅格列（layui-col-md*）：列宽随屏幕拉伸而控件宽度固定，
           列尾会留出随屏幕变宽而增大的空白，即各筛选项之间的大间隔 */
        .sw-search-bar { padding: 10px 5px 0; }
        .sw-search-bar .layui-inline { margin: 0 14px 10px 0; vertical-align: top; }
        .sw-search-bar .layui-form-label { width: auto; padding: 9px 8px 9px 0; }
        .sw-search-bar .layui-input-inline { vertical-align: top; }
        .sw-w-keyword { width: 180px; }
        .sw-w-select { width: 130px; }
        .sw-search-actions { margin-left: 4px; }
    </style>
</head>
<body>
<!-- 搜索卡 -->
<div class="layui-card">
    <div class="layui-card-body">
        <form class="layui-form" id="searchForm">
            <div class="sw-search-bar">
                <div class="layui-inline">
                    <label class="layui-form-label">关键词</label>
                    <div class="layui-input-inline sw-w-keyword">
                        <input type="text" name="keyword" placeholder="敏感词内容" class="layui-input" autocomplete="off">
                    </div>
                </div>
                <div class="layui-inline">
                    <label class="layui-form-label">分类</label>
                    <div class="layui-input-inline sw-w-select">
                        <select name="category_id" id="searchCategory">
                            <option value="">全部</option>
                        </select>
                    </div>
                </div>
                <div class="layui-inline">
                    <label class="layui-form-label">动作</label>
                    <div class="layui-input-inline sw-w-select">
                        <select name="action">
                            <option value="">全部</option>
                            <option value="block">禁止</option>
                            <option value="replace">替换</option>
                            <option value="warn">提醒</option>
                            <option value="inherit">跟随上级</option>
                        </select>
                    </div>
                </div>
                <div class="layui-inline">
                    <label class="layui-form-label">来源</label>
                    <div class="layui-input-inline sw-w-select">
                        <select name="source">
                            <option value="">全部</option>
                            <option value="manual">手工添加</option>
                            <option value="import">文件导入</option>
                            <option value="library">在线词库</option>
                            <option value="builtin">内置词库</option>
                        </select>
                    </div>
                </div>
                <div class="layui-inline">
                    <label class="layui-form-label">状态</label>
                    <div class="layui-input-inline sw-w-select">
                        <select name="status">
                            <option value="">全部</option>
                            <option value="1">启用</option>
                            <option value="0">禁用</option>
                        </select>
                    </div>
                </div>
                <div class="layui-inline sw-search-actions">
                    <button type="button" class="layui-btn" id="btnSearch"><i class="fa fa-search"></i></button>
                    <button type="reset" class="layui-btn layui-btn-primary" id="btnReset">重置</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- 表格卡 -->
<div class="layui-card">
    <div class="layui-card-body">
        <table id="wordTable" lay-filter="wordTable"></table>
    </div>
</div>

<!-- 顶部工具栏模板 -->
<script type="text/html" id="toolbarTpl">
@verbatim
<div class="layui-btn-container">
    <button class="layui-btn layui-btn-normal" lay-event="add" data-permission="cmspro.sensitivewords.word.create">
        <i class="layui-icon layui-icon-add-1"></i> 新增词条
    </button>
    <button class="layui-btn" lay-event="import" data-permission="cmspro.sensitivewords.word.import">
        <i class="fa fa-upload"></i> 批量导入
    </button>
    <button class="layui-btn layui-btn-warm" lay-event="enable" data-permission="cmspro.sensitivewords.word.edit">
        <i class="fa fa-check"></i> 批量启用
    </button>
    <button class="layui-btn layui-btn-primary" lay-event="disable" data-permission="cmspro.sensitivewords.word.edit">
        <i class="fa fa-ban"></i> 批量禁用
    </button>
    <button class="layui-btn layui-btn-danger" lay-event="batchDelete" data-permission="cmspro.sensitivewords.word.delete">
        <i class="layui-icon layui-icon-delete"></i> 批量删除
    </button>
</div>
@endverbatim
</script>

<!-- 状态列模板 -->
<script type="text/html" id="statusTpl">
@verbatim
<input type="checkbox" name="status" value="{{d.id}}" lay-skin="switch" lay-text="启用|禁用"
       lay-filter="statusSwitch" data-id="{{d.id}}" {{ d.status === 1 ? 'checked' : '' }}>
@endverbatim
</script>

<!-- 动作列模板 -->
<script type="text/html" id="actionTpl">
@verbatim
{{# if(d.action === 'block'){ }}
<span class="layui-badge">禁止</span>
{{# } else if(d.action === 'replace'){ }}
<span class="layui-badge layui-bg-blue">替换</span>
{{# } else if(d.action === 'warn'){ }}
<span class="layui-badge layui-bg-orange">提醒</span>
{{# } else { }}
<span class="layui-badge layui-bg-gray">跟随上级</span>
{{# } }}
@endverbatim
</script>

<!-- 级别列模板 -->
<script type="text/html" id="levelTpl">
@verbatim
{{# var lv = ['','低','中','高'][d.level] || '低'; }}
{{# var cls = ['','layui-bg-gray','layui-bg-orange',''][d.level] || ''; }}
<span class="layui-badge {{cls}}">{{ lv }}</span>
@endverbatim
</script>

<!-- 来源列模板 -->
<script type="text/html" id="sourceTpl">
@verbatim
{{# var map = {manual:'手工',import:'导入',library:'在线',builtin:'内置'}; }}
{{ map[d.source] || d.source }}
@endverbatim
</script>

<!-- 操作列模板 -->
<script type="text/html" id="operationTpl">
@verbatim
<a class="layui-btn layui-btn-xs" lay-event="edit" data-permission="cmspro.sensitivewords.word.edit">编辑</a>
<a class="layui-btn layui-btn-danger layui-btn-xs" lay-event="del" data-permission="cmspro.sensitivewords.word.delete">删除</a>
@endverbatim
</script>

<!-- 批量导入弹窗模板 -->
<script type="text/html" id="importTpl">
@verbatim
<div class="layui-form" lay-filter="importForm" style="padding:20px;">
    <div class="layui-tab layui-tab-brief" lay-filter="importTab">
        <ul class="layui-tab-title">
            <li class="layui-this">粘贴文本</li>
            <li>上传文件</li>
        </ul>
        <div class="layui-tab-content">
            <div class="layui-tab-item layui-show">
                <div class="layui-form-item">
                    <label class="layui-form-label">词条内容</label>
                    <div class="layui-input-block">
                        <textarea id="importContent" placeholder="每行一个敏感词，支持「词,动作,级别,分类」格式，如：&#10;赌博&#10;代开发票,block,3,fraud" class="layui-textarea" style="min-height:160px;"></textarea>
                    </div>
                </div>
                <div class="layui-form-item">
                    <label class="layui-form-label">默认动作</label>
                    <div class="layui-input-inline">
                        <select id="importAction">
                            <option value="">跟随分类/全局</option>
                            <option value="block">禁止</option>
                            <option value="replace">替换</option>
                            <option value="warn">提醒</option>
                        </select>
                    </div>
                    <label class="layui-form-label">重复处理</label>
                    <div class="layui-input-inline">
                        <select id="importMode">
                            <option value="skip">跳过已存在</option>
                            <option value="update">补齐空字段</option>
                            <option value="overwrite">覆盖</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="layui-tab-item">
                <div class="layui-form-item">
                    <label class="layui-form-label">词库文件</label>
                    <div class="layui-input-block">
                        <input type="file" id="importFile" accept=".txt,.csv,.zip,.dic" class="layui-input" style="padding:4px;">
                        <div class="layui-form-mid layui-word-aux">支持 txt / csv / zip（zip 内需含 words.txt），≤20MB</div>
                    </div>
                </div>
                <div class="layui-form-item">
                    <label class="layui-form-label">重复处理</label>
                    <div class="layui-input-inline">
                        <select id="importFileMode">
                            <option value="skip">跳过已存在</option>
                            <option value="update">补齐空字段</option>
                            <option value="overwrite">覆盖</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endverbatim
</script>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
layui.use(['table', 'form', 'layer', 'element', 'jquery'], function () {
    var table = layui.table, form = layui.form, layer = layui.layer, element = layui.element, $ = layui.$;

    var API = {
        list: '/admin/cmspro/sensitivewords/api/words',
        store: '/admin/cmspro/sensitivewords/api/words',
        form: '/admin/cmspro/sensitivewords/words/form',
        toggle: function (id) { return '/admin/cmspro/sensitivewords/api/words/toggle-status/' + id; },
        batchStatus: '/admin/cmspro/sensitivewords/api/words/batch-status',
        batchDelete: function (id) { return '/admin/cmspro/sensitivewords/api/words/' + id; },
        exportUrl: '/admin/cmspro/sensitivewords/api/words/export',
        test: '/admin/cmspro/sensitivewords/api/words/test',
        categoryOptions: '/admin/cmspro/sensitivewords/api/category-options',
        importText: '/admin/cmspro/sensitivewords/api/words/import-text',
        importFile: '/admin/cmspro/sensitivewords/api/words/import-file'
    };

    form.render();

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        statusCode: {
            401: function () {
                layer.msg('登录已过期，请重新登录', { icon: 2, time: 1500 }, function () {
                    top.location.href = '/admin/login';
                });
            }
        }
    });

    function hasPermission(code) {
        if (typeof window.hasPermission === 'function') return window.hasPermission(code);
        if (typeof window.permissions === 'undefined' || !window.permissions) return true;
        if (window.permissions.indexOf('*') !== -1) return true;
        return window.permissions.indexOf(code) !== -1;
    }

    // 加载分类下拉
    $.get(API.categoryOptions, function (res) {
        if (res.code === 0 && res.data) {
            var html = '<option value="">全部</option>';
            res.data.forEach(function (c) {
                html += '<option value="' + c.id + '">' + c.name + '</option>';
            });
            $('#searchCategory').html(html);
            form.render('select');
        }
    });

    // 渲染表格
    table.render({
        elem: '#wordTable',
        url: API.list,
        method: 'get',
        toolbar: '#toolbarTpl',
        defaultToolbar: ['filter', 'exports', 'print'],
        skin: false,
        even: true,
        page: { curr: 1, limit: 15, limits: [15, 30, 50, 100], layout: ['count', 'prev', 'page', 'next', 'limit', 'skip'], groups: 5 },
        cols: [[
            { type: 'checkbox', fixed: 'left' },
            { title: 'ID', field: 'id', width: 70, align: 'center', sort: true },
            { title: '敏感词', field: 'word', minWidth: 140 },
            { title: '分类', field: 'category_name', width: 110 },
            { title: '动作', field: 'action', width: 90, align: 'center', templet: '#actionTpl' },
            { title: '级别', field: 'level', width: 70, align: 'center', templet: '#levelTpl' },
            { title: '来源', field: 'source', width: 80, align: 'center', templet: '#sourceTpl' },
            { title: '命中', field: 'hit_count', width: 80, align: 'center' },
            { title: '状态', field: 'status', width: 100, align: 'center', templet: '#statusTpl' },
            { title: '创建时间', field: 'create_time', width: 170, align: 'center' },
            { title: '操作', toolbar: '#operationTpl', width: 120, align: 'center', fixed: 'right' }
        ]],
        parseData: function (res) {
            return { code: res.code === 0 ? 0 : res.code, msg: res.msg || res.message, count: res.count, data: res.data };
        }
    });

    // 搜索
    $('#btnSearch').on('click', function () {
        var data = {};
        $('#searchForm').serializeArray().forEach(function (item) {
            if (item.value !== '') data[item.name] = item.value;
        });
        // 「跟随上级」用空 action 表达，特殊映射
        if (data.action === 'inherit') data.action = '';
        table.reload('wordTable', { where: data, page: { curr: 1 } });
    });

    $('#btnReset').on('click', function () {
        setTimeout(function () { table.reload('wordTable', { where: {}, page: { curr: 1 } }); }, 30);
    });

    // 状态开关（无感知更新，仅更新当前行）
    form.on('switch(statusSwitch)', function (obj) {
        var id = $(this).data('id');
        var newStatus = obj.elem.checked ? 1 : 0;
        var $tr = $(obj.elem).closest('tr');
        $.ajax({
            url: API.toggle(id), type: 'POST', data: { status: newStatus },
            success: function (res) {
                if (res.code === 0) {
                    layer.msg(newStatus === 1 ? '已启用' : '已禁用', { icon: 1, time: 800 });
                    var rowData = (table.cache['wordTable'] || []).filter(function (item) { return item.id === id; })[0];
                    if (rowData) {
                        rowData.status = newStatus;
                        table.updateRow('wordTable', { index: $tr.data('index'), data: rowData });
                    }
                } else {
                    layer.msg(res.message || '操作失败', { icon: 2 });
                    obj.elem.checked = !obj.elem.checked;
                    form.render('switch');
                }
            },
            error: function () {
                layer.msg('请求失败', { icon: 2 });
                obj.elem.checked = !obj.elem.checked;
                form.render('switch');
            }
        });
    });

    // 工具栏事件
    table.on('toolbar(wordTable)', function (obj) {
        var event = obj.event;
        if (event === 'add') {
            openForm(0);
        } else if (event === 'import') {
            openImport();
        } else if (event === 'enable' || event === 'disable') {
            var ids = getCheckedIds();
            if (ids.length === 0) { layer.msg('请先勾选词条', { icon: 2 }); return; }
            batchStatus(ids, event === 'enable' ? 1 : 0);
        } else if (event === 'batchDelete') {
            var ids = getCheckedIds();
            if (ids.length === 0) { layer.msg('请先勾选词条', { icon: 2 }); return; }
            layer.confirm('确认删除选中的 ' + ids.length + ' 个词条？删除后不可恢复。', { icon: 3, title: '批量删除' }, function (idx) {
                layer.close(idx);
                $.ajax({
                    url: API.batchDelete(ids[0]), type: 'DELETE', data: { ids: ids },
                    success: function (res) {
                        if (res.code === 0) { layer.msg(res.message, { icon: 1 }); table.reload('wordTable'); }
                        else layer.msg(res.message || '删除失败', { icon: 2 });
                    },
                    error: function () { layer.msg('请求失败', { icon: 2 }); }
                });
            });
        }
    });

    // 行操作
    table.on('tool(wordTable)', function (obj) {
        var data = obj.data;
        if (obj.event === 'edit') {
            openForm(data.id);
        } else if (obj.event === 'del') {
            layer.confirm('确认删除词条「' + data.word + '」？', { icon: 3, title: '删除' }, function (idx) {
                layer.close(idx);
                $.ajax({
                    url: API.batchDelete(data.id), type: 'DELETE',
                    success: function (res) {
                        if (res.code === 0) { layer.msg(res.message, { icon: 1 }); obj.del(); }
                        else layer.msg(res.message || '删除失败', { icon: 2 });
                    },
                    error: function () { layer.msg('请求失败', { icon: 2 }); }
                });
            });
        }
    });

    function getCheckedIds() {
        return table.checkStatus('wordTable').data.map(function (item) { return item.id; });
    }

    function batchStatus(ids, status) {
        $.ajax({
            url: API.batchStatus, type: 'POST', data: { ids: ids, status: status },
            success: function (res) {
                if (res.code === 0) { layer.msg(res.message, { icon: 1 }); table.reload('wordTable'); }
                else layer.msg(res.message || '操作失败', { icon: 2 });
            },
            error: function () { layer.msg('请求失败', { icon: 2 }); }
        });
    }

    function openForm(id) {
        layer.open({
            type: 2, title: id ? '编辑词条' : '新增词条', area: ['620px', '560px'],
            shade: [0.4, '#000'], shadeClose: false, closeBtn: 1,
            content: API.form + '?id=' + (id || 0)
        });
    }

    // 批量导入弹窗
    function openImport() {
        layer.open({
            type: 1, title: '批量导入词条', area: ['680px', '520px'],
            shade: [0.4, '#000'], closeBtn: 1, content: $('#importTpl').html(),
            btn: ['开始导入', '取消'],
            success: function (layero) {
                element.render('tab', 'importTab');
                // 模板根节点带 .layui-form[lay-filter=importForm]，
                // form.render 仅作用于 .layui-form 容器内元素，第二参数须传该 lay-filter 值
                form.render(null, 'importForm');
            },
            yes: function (index, layero) {
                var activeTab = layero.find('.layui-tab-title .layui-this').index();
                if (activeTab === 0) {
                    submitTextImport(index, layero);
                } else {
                    submitFileImport(index, layero);
                }
            }
        });
    }

    function submitTextImport(index, layero) {
        var content = layero.find('#importContent').val();
        if ($.trim(content) === '') { layer.msg('请粘贴词条内容', { icon: 2 }); return; }
        var loadIdx = layer.load();
        $.ajax({
            url: API.importText, type: 'POST',
            data: { content: content, action: layero.find('#importAction').val(), mode: layero.find('#importMode').val() },
            success: function (res) {
                layer.close(loadIdx);
                if (res.code === 0) { layer.msg(res.message, { icon: 1 }); layer.close(index); table.reload('wordTable'); }
                else layer.alert(res.message, { icon: 2, title: '导入失败' });
            },
            error: function (xhr) {
                layer.close(loadIdx);
                var msg = '请求失败'; try { msg = (JSON.parse(xhr.responseText) || {}).message || msg; } catch (e) {}
                layer.msg(msg, { icon: 2 });
            }
        });
    }

    function submitFileImport(index, layero) {
        var fileInput = layero.find('#importFile')[0];
        if (!fileInput.files || !fileInput.files[0]) { layer.msg('请选择词库文件', { icon: 2 }); return; }
        var formData = new FormData();
        formData.append('file', fileInput.files[0]);
        formData.append('mode', layero.find('#importFileMode').val());
        var loadIdx = layer.load(1, { content: '正在导入...' });
        $.ajax({
            url: API.importFile, type: 'POST', data: formData, processData: false, contentType: false,
            success: function (res) {
                layer.close(loadIdx);
                if (res.code === 0) { layer.msg(res.message, { icon: 1 }); layer.close(index); table.reload('wordTable'); }
                else layer.alert(res.message, { icon: 2, title: '导入失败' });
            },
            error: function (xhr) {
                layer.close(loadIdx);
                var msg = '请求失败'; try { msg = (JSON.parse(xhr.responseText) || {}).message || msg; } catch (e) {}
                layer.msg(msg, { icon: 2 });
            }
        });
    }
});
</script>
</body>
</html>
