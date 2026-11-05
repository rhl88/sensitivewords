# 敏感词过滤 · 特性清单

> 版本：1.3.0 | 更新日期：2026-11-05
> 应用版本：1.3.0 | 应用 ID：`cmspro.sensitivewords`

> 本文档系统整理本应用覆盖的 CmsPro v5 框架特性，并标注对应实现位置，作为应用开发与二次开发的官方参考。

---

## 一、应用体系架构

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 1 | 目录隔离 | 应用代码统一存放于 `app/Apps/CmsproSensitivewords/` | 应用根目录 |
| 2 | 命名空间隔离 | 全部类位于 `App\Apps\CmsproSensitivewords\` 命名空间 | 各 PHP 文件头部 |
| 3 | 数据库隔离 | 三张表统一以 `app_cmspro_sensitivewords_` 为前缀 | `Migrations/*.php` |
| 4 | 视图隔离 | 视图命名空间 `cmspro.sensitivewords::` | `ServiceProvider::loadViews()` |
| 5 | 配置隔离 | 配置项 code 以 `app_cmspro_sensitivewords_` 为前缀 | `manifest.json` config_groups / `Install::seedDefaultConfigs()` |
| 6 | manifest.json | 应用元数据唯一声明源 | `manifest.json` |
| 7 | 目录名映射 | `app_id_to_class_name('cmspro.sensitivewords')` = `CmsproSensitivewords`（已验证） | 应用目录名 |

## 二、终端覆盖

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 8 | 后台管理端（admin） | 中间件 `['web','auth:admin','permission:admin']`，前缀 `/admin/cmspro/sensitivewords` | `Routes/admin.php` / `Controllers/Admin/` |
| 9 | 前端公开 API | 中间件 `['web','throttle:60,1']`，前缀 `/api/cmspro/sensitivewords`，无认证、限流防刷 | `Routes/home-api.php` / `Controllers/HomeApi/FilterApiController.php` |

## 三、路由系统

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 10 | 路由文件按端拆分 | `admin.php`（后台页面+接口）与 `home-api.php`（公开接口）分离 | `Routes/` |
| 11 | 页面/接口分离 | 页面路由挂根路径返回视图，数据接口统一放 `api/` 子前缀 | `Routes/admin.php` |
| 12 | 路由参数无 int 提示 | `{id}` 一律 `whereNumber('id')` 约束且不加 int 类型提示（防 TypeError） | `Routes/admin.php` / 各控制器方法签名 |
| 13 | 统一响应 | 数据接口用 `ApiResponse`；Layui table 数据源用 `{code,msg,count,data}` | `Controllers/Admin/BaseController.php` |

## 四、数据层

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 14 | 迁移幂等 | `up()` 前 `Schema::hasTable()` 检查，`down()` 用 `dropIfExists()` | `Migrations/*.php` |
| 15 | SQLite 兼容 | 表备注 `ALTER TABLE ... COMMENT` 用驱动判断包裹（测试环境跳过） | `Migrations/*.php` |
| 16 | 表和字段备注 | 每字段 `->comment()`，每表 `DB::statement(... COMMENT ...)` | `Migrations/*.php` |
| 17 | 时间字段规范 | 使用 `create_time` / `update_time`（非 Laravel 默认） | `Models/*.php` 的 CREATED_AT/UPDATED_AT 常量 |
| 18 | 日期格式规范 | 模型 `$casts` 为 `datetime:Y-m-d H:i:s`，继承 BaseModel 重写 serializeDate | `Models/*.php` |
| 19 | BaseModel 继承 | 三个模型均继承 `App\Models\BaseModel` | `Models/SensitiveCategory.php` 等 |
| 20 | 关联关系 | `SensitiveCategory hasMany SensitiveWord`；词条 `belongsTo` 分类 | `Models/SensitiveCategory.php` / `SensitiveWord.php` |
| 21 | 查询作用域 | `scopeEnabled` / `scopeOfSource` | `Models/SensitiveWord.php` |

## 五、核心过滤引擎

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 22 | DFA 字典树算法 | 建一次树后单次检测 O(文本长度)，与词条数无关 | `Services/SensitiveWordEngine.php` |
| 23 | 最长匹配优先 | 「赌博」与「赌博网站」共存时命中更长者，不重复报告 | `SensitiveWordEngine::match()` |
| 24 | 归一化匹配 | 全角转半角、英文大小写归一、跳过干扰符（识别「敏★感★词」变体） | `SensitiveWordEngine::normalizeChar()` / `isSkipChar()` |
| 25 | 词典编译缓存 | 按词条签名（数量+更新时间+策略配置）序列化词典树到 storage，签名变化自动重建 | `SensitiveWordService::engine()` / `signature()` |
| 26 | 命中按 ID 回查 | 词典叶子携带词条 ID，命中后按 ID 回查，不受匹配策略配置变更影响 | `SensitiveWordService::resolveHits()` |
| 27 | 动作三级决策 | 词条 action → 分类 default_action → 全局 default_action | `SensitiveWordService::resolveAction()` |
| 28 | 异常安全降级 | 过滤引擎任何内部异常均记日志并放行原文，不阻断调用方主流程 | `SensitiveWordService::check()` / `filter()` |

## 六、钩子系统

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 29 | 钩子注册传 app_id | 全部 `registerAction/registerFilter` 第 4 参传 `cmspro.sensitivewords`，禁用/卸载自动摘除 | `Hooks.php` |
| 30 | 双通道注册 | manifest `"hooks"` 声明 + ServiceProvider boot 补注册（去重防重复执行） | `ServiceProvider::registerHooks()` |
| 31 | 暴露自定义钩子点 | `cmspro.sensitivewords.hit`（Action）、`.whitelist` / `.before_filter` / `.after_filter`（Filter） | `Services/SensitiveWordService.php` / `Hooks.php` |
| 32 | 监听系统钩子（可配置） | `content.before_create/update`、`user.before_register`，默认关闭，设置页开启 | `Hooks::register()` |
| 33 | 禁用清理 | 监听 `app.disabled`，应用禁用时清理词典编译缓存 | `Hooks::onAppDisabled()` |

## 七、配置系统

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 34 | config_groups 声明 | 四组配置：过滤设置/日志设置/钩子接入/词库设置 | `manifest.json` |
| 35 | 三向同步 | `seedDefaultConfigs()` 以 manifest 为基准：新增写默认值、已有保留用户值、已删清残留 | `Install.php` |
| 36 | 配置读取 helper | `cmspro_sensitivewords_config()` 优先数据库、回退 `Config/sensitivewords.php`，带缓存 | `helpers.php` |
| 37 | mergeConfigFrom | 默认配置合并到 `config('apps.cmspro.sensitivewords.*')` | `ServiceProvider::register()` |
| 38 | 设置页白名单保存 | 只保存 ALLOWED_KEYS 白名单内的键；group_code 与 manifest 严格一致（防唯一键冲突脏数据） | `Controllers/Admin/SettingController.php` |

## 八、权限系统

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 39 | 权限对象格式声明 | 11 个权限码，name 均为中文 | `manifest.json` permissions |
| 40 | 后端权限校验 | 写操作先 `hasPermission()`（super_admin 放行），前端 data-permission 双保险 | `Controllers/Admin/BaseController.php` |
| 41 | 前端按钮权限 | 列表页注入 `admin.partials.permission-script`，按 data-permission 隐藏按钮 | `Views/Admin/*/index.blade.php` |

## 九、安装/卸载/升级

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 42 | 迁移兜底 | `install()` 中 require 迁移文件调 `up()` 并写 migrations 表记录 | `Install::runMigrations()` |
| 43 | 卸载回滚兜底 | `uninstall()` 中逆序调 `down()` 删表并删 migrations 记录（禁止手动 dropTable） | `Install::rollbackMigrations()` |
| 44 | 内置分类与词库 | 安装时创建 8 个系统内置分类、导入内置基础词库（幂等） | `Install::seedSystemCategories()` / `seedBuiltinWords()` |
| 45 | runtime_files 声明 | 词库包/词典缓存/临时文件三个运行时目录及清理策略 | `manifest.json` runtime_files |
| 46 | 运行时目录隔离 | 存于 `storage/app_cmspro_sensitivewords/`，卸载时清理；禁止在 public/apps 下建目录 | `Install.php` |
| 47 | 升级清超期日志 | `upgrade()` 按 log_keep_days 清理超期命中日志 | `Install::cleanExpiredLogs()` |

## 十、词库分发（弱依赖 Versionsvr）

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 48 | 在线词库更新 | 对接 Versionsvr 公开 API：检查 latest → 下载 zip → SHA-256 校验 → 落库 | `Services/SensitiveLibraryService.php` |
| 49 | 增量/覆盖双模式 | merge 只补缺失词；replace 先清 source=library 词条再导入（手工词条不受影响） | `SensitiveLibraryService::syncRemoteLibrary()` |
| 50 | 编码兜底 | 词库文件 GBK 自动转 UTF-8，去 BOM | `SensitiveLibraryService::convertToUtf8()` |
| 51 | 三种词库来源 | builtin（内置）/ import（导入）/ library（在线）/ manual（手工）按 source 区分 | `Models/SensitiveWord.php` 常量 |
| 52 | 弱依赖降级 | optional_app_ids 声明 versionsvr；未安装时在线更新不可用但不影响本地过滤 | `manifest.json` dependencies |
| 53 | 词库导入（txt/csv/zip） | zip 内按 words.txt → 任意 .txt → .csv → .dic 逐级放宽定位，兼容包裹目录与他人分享的词库包 | `SensitiveLibraryService::importFromFile()` / `importFromZip()` |
| 54 | 导入冲突三策略 | skip 跳过已存在 / update 补齐空字段 / overwrite 覆盖；skip 不覆盖本地已调动作 | `SensitiveWordManageService::importRows()` |
| 55 | 词库导出双格式 | 按来源（多选）/分类/状态筛选，导出 txt 纯文本或 zip 词库包（words.txt + meta.json） | `Controllers/Admin/LibraryController::export()` |
| 56 | 导出导入格式互认 | 导出格式与导入解析同口径，本站导出物可原样导入（往返不丢动作/级别/分类） | `SensitiveWordManageService::exportWords()` |
| 57 | 导出字段不错位 | 动作为空但有级别/分类时保留中间空段（`词,,3,分类`），级别 1 省略 | `SensitiveWordManageService::exportWords()` |
| 58 | 词库包内存构建 | zip 经系统临时目录构建后读回并删除，不落应用运行时目录 | `SensitiveLibraryService::makeZipInMemory()` |

## 十一、视图规范

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 59 | 完整 HTML 结构 | 后台列表页/表单页含完整 `<html>/<head>/<body>` 与公共 CSS 引用 | `Views/Admin/*` |
| 60 | 表单两种模式 | 词条/分类表单用模式 A（独立页 + iframe `layer.open(type:2)`） | `Views/Admin/words/form.blade.php` 等 |
| 61 | 固定底部按钮栏 | 表单页「内容区 calc(100vh-52px) + 按钮栏 52px」互补布局 | `Views/Admin/*/form.blade.php` |
| 62 | @verbatim 包裹 | 所有 Layui `{{# }}` 模板用 `@verbatim/@endverbatim` 包裹 | `Views/Admin/*/index.blade.php` |
| 63 | CSRF + 401 处理 | `$.ajaxSetup` 注入 X-CSRF-TOKEN，401 跳登录页 | 各视图 JS |
| 64 | 行内无感知更新 | 状态开关用 `table.updateRow` 单行更新，失败回滚开关 | `Views/Admin/words/index.blade.php` |
| 65 | 本地资源引用 | 全部引用 `CmsProUi/component/*` 本地路径，无 CDN | 各视图 `<head>` |
| 66 | 设置页 Tab 分类 | 四组配置按类型分页（过滤/日志/钩子/词库），单一 form 包裹全部面板，底部一次保存全部生效 | `Views/Admin/settings/index.blade.php` |
| 67 | Tab 切换重渲染 | `element.on('tab()')` 内调 `form.render()`，修正隐藏面板 select 宽度异常 | `Views/Admin/settings/index.blade.php` |

## 十二、菜单系统

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 68 | menus 声明带 code | 5 个叶子菜单均声明稳定 code（升级幂等匹配保留角色关联） | `manifest.json` menus |
| 69 | 目录不声明 path | 含 children 的顶级目录不声明 path（硬性规范） | `manifest.json` menus |
| 70 | code 全局唯一 | 目录 code 与子菜单 code 互不重复 | `manifest.json` menus |

## 十三、扩展接口（供任意应用接入）

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 71 | 全局辅助函数 | `cmspro_sensitivewords_filter/check/filter_array/available/config` | `helpers.php` |
| 72 | Service 容器调用 | `app(SensitiveWordService::class)->filter/check/inspect/validateText` | `Services/SensitiveWordService.php` |
| 73 | 字符串验证规则 | `'sensitive_word'`（Validator::extend 注册，支持场景参数） | `ServiceProvider::registerValidationRule()` |
| 74 | 对象验证规则 | `new SensitiveWordRule($scene, $sourceApp)`（ValidationRule 接口） | `Rules/SensitiveWordRule.php` |
| 75 | HTTP 检测接口 | `POST /api/cmspro/sensitivewords/check|filter`（非 PHP 调用方） | `Controllers/HomeApi/FilterApiController.php` |
| 76 | Filter 钩子扩展 | 其他应用挂 `.whitelist/.before_filter/.after_filter` 定制过滤行为 | `Services/SensitiveWordService.php` |

## 十四、测试

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 77 | 应用级测试 | 单元 + 功能测试放 `Tests/`（命名空间 `App\Apps\CmsproSensitivewords\Tests`），不入框架 tests/ | `Tests/` |
| 78 | RefreshDatabase 隔离 | sqlite :memory:，不污染真实库；tearDown 清理 storage 词典缓存 | `Tests/SensitivewordsTestCase.php` |
| 79 | 场景全覆盖 | 正常/异常/边界/权限/校验失败；122 个测试全部通过 | `Tests/Unit` + `Tests/Feature` |
| 80 | 跨应用契约测试 | 串联验证「本站导出发布包 → Versionsvr 解析发布 → 产物可回同步」闭环，守护包格式契约 | `Tests/Feature/CrossAppReleaseContractTest.php` |

## 十五、版本历史

| 版本 | 日期 | 说明 |
|------|------|------|
| 1.3.0 | 2026-11-05 | 词库导入 / 导出双向打通：新增导入测试 14 个（含 zip 导入回归与导出→导入往返）；修复 `importFromFile()` 调用未定义 `importFromZip()` 致上传 zip 致命错误；zip 内词库文件定位逐级放宽；特性清单重排为 1-80 连续；测试增至 122 个 |
| 1.2.0 | 2026-11-05 | 「过滤设置」页改为按类型 Tab 分类（四个分页，单一表单统一保存）；新增设置页渲染与配置接口测试；修正本清单特性编号断裂（重排为 1-78 连续）；新增跨应用契约测试（导出发布包 → Versionsvr 解析发布 → 回同步闭环）；测试增至 108 个 |
| 1.1.0 | 2026-11-05 | 词库维护页新增导出能力：可导出与版本服务发布包同构的 zip（words.txt + meta.json），支持来源/分类/状态筛选；导出四段式修复中间空段丢失导致的字段错位；测试增至 95 个 |
| 1.0.0 | 2026-11-05 | 初始版本：DFA 过滤引擎、词条/分类管理、命中日志、四种扩展接口、内置基础词库、在线词库更新（对接 Versionsvr） |
