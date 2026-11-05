<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>命中日志</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    @include('admin.partials.permission-script')
    <style>
        .stat-cards { display: flex; gap: 15px; margin-bottom: 15px; flex-wrap: wrap; }
        .stat-card { flex: 1; min-width: 150px; background: #fff; border-radius: 4px; padding: 15px 20px; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
        .stat-card .num { font-size: 26px; font-weight: 600; color: #1e9fff; }
        .stat-card .label { color: #999; font-size: 13px; margin-top: 4px; }
        .stat-card.warn .num { color: #ffb800; }
        .stat-card.danger .num { color: #ff5722; }
    </style>
</head>
<body>
<!-- 概览卡片 -->
<div class="stat-cards">
    <div class="stat-card"><div class="num" id="statTotal">{{ $stats['total'] }}</div><div class="label">累计命中</div></div>
    <div class="stat-card warn"><div class="num" id="statToday">{{ $stats['today'] }}</div><div class="label">今日命中</div></div>
    <div class="stat-card danger"><div class="num" id="statBlock">{{ $stats['block'] }}</div><div class="label">拦截次数</div></div>
    <div class="stat-card"><div class="num" id="statWord">{{ $stats['word_enabled'] }}</div><div class="label">生效词条</div></div>
</div>

<!-- 搜索卡 -->
<div class="layui-card">
    <div class="layui-card-body">
        <form class="layui-form" id="searchForm">
            <div class="layui-form-item layui-row layui-col-space15">
                <div class="layui-col-md2">
                    <label class="layui-form-label">关键词</label>
                    <div class="layui-input-block">
                        <input type="text" name="keyword" placeholder="命中词/原文" class="layui-input" autocomplete="off">
                    </div>
                </div>
                <div class="layui-col-md2">
                    <label class="layui-form-label">来源应用</label>
                    <div class="layui-input-block">
                        <input type="text" name="source_app" placeholder="如 cmspro.blog" class="layui-input" autocomplete="off">
                    </div>
                </div>
                <div class="layui-col-md2">
                    <label class="layui-form-label">动作</label>
                    <div class="layui-input-block">
                        <select name="action">
                            <option value="">全部</option>
                            <option value="block">禁止</option>
                            <option value="replace">替换</option>
                            <option value="warn">提醒</option>
                        </select>
                    </div>
                </div>
                <div class="layui-col-md2">
                    <label class="layui-form-label">开始日期</label>
                    <div class="layui-input-block">
                        <input type="text" name="start_date" id="startDate" placeholder="yyyy-MM-dd" class="layui-input" autocomplete="off">
                    </div>
                </div>
                <div class="layui-col-md2">
                    <label class="layui-form-label">结束日期</label>
                    <div class="layui-input-block">
                        <input type="text" name="end_date" id="endDate" placeholder="yyyy-MM-dd" class="layui-input" autocomplete="off">
                    </div>
                </div>
                <div class="layui-col-md2" style="text-align:right; padding-top:5px;">
                    <button type="button" class="layui-btn" id="btnSearch"><i class="fa fa-search"></i> 查询</button>
                    <button type="reset" class="layui-btn layui-btn-primary" id="btnReset">重置</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- 表格卡 -->
<div class="layui-card">
    <div class="layui-card-body">
        <table id="logTable" lay-filter="logTable"></table>
    </div>
</div>

<script type="text/html" id="toolbarTpl">
@verbatim
<div class="layui-btn-container">
    <button class="layui-btn layui-btn-warm" lay-event="cleanExpired" data-permission="cmspro.sensitivewords.log.delete">
        <i class="fa fa-trash-o"></i> 清理超期日志
    </button>
    <button class="layui-btn layui-btn-danger" lay-event="truncate" data-permission="cmspro.sensitivewords.log.delete">
        <i class="fa fa-exclamation-triangle"></i> 清空全部
    </button>
</div>
@endverbatim
</script>

<script type="text/html" id="actionTpl">
@verbatim
{{# if(d.action === 'block'){ }}
<span class="layui-badge">禁止</span>
{{# } else if(d.action === 'replace'){ }}
<span class="layui-badge layui-bg-blue">替换</span>
{{# } else if(d.action === 'warn'){ }}
<span class="layui-badge layui-bg-orange">提醒</span>
{{# } else { }}
<span class="layui-badge layui-bg-gray">{{ d.action }}</span>
{{# } }}
@endverbatim
</script>

<script type="text/html" id="contentTpl">
@verbatim
<span title="{{ d.content }}">{{ (d.content || '').substring(0, 40) }}{{ (d.content && d.content.length > 40) ? '...' : '' }}</span>
@endverbatim
</script>

<script type="text/html" id="operationTpl">
@verbatim
<a class="layui-btn layui-btn-xs layui-btn-primary" lay-event="detail">
    <i class="fa fa-eye"></i> 详情
</a>
<a class="layui-btn layui-btn-danger layui-btn-xs" lay-event="del" data-permission="cmspro.sensitivewords.log.delete">
    <i class="layui-icon layui-icon-delete"></i>
</a>
@endverbatim
</script>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
layui.use(['table', 'form', 'layer', 'laydate', 'jquery'], function () {
    var table = layui.table, form = layui.form, layer = layui.layer, laydate = layui.laydate, $ = layui.jquery;

    var API = {
        list: '/admin/cmspro/sensitivewords/api/hit-logs',
        del: function (id) { return '/admin/cmspro/sensitivewords/api/hit-logs/' + id; },
        cleanExpired: '/admin/cmspro/sensitivewords/api/hit-logs/clean-expired',
        truncate: '/admin/cmspro/sensitivewords/api/hit-logs/truncate'
    };

    form.render();
    laydate.render({ elem: '#startDate' });
    laydate.render({ elem: '#endDate' });

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        statusCode: { 401: function () { layer.msg('登录已过期', { icon: 2 }, function () { top.location.href = '/admin/login'; }); } }
    });

    $('[data-permission]').each(function () {
        var code = $(this).data('permission');
        if (typeof window.hasPermission === 'function' && !window.hasPermission(code)) $(this).remove();
    });

    table.render({
        elem: '#logTable', url: API.list, method: 'get',
        toolbar: '#toolbarTpl', defaultToolbar: ['filter'], skin: false, even: true,
        page: { curr: 1, limit: 15, limits: [15, 30, 50, 100], layout: ['count', 'prev', 'page', 'next', 'limit', 'skip'], groups: 5 },
        cols: [[
            { title: 'ID', field: 'id', width: 80, align: 'center' },
            { title: '命中词', field: 'words', minWidth: 160 },
            { title: '动作', field: 'action', width: 80, align: 'center', templet: '#actionTpl' },
            { title: '来源应用', field: 'source_app', width: 140 },
            { title: '场景', field: 'scene', width: 130 },
            { title: '原文快照', field: 'content', minWidth: 220, templet: '#contentTpl' },
            { title: 'IP', field: 'ip', width: 130 },
            { title: '命中时间', field: 'create_time', width: 170, align: 'center' },
            { title: '操作', toolbar: '#operationTpl', width: 130, align: 'center', fixed: 'right' }
        ]],
        parseData: function (res) {
            return { code: res.code === 0 ? 0 : res.code, msg: res.msg || res.message, count: res.count, data: res.data };
        }
    });

    $('#btnSearch').on('click', function () {
        var data = {};
        $('#searchForm').serializeArray().forEach(function (item) { if (item.value !== '') data[item.name] = item.value; });
        table.reload('logTable', { where: data, page: { curr: 1 } });
    });

    $('#btnReset').on('click', function () {
        setTimeout(function () { table.reload('logTable', { where: {}, page: { curr: 1 } }); }, 30);
    });

    table.on('toolbar(logTable)', function (obj) {
        if (obj.event === 'cleanExpired') {
            layer.confirm('按「日志保留天数」配置清理超期日志，确认继续？', { icon: 3 }, function (idx) {
                layer.close(idx);
                $.post(API.cleanExpired, {}, function (res) {
                    if (res.code === 0) { layer.msg(res.message, { icon: 1 }); reloadAll(); }
                    else layer.msg(res.message, { icon: 2 });
                }).fail(function () { layer.msg('请求失败', { icon: 2 }); });
            });
        } else if (obj.event === 'truncate') {
            layer.confirm('<span style="color:#ff5722;">将清空全部命中日志，不可恢复！</span>确认继续？', { icon: 3, title: '高危操作' }, function (idx) {
                layer.close(idx);
                $.post(API.truncate, { confirm: '1' }, function (res) {
                    if (res.code === 0) { layer.msg(res.message, { icon: 1 }); reloadAll(); }
                    else layer.msg(res.message, { icon: 2 });
                }).fail(function () { layer.msg('请求失败', { icon: 2 }); });
            });
        }
    });

    table.on('tool(logTable)', function (obj) {
        var d = obj.data;
        if (obj.event === 'detail') {
            layer.open({
                type: 1, title: '命中详情 #' + d.id, area: ['640px', '480px'], shadeClose: true,
                content: '<div style="padding:20px;line-height:1.9;">'
                    + '<p><b>命中词：</b>' + (d.words || '-') + '</p>'
                    + '<p><b>动作：</b>' + (d.action || '-') + '</p>'
                    + '<p><b>来源应用：</b>' + (d.source_app || '-') + '</p>'
                    + '<p><b>业务场景：</b>' + (d.scene || '-') + '</p>'
                    + '<p><b>请求路径：</b>' + (d.request_path || '-') + '</p>'
                    + '<p><b>IP：</b>' + (d.ip || '-') + '　<b>用户ID：</b>' + (d.user_id || 0) + '　<b>管理员ID：</b>' + (d.admin_id || 0) + '</p>'
                    + '<p><b>命中时间：</b>' + (d.create_time || '-') + '</p>'
                    + '<p><b>原文快照：</b></p>'
                    + '<pre style="background:#f5f5f5;padding:12px;border-radius:4px;white-space:pre-wrap;word-break:break-all;">' + $('<div>').text(d.content || '(无)').html() + '</pre>'
                    + '</div>'
            });
        } else if (obj.event === 'del') {
            layer.confirm('确认删除该条日志？', { icon: 3 }, function (idx) {
                layer.close(idx);
                $.ajax({
                    url: API.del(d.id), type: 'DELETE',
                    success: function (res) {
                        if (res.code === 0) { layer.msg(res.message, { icon: 1 }); obj.del(); }
                        else layer.msg(res.message, { icon: 2 });
                    },
                    error: function () { layer.msg('请求失败', { icon: 2 }); }
                });
            });
        }
    });

    function reloadAll() {
        table.reload('logTable');
        $.get('/admin/cmspro/sensitivewords/api/hit-logs/stats', function (res) {
            if (res.code === 0 && res.data) {
                $('#statTotal').text(res.data.total);
                $('#statToday').text(res.data.today);
                $('#statBlock').text(res.data.block);
                $('#statWord').text(res.data.word_enabled);
            }
        });
    }
});
</script>
</body>
</html>
