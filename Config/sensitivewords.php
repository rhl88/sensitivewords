<?php

/**
 * 敏感词过滤应用 · 默认配置
 *
 * 数据库 config_items 无记录时的兜底值（ServiceProvider::register 合并到
 * config('apps.cmspro.sensitivewords.*')）。所有键与 manifest.json 的
 * config_groups 一一对应，值类型统一为字符串。
 */

return [
    // 过滤设置
    'filter_enabled' => '1',
    'default_action' => 'replace',
    'replacement' => '*',
    'ignore_case' => '1',
    'skip_symbols' => '1',
    'full_width_to_half' => '1',

    // 日志设置
    'log_enabled' => '1',
    'log_content_length' => '200',
    'log_keep_days' => '90',
    'stat_hit_count' => '1',

    // 钩子接入设置
    'hook_content_filter' => '0',
    'hook_user_register' => '0',

    // 词库设置
    'remote_update_enabled' => '1',
    'remote_update_mode' => 'merge',
    'library_category_id' => '0',

    // 本地已同步的官方词库版本号（系统自动维护，不在 manifest 中声明为可编辑项）
    'library_version' => '',
];
