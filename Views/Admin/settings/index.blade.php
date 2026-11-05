<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>过滤设置</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    @include('admin.partials.permission-script')
    <style>
        /* 固定内容区高度，避免切换 Tab 时页面高度跳动 */
        .layui-tab-content { min-height: 360px; padding: 22px 12px 12px; }
        /* Tab 标题：图标与文字间距 */
        .layui-tab-title li i { margin-right: 5px; }
        /* 面板内的分组说明 */
        .panel-tip { font-size: 13px; margin-bottom: 16px; }
        /* 底部操作栏：与 Tab 内容分隔，始终可见 */
        .setting-footer { border-top: 1px solid #eee; padding-top: 16px; margin-top: 6px; }
    </style>
</head>
<body>
<div class="pear-container">
    <div class="layui-card">
        <div class="layui-card-header">
            <i class="fa fa-cog"></i> 过滤设置
            <span class="layui-badge layui-bg-blue" style="margin-left:8px;">
                生效词条 <span id="runtimeWordTotal">{{ $stats['word_enabled'] }}</span>
            </span>
            <span class="layui-badge layui-bg-green" id="runtimeEnabled" style="margin-left:6px;display:none;">过滤已启用</span>
            <span class="layui-badge layui-bg-gray" id="runtimeDisabled" style="margin-left:6px;display:none;">过滤已关闭</span>
        </div>
        <div class="layui-card-body">
            {{-- 单一 form 包裹全部 Tab 面板：隐藏面板的字段仍在 DOM 中，保存时统一收集提交 --}}
            <form class="layui-form" id="settingForm" lay-filter="settingForm">

                <div class="layui-tab layui-tab-brief" lay-filter="settingTab">
                    <ul class="layui-tab-title">
                        <li class="layui-this"><i class="fa fa-filter"></i>过滤设置</li>
                        <li><i class="fa fa-history"></i>日志设置</li>
                        <li><i class="fa fa-plug"></i>钩子接入</li>
                        <li><i class="fa fa-database"></i>词库设置</li>
                    </ul>
                    <div class="layui-tab-content">

                        {{-- ══ Tab 1：过滤设置 ══════════════════════════════ --}}
                        <div class="layui-tab-item layui-show">
                            <blockquote class="layui-elem-quote layui-quote-nm panel-tip">
                                控制敏感词的检测行为：总开关、默认处理动作、替换掩码与字符归一化策略。
                                <b>调整匹配策略会自动重建词典缓存。</b>
                            </blockquote>
                            <div class="layui-form-item">
                                <label class="layui-form-label">过滤总开关</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="filter_enabled" lay-skin="switch" lay-text="开|关" value="1">
                                    <div class="layui-form-mid layui-word-aux">关闭后所有检测与替换直接放行原文，用于紧急排障</div>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">默认动作</label>
                                <div class="layui-input-block">
                                    <select name="default_action">
                                        <option value="replace">替换（用掩码字符替换命中词）</option>
                                        <option value="block">禁止（拦截并返回错误）</option>
                                        <option value="warn">提醒（放行原文但记录日志）</option>
                                    </select>
                                    <div class="layui-form-mid layui-word-aux">词条与分类均未指定动作时的兜底动作</div>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">替换掩码</label>
                                <div class="layui-input-inline" style="width:120px;">
                                    <input type="text" name="replacement" placeholder="*" class="layui-input" maxlength="5" autocomplete="off">
                                </div>
                                <div class="layui-form-mid layui-word-aux">按命中词长度重复，如 * → ***</div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">匹配策略</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="ignore_case" lay-skin="primary" title="忽略英文大小写" value="1">
                                    <input type="checkbox" name="full_width_to_half" lay-skin="primary" title="全角转半角" value="1">
                                    <input type="checkbox" name="skip_symbols" lay-skin="primary" title="跳过干扰字符（识别 敏★感★词）" value="1">
                                    <div class="layui-form-mid layui-word-aux">开启后可识别全角字符、大小写变体与插入干扰符的绕过写法</div>
                                </div>
                            </div>
                        </div>

                        {{-- ══ Tab 2：日志设置 ══════════════════════════════ --}}
                        <div class="layui-tab-item">
                            <blockquote class="layui-elem-quote layui-quote-nm panel-tip">
                                控制命中日志的记录范围与保留策略。日志用于事后审计与词库调优，写入失败不影响过滤主流程。
                            </blockquote>
                            <div class="layui-form-item">
                                <label class="layui-form-label">记录命中日志</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="log_enabled" lay-skin="switch" lay-text="开|关" value="1">
                                    <div class="layui-form-mid layui-word-aux">关闭后不再写入命中日志，已产生的记录仍可在「命中日志」页查询</div>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">原文快照长度</label>
                                <div class="layui-input-inline" style="width:120px;">
                                    <input type="number" name="log_content_length" placeholder="200" class="layui-input" min="0" max="2000" autocomplete="off">
                                </div>
                                <div class="layui-form-mid layui-word-aux">范围 0-2000，0 表示不保存原文</div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">日志保留天数</label>
                                <div class="layui-input-inline" style="width:120px;">
                                    <input type="number" name="log_keep_days" placeholder="90" class="layui-input" min="0" max="3650" autocomplete="off">
                                </div>
                                <div class="layui-form-mid layui-word-aux">0 表示永久保留；超期日志可在「命中日志」页清理</div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">累计命中次数</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="stat_hit_count" lay-skin="switch" lay-text="开|关" value="1">
                                    <div class="layui-form-mid layui-word-aux">每次命中回写词条命中次数，高并发站点可关闭以降低写库压力</div>
                                </div>
                            </div>
                        </div>

                        {{-- ══ Tab 3：钩子接入 ══════════════════════════════ --}}
                        <div class="layui-tab-item">
                            <blockquote class="layui-elem-quote layui-quote-nm panel-tip">
                                开启后本应用自动监听系统钩子，无需被接入方改任何代码；关闭后其他应用仍可主动调用辅助函数 / Service / 验证规则接入。
                                <b>开关保存后需刷新页面或重新进入，钩子在下次请求生效。</b>
                            </blockquote>
                            <div class="layui-form-item">
                                <label class="layui-form-label">内容模块过滤</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="hook_content_filter" lay-skin="switch" lay-text="开|关" value="1">
                                    <div class="layui-form-mid layui-word-aux">监听 content.before_create / content.before_update，过滤内容模块的标题、摘要、正文</div>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">注册信息检测</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="hook_user_register" lay-skin="switch" lay-text="开|关" value="1">
                                    <div class="layui-form-mid layui-word-aux">监听 user.before_register，命中禁止级词条时阻断注册</div>
                                </div>
                            </div>
                        </div>

                        {{-- ══ Tab 4：词库设置 ══════════════════════════════ --}}
                        <div class="layui-tab-item">
                            <blockquote class="layui-elem-quote layui-quote-nm panel-tip">
                                控制官方词库的在线更新行为。词库的导入导出与版本同步操作请前往「词库维护」页。
                            </blockquote>
                            <div class="layui-form-item">
                                <label class="layui-form-label">在线词库更新</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="remote_update_enabled" lay-skin="switch" lay-text="开|关" value="1">
                                    <div class="layui-form-mid layui-word-aux">开启后可在「词库维护」页检查并同步官方词库</div>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">在线更新方式</label>
                                <div class="layui-input-block">
                                    <select name="remote_update_mode">
                                        <option value="merge">增量合并（保留本地词条，仅新增缺失词）</option>
                                        <option value="replace">覆盖官方库（清除旧官方词条后重新导入）</option>
                                    </select>
                                    <div class="layui-form-mid layui-word-aux">两种方式均不影响手工添加与文件导入的词条</div>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">在线词库归类</label>
                                <div class="layui-input-inline" style="width:120px;">
                                    <input type="number" name="library_category_id" placeholder="0" class="layui-input" min="0" autocomplete="off">
                                </div>
                                <div class="layui-form-mid layui-word-aux">指定分类 ID，0 表示归入「官方词库」内置分类</div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">本地词库版本</label>
                                <div class="layui-input-inline" style="width:200px;">
                                    <input type="text" id="library_version" class="layui-input" readonly style="background:#f2f2f2;" value="未同步">
                                </div>
                                <div class="layui-form-mid layui-word-aux">系统自动维护，同步成功后自动更新</div>
                            </div>
                        </div>

                    </div>
                </div>

                {{-- 底部操作栏：位于 Tab 之外，在任意分页均可一次保存全部配置 --}}
                <div class="layui-form-item setting-footer">
                    <div class="layui-input-block" style="margin-left:0;">
                        <button type="submit" class="layui-btn layui-btn-normal" lay-submit lay-filter="saveSetting" data-permission="cmspro.sensitivewords.setting.edit">
                            <i class="layui-icon layui-icon-ok"></i> 保存设置
                        </button>
                        <button type="button" class="layui-btn layui-btn-primary" id="btnReload">
                            <i class="layui-icon layui-icon-refresh"></i> 还原为已保存值
                        </button>
                        <span class="layui-form-mid layui-word-aux" style="margin-left:12px;">保存将对四个分页的全部配置生效</span>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
layui.use(['form', 'layer', 'element', 'jquery'], function () {
    var form = layui.form, layer = layui.layer, element = layui.element, $ = layui.jquery;

    var API = {
        get: '/admin/cmspro/sensitivewords/api/settings',
        save: '/admin/cmspro/sensitivewords/api/settings'
    };

    // 开关/多选类配置项：值为 "1"/"0" 字符串，回填时转 checkbox 状态
    var SWITCH_KEYS = ['filter_enabled', 'log_enabled', 'stat_hit_count', 'hook_content_filter', 'remote_update_enabled'];
    var CHECK_KEYS = ['ignore_case', 'full_width_to_half', 'skip_symbols'];

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        statusCode: { 401: function () { layer.msg('登录已过期', { icon: 2 }, function () { top.location.href = '/admin/login'; }); } }
    });

    $('[data-permission]').each(function () {
        var code = $(this).data('permission');
        if (typeof window.hasPermission === 'function' && !window.hasPermission(code)) $(this).hide();
    });

    /**
     * 载入配置并回填表单。
     *
     * Tab 的非当前面板处于 display:none，但字段仍在 DOM 中，
     * 因此可一次性回填全部分页；回填后统一 form.render() 渲染组件。
     */
    function loadConfig() {
        $.get(API.get, function (res) {
            if (res.code !== 0 || !res.data) { layer.msg(res.message || '配置读取失败', { icon: 2 }); return; }
            var c = res.data.config || {};
            var runtime = res.data.runtime || {};

            if (runtime.enabled) $('#runtimeEnabled').show(); else $('#runtimeDisabled').show();
            $('#runtimeWordTotal').text(runtime.word_total || 0);

            // 文本 / 数字
            ['replacement', 'log_content_length', 'log_keep_days', 'library_category_id'].forEach(function (k) {
                if (c[k] !== undefined && c[k] !== '') $('[name="' + k + '"]').val(c[k]);
            });
            // 下拉
            ['default_action', 'remote_update_mode'].forEach(function (k) {
                if (c[k]) $('[name="' + k + '"]').val(c[k]);
            });
            // 只读展示项
            $('#library_version').val(c.library_version || '未同步');

            // 开关与多选
            SWITCH_KEYS.concat(CHECK_KEYS).forEach(function (k) {
                $('[name="' + k + '"]').prop('checked', c[k] === '1');
            });

            form.render(null, 'settingForm');
        }).fail(function () { layer.msg('配置读取失败', { icon: 2 }); });
    }

    loadConfig();

    // 切换 Tab 后重渲染当前面板：隐藏状态下渲染的 select 宽度可能异常，重渲染可修正
    element.on('tab(settingTab)', function () {
        form.render(null, 'settingForm');
    });

    // 还原为已保存值（原 type=reset 会把字段清成 HTML 初始空值，而配置由 AJAX 回填，
    // 故改为重新拉取服务端值，语义才是「放弃本次未保存的修改」）
    $('#btnReload').on('click', function () {
        loadConfig();
        layer.msg('已还原为服务器保存的配置', { icon: 1, time: 1200 });
    });

    // 保存：收集四个分页的全部字段一次性提交
    form.on('submit(saveSetting)', function () {
        var payload = {};
        SWITCH_KEYS.concat(CHECK_KEYS).forEach(function (k) {
            payload[k] = $('[name="' + k + '"]').prop('checked') ? '1' : '0';
        });
        payload.default_action = $('[name="default_action"]').val();
        payload.remote_update_mode = $('[name="remote_update_mode"]').val();
        payload.replacement = $('[name="replacement"]').val();
        payload.log_content_length = $('[name="log_content_length"]').val();
        payload.log_keep_days = $('[name="log_keep_days"]').val();
        payload.library_category_id = $('[name="library_category_id"]').val();

        var loadIdx = layer.load();
        $.ajax({
            url: API.save, type: 'POST', dataType: 'json',
            contentType: 'application/json', data: JSON.stringify(payload),
            success: function (res) {
                layer.close(loadIdx);
                if (res.code === 0) {
                    layer.msg(res.message, { icon: 1, time: 1200 }, function () { loadConfig(); });
                } else {
                    layer.msg(res.message || '保存失败', { icon: 2, time: 2500 });
                }
            },
            error: function (xhr) {
                layer.close(loadIdx);
                var msg = '请求失败';
                try {
                    var body = JSON.parse(xhr.responseText) || {};
                    msg = body.message || (body.errors ? Object.values(body.errors)[0][0] : msg);
                } catch (e) {}
                layer.msg(msg, { icon: 2, time: 2500 });
            }
        });
        return false;
    });
});
</script>
</body>
</html>
