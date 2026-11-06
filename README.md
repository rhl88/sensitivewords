# 敏感词过滤 应用文档

> 应用ID：`cmspro.sensitivewords` | 文档版本：1.4.3 | 应用版本：1.4.3 | 依赖：PHP ≥ 8.3, CmsPro ≥ 5.0.0（可选弱依赖 `versionsvr`，用于在线词库更新与词库发布）

- 官方地址：https://www.cmspro.cn/apps/cmspro.sensitivewords
- 本应用是 CmsPro 系统的通用内容安全能力，基于 CMSPRO 运行，请先安装 [CMSPRO](https://www.cmspro.cn/help/2)

## 1. 应用概述

敏感词过滤应用基于 DFA 字典树引擎提供敏感词**检测 / 替换 / 拦截**三种处理动作，并向任意应用开放四种扩展接入方式，适用于内容发布、评论互动、用户注册等需要过滤不良文本的场景。

- **词库管理**：分类管理 / 词条增删改查 / 状态与级别 / 批量导入导出（txt、csv、zip）/ 在线同步官方词库
- **DFA 过滤引擎**：最长匹配 / 忽略英文大小写 / 跳过干扰字符 / 全角转半角 / 三级动作决策（block、replace、warn）
- **命中审计**：命中日志记录（来源应用、场景、请求路径、IP、原文快照）/ 日志清理 / 累计命中统计
- **四种扩展接入**：全局辅助函数 `cmspro_sensitivewords_filter()` 与 `cmspro_sensitivewords_check()` / 验证规则 `sensitive_word` 与 `SensitiveWordRule` / `SensitiveWordService` 服务调用 / `whitelist`、`before_filter`、`after_filter`、`hit` 钩子
- **系统钩子接入**：可按配置监听系统内容模块（`content.before_create`、`content.before_update`）与前台用户注册（`user.before_register`）

核心业务闭环：词库管理（分类/词条/导入导出）→ DFA 引擎过滤（三级动作决策 + 归一化变体识别）→ 命中日志审计 → 词库流转（导出备份 / 导入他人词库 / 在线同步官方词库）。

「词库维护」页支持词库导入与导出双向流转：导出 txt/csv/zip，导出格式与导入解析同口径，本站导出的文件可原样导入其他站点。

## 2. 应用截图

> 截图来源：[官方应用页 · cmspro.sensitivewords](https://www.cmspro.cn/apps/cmspro.sensitivewords)（官网标注 v1.4.3）。每次发布与更新必须核对本节截图与官网「应用截图」是否一致，不一致时以官网为准同步更新。

![应用截图 1](https://www.cmspro.cn/uploads/appstore/2026/11/06/KJEnM7RUrjucuGc2e8Z14WS1BxYVzMCauo0QH2ph.png)

![应用截图 2](https://www.cmspro.cn/uploads/appstore/2026/11/06/B1LXMXD5M6nJDbpVQaVIoaQdvUSSqL0jb9vb7OjA.png)

![应用截图 3](https://www.cmspro.cn/uploads/appstore/2026/11/06/q7427nVvxUFFKMvzWXE7488KYR0BCLCUBlBMHLxi.png)

![应用截图 4](https://www.cmspro.cn/uploads/appstore/2026/11/06/NQGqoVxt2Qh4zj0kEgfjVep90U6S9Ui44Cl3GiXW.png)

![应用截图 5](https://www.cmspro.cn/uploads/appstore/2026/11/06/9OdUeoGbt7eqATLZ7UJwEcTDXl6EkXZrmUbnV3QN.png)

![应用截图 6](https://www.cmspro.cn/uploads/appstore/2026/11/06/5MHIriusrGIqnvj31Z3lXqBr0Nw5FkhLbhtZcPjM.png)

![应用截图 7](https://www.cmspro.cn/uploads/appstore/2026/11/06/OQ7F4FzatSlfwSvEYchFLzAVztN03zAPg3OGbHNR.png)

![应用截图 8](https://www.cmspro.cn/uploads/appstore/2026/11/06/URPYFYIbLOGoelB9aqm0X2geLs9zNFn3ri9eFcZM.png)

![应用截图 9](https://www.cmspro.cn/uploads/appstore/2026/11/06/O34UpnUWBb6FYf7m4p3PQxchBT16KelA9lBH5QVX.png)

![应用截图 10](https://www.cmspro.cn/uploads/appstore/2026/11/06/soQpJRLxtQI9p5S2BWiRf3IYGgJvcMrnrGDfabkb.png)

## 3. 目录结构

```
CmsproSensitivewords/
├── Config/sensitivewords.php     # 默认配置兜底
├── Console/Commands/             # 应用级 Artisan 命令（测试数据填充 cmspro:sensitivewords:seed-testdata）
├── Controllers/Admin/            # 词条/分类/词库/日志/设置控制器 + BaseController
├── Controllers/HomeApi/          # 前端公开 API（FilterApiController）
├── Migrations/                   # 3 张建表迁移（分类 / 词条 / 命中日志，幂等 + SQLite 兼容）
├── Models/                       # SensitiveCategory / SensitiveWord / SensitiveHitLog
├── Routes/admin.php              # 后台页面 + api 接口路由
├── Routes/home-api.php           # 前端公开 API（check/filter/status，限流）
├── Rules/SensitiveWordRule.php   # Laravel ValidationRule 对象规则
├── Services/                     # 引擎 / 运行时服务 / 管理态服务 / 词库维护服务
├── Storage/base_words.txt        # 内置常见词库（随应用分发，192 条，升级按指纹增量补齐）
├── Tests/                        # 应用测试（177 个用例：引擎单测 + 服务/接口/规则/助手/词库导入导出/内置词库/设置页/测试数据命令 + 跨应用契约测试）
├── Views/Admin/                  # words/categories/hit-logs/library/settings 视图
├── doc/                          # 三类应用文档（特性清单/使用指南/扩展指南）
├── helpers.php                   # 全局辅助函数（其他应用零依赖入口）
├── Hooks.php                     # 钩子注册：系统钩子监听（可配置）+ 自定义钩子点
├── Install.php                   # 安装/卸载/升级：迁移兜底、配置三向同步、内置分类与词库
├── ServiceProvider.php           # 入口：helpers/配置/单例/验证规则/路由/视图/钩子/命令注册
├── manifest.json                 # 应用声明（菜单/权限/配置组/runtime_files/弱依赖）
├── icon.svg                      # 应用图标
└── README.md                     # 本文件
```

## 4. 应用文档

| 文档 | 说明 |
|------|------|
| [特性清单](doc/CmsPro-敏感词过滤-特性清单.md) | 应用覆盖的框架特性与实现位置对照 |
| [使用指南](doc/CmsPro-敏感词过滤-使用指南.md) | 安装、配置、功能验证、常见问题 |
| [扩展指南](doc/CmsPro-敏感词过滤-扩展指南.md) | 其他应用如何接入本应用（四种方式 + 钩子契约） |

## 5. 验证

```bash
# 在 code 目录下执行（使用 sqlite :memory: 内存库）
php artisan test app/Apps/CmsproSensitivewords/Tests

# 结果：177 passed (1291 assertions)
```