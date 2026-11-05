<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>词库维护</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    @include('admin.partials.permission-script')
    <style>
        .lib-cards { display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px; }
        .lib-card { flex: 1; min-width: 160px; background: #fff; border-radius: 4px; padding: 16px 20px; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
        .lib-card .num { font-size: 24px; font-weight: 600; color: #1e9fff; }
        .lib-card .label { color: #999; font-size: 13px; margin-top: 4px; }
        .panel-row { display: flex; gap: 15px; flex-wrap: wrap; }
        .panel-col { flex: 1; min-width: 320px; }
    </style>
</head>
<body>
<div class="pear-container">
    <!-- 词库概览 -->
    <div class="lib-cards">
        <div class="lib-card"><div class="num" id="totalCount">{{ $info['total'] }}</div><div class="label">词条总数</div></div>
        <div class="lib-card"><div class="num" id="builtinCount">{{ $info['by_source']['builtin'] }}</div><div class="label">内置词库</div></div>
        <div class="lib-card"><div class="num" id="libraryCount">{{ $info['by_source']['library'] }}</div><div class="label">在线词库</div></div>
        <div class="lib-card"><div class="num" id="manualCount">{{ $info['by_source']['manual'] }}</div><div class="label">手工词条</div></div>
        <div class="lib-card"><div class="num" id="importCount">{{ $info['by_source']['import'] }}</div><div class="label">导入词条</div></div>
    </div>

    <div class="panel-row">
        <!-- 在线词库更新 -->
        <div class="layui-card panel-col">
            <div class="layui-card-header"><i class="fa fa-cloud-download"></i> 在线词库更新</div>
            <div class="layui-card-body">
                <blockquote class="layui-elem-quote layui-quote-nm" style="font-size:13px;">
                    官方词库由版本服务（Versionsvr）统一发布，各站点在线检查并同步，与敏感词应用发版解耦。
                    <br>服务地址：<code>{{ $info['api_base'] }}</code>
                    <span class="layui-badge {{ $info['remote_enabled'] ? 'layui-bg-green' : 'layui-bg-gray' }}" style="margin-left:6px;">
                        {{ $info['remote_enabled'] ? '已启用' : '已关闭' }}
                    </span>
                </blockquote>
                <table class="layui-table" lay-size="sm">
                    <tbody>
                    <tr><td style="width:120px;color:#999;">本地版本</td><td><span id="localVersion">{{ $info['local_version'] ?: '未同步' }}</span></td></tr>
                    <tr><td style="color:#999;">本地词条</td><td><span id="localCount">{{ $info['by_source']['library'] }}</span> 条</td></tr>
                    <tr><td style="color:#999;">最新版本</td><td><span id="remoteVersion">-</span> <span id="remoteTime" style="color:#999;"></span></td></tr>
                    <tr><td style="color:#999;">最新词条</td><td><span id="remoteCount">-</span> 条</td></tr>
                    <tr><td style="color:#999;">更新方式</td><td>{{ $info['remote_mode'] === 'replace' ? '覆盖官方库' : '增量合并' }}</td></tr>
                    </tbody>
                </table>
                <div style="margin-top:12px;">
                    <button class="layui-btn layui-btn-sm layui-btn-primary" id="btnCheck" data-permission="cmspro.sensitivewords.library.update">
                        <i class="fa fa-refresh"></i> 检查更新
                    </button>
                    <button class="layui-btn layui-btn-sm layui-btn-normal" id="btnSync" data-permission="cmspro.sensitivewords.library.update">
                        <i class="fa fa-download"></i> 立即同步
                    </button>
                    <select id="syncMode" style="display:inline-block;width:130px;height:32px;border:1px solid #e6e6e6;border-radius:2px;vertical-align:middle;">
                        <option value="">按设置（{{ $info['remote_mode'] === 'replace' ? '覆盖' : '合并' }}）</option>
                        <option value="merge">增量合并</option>
                        <option value="replace">覆盖官方库</option>
                    </select>
                </div>
                <div id="remoteMsg" style="margin-top:10px;"></div>
            </div>
        </div>

        <!-- 本地词库维护 -->
        <div class="layui-card panel-col">
            <div class="layui-card-header"><i class="fa fa-database"></i> 本地词库维护</div>
            <div class="layui-card-body">
                <table class="layui-table" lay-size="sm">
                    <tbody>
                    <tr><td style="width:120px;color:#999;">内置词库</td><td>可用词条 {{ $info['builtin_count'] }} 条
                        <span class="layui-badge {{ $info['builtin_available'] ? 'layui-bg-green' : 'layui-bg-gray' }}" style="margin-left:6px;">
                            {{ $info['builtin_available'] ? '文件就绪' : '文件缺失' }}
                        </span>
                    </td></tr>
                    <tr><td style="color:#999;">分类数量</td><td>{{ $info['category_count'] }} 个</td></tr>
                    <tr><td style="color:#999;">生效词条</td><td><span id="dictCount">{{ $info['dictionary_version'] }}</span> 条（当前词典）</td></tr>
                    <tr><td style="color:#999;">词库包目录</td><td style="word-break:break-all;"><code>{{ $info['pack_dir'] }}</code>
                        <span class="layui-badge {{ $info['pack_writable'] ? 'layui-bg-green' : 'layui-bg-red' }}" style="margin-left:6px;">
                            {{ $info['pack_writable'] ? '可写' : '不可写' }}
                        </span>
                    </td></tr>
                    </tbody>
                </table>
                <div style="margin-top:12px;">
                    <button class="layui-btn layui-btn-sm" id="btnImportBuiltin" data-permission="cmspro.sensitivewords.word.import">
                        <i class="fa fa-plus"></i> 导入内置基础词库
                    </button>
                    <button class="layui-btn layui-btn-sm layui-btn-primary" id="btnRebuild" data-permission="cmspro.sensitivewords.setting.edit">
                        <i class="fa fa-cogs"></i> 重建词典缓存
                    </button>
                </div>
                <div style="margin-top:12px;border-top:1px dashed #e6e6e6;padding-top:12px;">
                    <div style="color:#999;font-size:13px;margin-bottom:8px;">按来源清理词条（不影响手工添加的词条）</div>
                    <button class="layui-btn layui-btn-sm layui-btn-danger" data-clear="library" data-permission="cmspro.sensitivewords.word.delete">
                        <i class="fa fa-trash"></i> 清空在线词库
                    </button>
                    <button class="layui-btn layui-btn-sm layui-btn-warm" data-clear="import" data-permission="cmspro.sensitivewords.word.delete">
                        <i class="fa fa-trash"></i> 清空导入词条
                    </button>
                </div>
            </div>
        </div>

        <!-- 导出词库 -->
        <div class="layui-card panel-col">
            <div class="layui-card-header">
                <i class="fa fa-share-square-o"></i> 导出词库
                <span class="layui-badge layui-bg-blue" style="margin-left:8px;">可供版本服务发布</span>
            </div>
            <div class="layui-card-body">
                <blockquote class="layui-elem-quote layui-quote-nm" style="font-size:13px;">
                    导出<b>发布包（zip）</b>后，可直接到「版本服务 → 敏感词库」页上传发布，
                    各站点即可在线同步——形成「本站整理词库 → 官方发布 → 全网同步」闭环。
                </blockquote>

                <form class="layui-form" id="exportForm" style="margin-top:10px;">
                    <div class="layui-form-item">
                        <label class="layui-form-label" style="width:80px;">导出格式</label>
                        <div class="layui-input-block" style="margin-left:110px;">
                            <input type="radio" name="format" value="zip" title="发布包 zip（推荐）" checked>
                            <input type="radio" name="format" value="txt" title="纯文本 txt">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label" style="width:80px;">词条来源</label>
                        <div class="layui-input-block" style="margin-left:110px;">
                            <input type="checkbox" name="src_manual" title="手工添加" lay-skin="primary" checked>
                            <input type="checkbox" name="src_import" title="文件导入" lay-skin="primary" checked>
                            <input type="checkbox" name="src_library" title="在线词库" lay-skin="primary" checked>
                            <input type="checkbox" name="src_builtin" title="内置词库" lay-skin="primary" checked>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label" style="width:80px;">所属分类</label>
                        <div class="layui-input-inline" style="width:180px;">
                            <select name="category_id" id="exportCategory">
                                <option value="">全部分类</option>
                            </select>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label" style="width:80px;">词条状态</label>
                        <div class="layui-input-inline" style="width:180px;">
                            <select name="status">
                                <option value="1">仅启用词条（推荐）</option>
                                <option value="">全部词条</option>
                                <option value="0">仅禁用词条</option>
                            </select>
                        </div>
                    </div>
                    <div class="layui-form-item" id="versionRow">
                        <label class="layui-form-label" style="width:80px;">版本号</label>
                        <div class="layui-input-inline" style="width:180px;">
                            <input type="text" name="version" id="exportVersion" placeholder="留空自动生成" class="layui-input" autocomplete="off" maxlength="32">
                        </div>
                        <div class="layui-form-mid layui-word-aux" id="versionHint">留空按导出时间生成 YYYYMMDDHHMMSS</div>
                    </div>
                    <div class="layui-form-item">
                        <div class="layui-input-block" style="margin-left:110px;">
                            <button type="button" class="layui-btn layui-btn-sm layui-btn-normal" id="btnExport" data-permission="cmspro.sensitivewords.word.import">
                                <i class="fa fa-download"></i> 导出词库
                            </button>
                        </div>
                    </div>
                </form>
                <div id="exportMsg" style="font-size:13px;color:#999;"></div>
            </div>
        </div>
    </div>
</div>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
layui.use(['layer', 'form', 'jquery'], function () {
    var layer = layui.layer, form = layui.form, $ = layui.jquery;

    var API = {
        info: '/admin/cmspro/sensitivewords/api/library/info',
        check: '/admin/cmspro/sensitivewords/api/library/check-remote',
        sync: '/admin/cmspro/sensitivewords/api/library/sync-remote',
        importBuiltin: '/admin/cmspro/sensitivewords/api/library/import-builtin',
        clearSource: '/admin/cmspro/sensitivewords/api/library/clear-source',
        rebuild: '/admin/cmspro/sensitivewords/api/settings/rebuild-dictionary',
        exportUrl: '/admin/cmspro/sensitivewords/api/library/export',
        categoryOptions: '/admin/cmspro/sensitivewords/api/category-options'
    };

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        statusCode: { 401: function () { layer.msg('登录已过期', { icon: 2 }, function () { top.location.href = '/admin/login'; }); } }
    });

    $('[data-permission]').each(function () {
        var code = $(this).data('permission');
        if (typeof window.hasPermission === 'function' && !window.hasPermission(code)) $(this).hide();
    });

    function refreshInfo() {
        $.get(API.info, function (res) {
            if (res.code !== 0 || !res.data) return;
            var d = res.data;
            $('#totalCount').text(d.total);
            $('#builtinCount').text(d.by_source.builtin);
            $('#libraryCount').text(d.by_source.library);
            $('#manualCount').text(d.by_source.manual);
            $('#importCount').text(d.by_source.import);
            $('#localVersion').text(d.local_version || '未同步');
            $('#localCount').text(d.by_source.library);
            $('#dictCount').text(d.dictionary_version);
        });
    }

    // 检查更新
    $('#btnCheck').on('click', function () {
        var loadIdx = layer.load();
        $.get(API.check, function (res) {
            layer.close(loadIdx);
            if (res.code !== 0) { $('#remoteMsg').html('<span style="color:#ff5722;">' + res.message + '</span>'); return; }
            var d = res.data;
            if (!d.available) {
                $('#remoteVersion').text('-'); $('#remoteCount').text('-');
                $('#remoteMsg').html('<span style="color:#ff9900;"><i class="fa fa-info-circle"></i> ' + (d.message || '版本服务不可用') + '</span>');
                return;
            }
            $('#remoteVersion').text(d.version);
            $('#remoteTime').text(d.release_time ? '（' + d.release_time + '）' : '');
            $('#remoteCount').text(d.word_count);
            if (d.need_update) {
                $('#remoteMsg').html('<span style="color:#1e9fff;"><i class="fa fa-arrow-up"></i> 发现新版本，可点击「立即同步」更新</span>');
            } else {
                $('#remoteMsg').html('<span style="color:#5fb878;"><i class="fa fa-check"></i> 本地已是最新版本</span>');
            }
        }).fail(function () { layer.close(loadIdx); $('#remoteMsg').html('<span style="color:#ff5722;">请求失败</span>'); });
    });

    // 立即同步
    $('#btnSync').on('click', function () {
        var mode = $('#syncMode').val();
        var tip = mode === 'replace'
            ? '覆盖模式将先清除现有「官方在线词库」词条再导入（手工词条不受影响），确认继续？'
            : '增量合并模式只新增缺失词条，保留本地已有词条，确认继续？';
        layer.confirm(tip, { icon: 3, title: '同步官方词库' }, function (idx) {
            layer.close(idx);
            var loadIdx = layer.load(1, { content: '正在下载并导入词库...' });
            var payload = {};
            if (mode) payload.mode = mode;
            $.ajax({
                url: API.sync, type: 'POST', data: payload,
                success: function (res) {
                    layer.close(loadIdx);
                    if (res.code === 0) {
                        layer.msg(res.message, { icon: 1, time: 2500 });
                        refreshInfo();
                        $('#localVersion').text(res.data.version);
                    } else {
                        layer.alert(res.message, { icon: 2, title: '同步失败' });
                    }
                },
                error: function (xhr) {
                    layer.close(loadIdx);
                    var msg = '请求失败'; try { msg = (JSON.parse(xhr.responseText) || {}).message || msg; } catch (e) {}
                    layer.alert(msg, { icon: 2, title: '同步失败' });
                }
            });
        });
    });

    // 导入内置基础词库
    $('#btnImportBuiltin').on('click', function () {
        layer.confirm('导入应用自带的内置基础词库（已存在的词条自动跳过），确认继续？', { icon: 3 }, function (idx) {
            layer.close(idx);
            var loadIdx = layer.load();
            $.post(API.importBuiltin, {}, function (res) {
                layer.close(loadIdx);
                if (res.code === 0) { layer.msg(res.message, { icon: 1 }); refreshInfo(); }
                else layer.msg(res.message, { icon: 2 });
            }).fail(function () { layer.close(loadIdx); layer.msg('请求失败', { icon: 2 }); });
        });
    });

    // 重建词典缓存
    $('#btnRebuild').on('click', function () {
        var loadIdx = layer.load();
        $.post(API.rebuild, {}, function (res) {
            layer.close(loadIdx);
            if (res.code === 0) { layer.msg(res.message, { icon: 1 }); refreshInfo(); }
            else layer.msg(res.message, { icon: 2 });
        }).fail(function () { layer.close(loadIdx); layer.msg('请求失败', { icon: 2 }); });
    });

    // 按来源清理
    $('[data-clear]').on('click', function () {
        var source = $(this).data('clear');
        var labels = { library: '官方在线词库', import: '文件导入', builtin: '内置基础词库' };
        layer.confirm('<span style="color:#ff5722;">将删除全部「' + labels[source] + '」词条，不可恢复！</span>确认继续？', { icon: 3, title: '高危操作' }, function (idx) {
            layer.close(idx);
            var loadIdx = layer.load();
            $.post(API.clearSource, { source: source, confirm: '1' }, function (res) {
                layer.close(loadIdx);
                if (res.code === 0) { layer.msg(res.message, { icon: 1 }); refreshInfo(); }
                else layer.msg(res.message, { icon: 2 });
            }).fail(function () { layer.close(loadIdx); layer.msg('请求失败', { icon: 2 }); });
        });
    });

    // ── 导出词库 ──────────────────────────────────────────────
    // 加载分类下拉
    $.get(API.categoryOptions, function (res) {
        if (res.code === 0 && res.data) {
            var html = '<option value="">全部分类</option>';
            res.data.forEach(function (c) {
                html += '<option value="' + c.id + '">' + c.name + '</option>';
            });
            $('#exportCategory').html(html);
            form.render('select');
        }
    });

    // 格式切换：txt 无版本号概念，隐藏版本行
    form.on('radio()', function () {
        var fmt = $('#exportForm input[name="format"]:checked').val();
        if (fmt === 'txt') { $('#versionRow').hide(); } else { $('#versionRow').show(); }
    });

    $('#btnExport').on('click', function () {
        // 收集勾选的来源（至少一个）
        var sources = [];
        ['manual', 'import', 'library', 'builtin'].forEach(function (s) {
            if ($('#exportForm input[name="src_' + s + '"]').prop('checked')) sources.push(s);
        });
        if (sources.length === 0) {
            layer.msg('请至少勾选一个词条来源', { icon: 2 });
            return;
        }

        var fmt = $('#exportForm input[name="format"]:checked').val() || 'zip';
        var params = {
            format: fmt,
            sources: sources.join(','),
            category_id: $('#exportForm select[name="category_id"]').val() || '',
            status: $('#exportForm select[name="status"]').val() || ''
        };
        if (fmt === 'zip') {
            var v = $.trim($('#exportVersion').val());
            if (v) params.version = v;
        }

        // 组装查询串，用隐藏 iframe 触发下载（避免整页跳转）
        var qs = $.map(params, function (val, key) {
            return val === '' ? null : encodeURIComponent(key) + '=' + encodeURIComponent(val);
        }).join('&');

        $('#exportMsg').html('<i class="fa fa-spinner fa-spin"></i> 正在导出（' + sources.length + ' 个来源，' + fmt + ' 格式）...');

        var $iframe = $('<iframe style="display:none;"></iframe>');
        $iframe.attr('src', API.exportUrl + '?' + qs);
        $('body').append($iframe);

        // 下载为浏览器行为，无回调；提示用户稍候并说明发布包用途
        setTimeout(function () {
            $('#exportMsg').html(fmt === 'zip'
                ? '已触发下载：发布包 zip（含 words.txt + meta.json），可到「版本服务 → 敏感词库」页上传发布。'
                : '已触发下载：纯文本词库 txt，可直接导入本应用或其他站点。');
            $iframe.remove();
        }, 1500);
    });

    form.render();
});
</script>
</body>
</html>
