# 敏感词过滤 应用文档

> 应用 ID：`cmspro.sensitivewords` | 文档版本：1.2.0 | 应用版本：1.2.0
> 依赖：PHP >= 8.3，CmsPro >= 5.0.0（可选弱依赖：versionsvr，用于在线词库更新与词库发布）
> 官方地址：https://www.cmspro.cn/apps/cmspro.sensitivewords

## 应用概述

敏感词过滤应用是 CmsPro 系统的通用内容安全能力，基于 DFA 字典树引擎提供敏感词**检测 / 替换 / 拦截**三种处理动作，并向任意应用开放四种扩展接入方式：

1. **全局辅助函数**：`cmspro_sensitivewords_filter()` / `cmspro_sensitivewords_check()`（能力不可用时自动放行，真正弱依赖）
2. **验证规则**：字符串规则 `sensitive_word` 与对象规则 `SensitiveWordRule`，表单校验一行接入
3. **Service 调用**：`app(SensitiveWordService::class)` 获取命中详情、试检测（inspect）、自定义动作处理
4. **钩子扩展**：`whitelist` 白名单、`before_filter`/`after_filter` 文本加工、`hit` 命中通知

核心业务闭环：**词库管理（分类/词条/导入导出）→ DFA 引擎过滤（三级动作决策 + 归一化变体识别）→ 命中日志审计 → 词库分发（导出发布包上传版本服务 / 在线同步官方词库）**。

其中「词库维护」页可导出与版本服务发布包**同构的 zip**（words.txt + meta.json），导出物可直接上传到 Versionsvr「敏感词库」发布，形成「本站整理词库 → 官方发布 → 全网同步」闭环。

## 目录结构

```
CmsproSensitivewords/
├── manifest.json                 # 应用声明（菜单/权限/配置组/runtime_files/弱依赖）
├── icon.svg                      # 应用图标
├── ServiceProvider.php           # 入口：helpers/配置/单例/验证规则/路由/视图/钩子注册
├── Hooks.php                     # 钩子注册：系统钩子监听（可配置）+ 自定义钩子点
├── Install.php                   # 安装/卸载/升级：迁移兜底、配置三向同步、内置分类与词库
├── helpers.php                   # 全局辅助函数（其他应用零依赖入口）
├── Config/sensitivewords.php     # 默认配置兜底
├── Routes/admin.php              # 后台页面 + api 接口路由
├── Routes/home-api.php           # 前端公开 API（check/filter/status，限流）
├── Controllers/Admin/            # 词条/分类/词库/日志/设置控制器 + BaseController
├── Controllers/HomeApi/          # FilterApiController
├── Services/SensitiveWordEngine.php        # DFA 匹配引擎（纯算法层）
├── Services/SensitiveWordService.php       # 运行时过滤核心（单例 + 词典编译缓存）
├── Services/SensitiveWordManageService.php # 管理态（CRUD/导入导出/日志管理）
├── Services/SensitiveLibraryService.php    # 词库维护（内置/文件/在线同步）
├── Models/                       # SensitiveCategory / SensitiveWord / SensitiveHitLog
├── Migrations/                   # 3 张建表迁移（幂等 + SQLite 兼容）
├── Rules/SensitiveWordRule.php   # Laravel ValidationRule 对象规则
├── Storage/base_words.txt        # 内置基础词库（随应用分发）
├── Views/Admin/                  # words/categories/hit-logs/library/settings 视图
├── Tests/                        # 应用测试（108 个用例：引擎单测 + 服务/接口/规则/助手/词库导出/设置页 + 跨应用契约测试）
└── doc/                          # 三类应用文档（特性清单/使用指南/扩展指南）
```

## 文档索引

- [特性清单](doc/CmsPro-敏感词过滤-特性清单.md)：应用覆盖的框架特性与实现位置对照
- [使用指南](doc/CmsPro-敏感词过滤-使用指南.md)：安装、配置、功能验证、常见问题
- [扩展指南](doc/CmsPro-敏感词过滤-扩展指南.md)：**其他应用如何接入本应用**（四种方式 + 钩子契约）
