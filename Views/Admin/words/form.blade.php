<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>词条表单</title>
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
        /* 内容区：总高度减去底部按钮栏高度，内容超出时自身滚动 */
        .layui-form > .layui-row { height: calc(100vh - 52px); overflow-y: auto; padding: 20px 15px; margin: 0; }
        /* 底部按钮栏：固定 52px，始终贴底不参与滚动（不加 padding，内边距由 button-container 控制） */
        .bottom { height: 52px; background: #fff; border-top: 1px solid #e6e6e6; text-align: right; }
        .bottom .button-container { display: inline-block; padding: 10px 15px; }
        .bottom .button-container button { margin-left: 10px; }
    </style>
</head>
<body>
<form class="layui-form" lay-filter="wordForm" id="wordForm">
    <input type="hidden" id="id" name="id" value="{{ $id }}">
    <div class="layui-row">
        <div class="layui-col-md12">
            <div class="layui-form-item">
                <label class="layui-form-label">敏感词</label>
                <div class="layui-input-block">
                    <input type="text" id="word" name="word" lay-verify="required" placeholder="请输入敏感词，如：赌博" class="layui-input" autocomplete="off" maxlength="191">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">所属分类</label>
                <div class="layui-input-block">
                    <select name="category_id" id="category_id">
                        <option value="0">未分类</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">处理动作</label>
                <div class="layui-input-block">
                    <select name="action" id="action">
                        <option value="">跟随分类/全局（推荐）</option>
                        <option value="block">禁止（拦截并返回错误）</option>
                        <option value="replace">替换（用掩码字符替换）</option>
                        <option value="warn">提醒（放行但记录日志）</option>
                    </select>
                    <div class="layui-form-mid layui-word-aux">留空则依次回落到「分类默认动作 → 全局默认动作」</div>
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">敏感级别</label>
                <div class="layui-input-block">
                    <select name="level" id="level">
                        <option value="1">低</option>
                        <option value="2">中</option>
                        <option value="3">高</option>
                    </select>
                    <div class="layui-form-mid layui-word-aux">级别供调用方差异化处理，不直接影响动作</div>
                </div>
            </div>
            <div class="layui-form-item layui-form-text">
                <label class="layui-form-label">备注</label>
                <div class="layui-input-block">
                    <textarea name="remark" id="remark" placeholder="可选，说明该词的来源或处理原因" class="layui-textarea" maxlength="255"></textarea>
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">状态</label>
                <div class="layui-input-block">
                    <input type="checkbox" name="status" id="status" lay-skin="switch" lay-text="启用|禁用" checked>
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
    var base = '/admin/cmspro/sensitivewords/api/words';

    // 编辑：回填数据
    if (isEdit) {
        $.ajax({
            url: base + '/' + editId, type: 'GET', dataType: 'json',
            success: function (res) {
                if (res.code === 0 && res.data) {
                    var d = res.data;
                    $('#word').val(d.word || '');
                    $('#category_id').val(String(d.category_id || 0));
                    $('#action').val(d.action || '');
                    $('#level').val(String(d.level || 1));
                    $('#remark').val(d.remark || '');
                    $('#status').prop('checked', d.status === 1);
                    form.render();
                } else {
                    layer.msg(res.message || '词条不存在', { icon: 2 });
                }
            },
            error: function () { layer.msg('加载失败', { icon: 2 }); }
        });
    }

    // 提交
    form.on('submit(save)', function (data) {
        var field = data.field;
        var payload = {
            word: field.word,
            category_id: parseInt(field.category_id || '0', 10),
            action: field.action || '',
            level: parseInt(field.level || '1', 10),
            remark: field.remark || '',
            status: field.status ? 1 : 0
        };
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
                        if (parent.layui && parent.layui.table) parent.layui.table.reload('wordTable');
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
