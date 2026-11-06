# CmsPro 敏感词过滤扩展指南

> 版本：1.4.2 | 更新日期：2026-11-06
> 适用应用：CmsproSensitivewords v1.0.0+

---

## 一、概述

本指南说明**其他应用**如何接入敏感词过滤能力。共四种方式，按接入成本从低到高排列：

| 方式 | 适用场景 | 是否需 use 本应用类 |
|------|---------|------------------|
| 1. 全局辅助函数 | 最简单的一行式过滤/检测 | 否（函数存在性判断即可） |
| 2. 字符串验证规则 | 表单校验中拦截违规内容 | 否 |
| 3. Service 容器调用 | 需要命中详情、自定义动作处理 | 是（建议 class_exists 保护） |
| 4. 钩子扩展 | 注入白名单、预处理文本、命中后联动 | 否（挂系统 HookManager） |

另有 HTTP API 供非 PHP 调用方（前端 JS / 小程序 / 第三方系统），见使用指南 4.2。

**弱依赖原则**：本应用可能未安装、未启用或被禁用。所有接入方式都内置能力探测，不可用时自动放行（filter 原样返回、check 返回 passed=true、验证规则通过），**接入方无需判断本应用是否安装，也不会因本应用故障而报错**。

---

## 二、前置检查（可选）

```php
// 探测过滤能力是否可用（应用已安装启用 + 总开关开 + 数据表就绪）
if (function_exists('cmspro_sensitivewords_available') && cmspro_sensitivewords_available()) {
    // 能力可用
}
```

> 通常无需显式探测——各接入函数内部已做判定，直接调用即可。

---

## 三、辅助函数调用（推荐入门方式）

### 3.1 函数签名

| 函数 | 签名 | 返回 |
|------|------|------|
| `cmspro_sensitivewords_filter` | `(string $text, ?string $scene = null, ?string $sourceApp = null)` | `string` 过滤后文本 |
| `cmspro_sensitivewords_check` | `(string $text, ?string $scene = null, ?string $sourceApp = null)` | `array` 命中详情 |
| `cmspro_sensitivewords_filter_array` | `(array $data, array $fields = [], ?string $scene = null, ?string $sourceApp = null)` | `array` 过滤后数组 |
| `cmspro_sensitivewords_available` | `()` | `bool` 能力是否可用 |
| `cmspro_sensitivewords_sanitize_text` | `(?string $text, int $maxLength = 500)` | `string` 合法 UTF-8 文本 |

参数说明：
- `$scene`：业务场景标识（写入命中日志，建议用 `{模块}.{动作}` 格式，如 `comment.create`）
- `$sourceApp`：调用方应用 ID（写入命中日志，如 `cmspro.blog`），便于日志页按来源筛选
- `cmspro_sensitivewords_sanitize_text`：把可能含非法字节的文本净化为合法 UTF-8，非法序列替换为占位字符、可读上下文完整保留。**接入方若把数据库异常 message 拼进 JSON 响应，建议先用它净化**——否则 `json_encode` 会失败，接口退化成 500「Malformed UTF-8 characters」，把可读的业务错误与真实根因一起掩盖

### 3.2 check 返回结构

```php
[
    'passed'    => bool,   // true=可放行；false=命中「禁止」级，应拦截
    'action'    => string, // 最终动作：block/replace/warn/none
    'message'   => string, // block 时的用户提示文案（含命中词）
    'hit_count' => int,    // 命中数
    'filtered'  => string, // 替换后的文本
    'hits'      => [       // 命中详情列表
        ['word' => '赌博', 'start' => 2, 'end' => 3, 'level' => 3,
         'action' => 'block', 'category_id' => 5, 'word_id' => 12],
    ],
]
```

### 3.3 调用示例

```php
// 示例 1：评论提交前过滤（替换模式）
$content = cmspro_sensitivewords_filter($request->input('content'), 'comment.create', 'cmspro.blog');
Comment::create(['content' => $content, /* ... */]);

// 示例 2：拦截模式（命中禁止级即拒绝提交）
$result = cmspro_sensitivewords_check($request->input('content'), 'comment.create', 'cmspro.blog');
if (! $result['passed']) {
    return response()->json(ApiResponse::error(40002, $result['message']));
}

// 示例 3：批量过滤表单多个字段
$data = cmspro_sensitivewords_filter_array(
    $request->validated(),
    ['title', 'content', 'nickname'],
    'post.save',
    'cmspro.blog'
);
```

> **注意**：`filter` 对 block 级词条同样执行掩码替换（保证落库内容安全）。需要「拒绝提交」语义时用 `check` 判 `passed`，或下节验证规则。

---

## 四、验证规则接入（表单校验场景）

### 4.1 字符串规则 `sensitive_word`（零依赖，推荐）

```php
// 基础用法：命中禁止级词条时验证失败
$request->validate([
    'content' => 'required|string|sensitive_word',
]);

// 带场景参数：sensitive_word:{scene},{sourceApp}（写入命中日志）
$request->validate([
    'content' => 'required|string|sensitive_word:comment.create,cmspro.blog',
]);
```

### 4.2 对象规则 `SensitiveWordRule`（需要 use 类）

```php
use App\Apps\CmsproSensitivewords\Rules\SensitiveWordRule;

$request->validate([
    'content' => ['required', 'string', new SensitiveWordRule('comment.create', 'cmspro.blog')],
]);
```

> 建议优先用字符串规则：调用方无需 use 本应用的类，本应用未安装时规则自动通过，彻底弱依赖。

---

## 五、Service 层调用（需要命中详情/自定义处理）

```php
use App\Apps\CmsproSensitivewords\Services\SensitiveWordService;

// 容器解析（ServiceProvider 已绑定单例，同请求复用词典树）
$service = app(SensitiveWordService::class);

// 检测（写命中日志 + 触发命中钩子）
$result = $service->check($text, 'order.remark', 'cmspro.ordersign');

// 试检测（无任何副作用：不写日志、不计数、不触发钩子，适合干跑预览）
$preview = $service->inspect($text);

// 校验（命中禁止级返回错误文案，否则 null）
$error = $service->validateText($text, 'order.remark', 'cmspro.ordersign');

// 过滤（返回替换后文本）
$filtered = $service->filter($text, 'order.remark', 'cmspro.ordersign');
```

> **弱依赖保护**：直接 use 本应用类要求调用方声明 `dependencies.optional_app_ids` 含 `cmspro.sensitivewords`，或调用前 `class_exists()` 判断。不满足时请改用第三/四章的弱依赖方式。

---

## 六、钩子扩展点

本应用暴露以下自定义钩子，其他应用在自己的 `Hooks.php` 中注册（**第 4 参必须传自己的 app_id**）：

### 6.1 Filter：`cmspro.sensitivewords.whitelist`（注入白名单）

按场景放行指定词，返回追加后的白名单数组：

```php
$hooks->registerFilter('cmspro.sensitivewords.whitelist', function (array $whitelist, string $scene, string $sourceApp) {
    // 订单备注场景放行「代办」
    if ($scene === 'order.remark') {
        $whitelist[] = '代办';
    }
    return $whitelist;
}, 10, 'cmspro.ordersign');
```

### 6.2 Filter：`cmspro.sensitivewords.before_filter`（过滤前预处理）

```php
// 例：富文本先剥离 HTML 标签再过滤，避免标签内字符干扰匹配
$hooks->registerFilter('cmspro.sensitivewords.before_filter', function (string $text, string $scene) {
    return $scene === 'article.save' ? strip_tags($text) : $text;
}, 10, 'cmspro.blog');
```

### 6.3 Filter：`cmspro.sensitivewords.after_filter`（过滤后再加工）

参数：`(string $filtered, string $original, array $hits, string $scene, string $sourceApp)`

```php
$hooks->registerFilter('cmspro.sensitivewords.after_filter', function (string $filtered, string $original, array $hits) {
    return $hits === [] ? $filtered : $filtered . '（内容已经安全审核）';
}, 10, 'cmspro.blog');
```

### 6.4 Action：`cmspro.sensitivewords.hit`（命中后通知）

参数：`(array $hits, array $context)`，`$context` 含 `text/scene/source_app/action`：

```php
// 例：命中禁止级词条时推送人工审核待办
$hooks->registerAction('cmspro.sensitivewords.hit', function (array $hits, array $context) {
    if (($context['action'] ?? '') === 'block') {
        app(\App\Services\NotificationService::class)->push([
            'title' => '敏感内容待审核',
            'content' => '场景 ' . $context['scene'] . ' 命中 ' . count($hits) . ' 个违规词',
            'link' => '/admin/cmspro/sensitivewords/hit-logs',
            'level' => 'warning',
            'app_id' => 'cmspro.blog',
        ]);
    }
}, 10, 'cmspro.blog');
```

### 6.5 系统钩子自动接入（零代码，配置开关）

本应用可自动监听以下系统钩子（默认关闭，在「过滤设置 → 钩子接入」开启，开启后下次请求生效）：

| 系统钩子 | 行为 | 配置项 |
|---------|------|--------|
| `content.before_create` / `content.before_update` | 过滤内容模块 title/summary/content/description | hook_content_filter |
| `user.before_register` | 检测注册 nickname/username/name，命中禁止级抛异常阻断注册 | hook_user_register |

---

## 七、依赖声明

调用方若使用 Service 类或对象验证规则，建议在自己的 `manifest.json` 声明弱依赖：

```json
"dependencies": {
    "app_ids": [],
    "optional_app_ids": ["cmspro.sensitivewords"]
}
```

仅用辅助函数/字符串规则/钩子时**无需任何声明**。

---

## 八、注意事项

1. **数据隔离**：命中日志的 `source_app` 请如实传调用方 app_id，便于分来源审计；不传则为空
2. **场景标识**：`scene` 建议 `{模块}.{动作}` 格式，与日志页筛选联动
3. **性能**：辅助函数每次调用有配置缓存读取（Cache::remember 600s）+ 词典请求内单例复用；批量文本请用 `filter_array` 一次处理
4. **不要在循环中反复 check 同一段文本**：check 会写日志与计数，干跑预览请用 `inspect()`
5. **钩子归属**：注册本应用钩子时第 4 参必须传调用方自己的 app_id（不是 `cmspro.sensitivewords`），保证调用方被禁用时钩子自动摘除
6. **事务边界**：命中日志写入不在调用方事务内（内部 try-catch 兜底），过滤失败不会回滚调用方业务
7. **词库导出契约**：`SensitiveLibraryService::buildReleasePackage()` 产出的 zip 与 Versionsvr 规范化发布包同构（`words.txt` 四段式 + `meta.json`），其他应用若需自建词库分发可复用该格式；四段式中动作为空但存在级别/分类时必须保留中间空段（如 `代开票,,3,fraud`），省略会导致消费端字段错位

---

## 九、版本与更新日志

| 版本 | 日期 | 更新人 | 说明 |
|------|------|--------|------|
| 1.4.2 | 2026-11-06 | 开发团队 | 修复 `checkRemoteLibrary()` 因 PHP 数组 `+` 左键优先致成功结果被丢弃的缺陷（成功路径永远返回 `available=false`）。**若接入方通过 Service 调用 `checkRemoteLibrary()` 判断在线词库可用性，修复前会误判为不可用**；`syncRemoteLibrary()` 同受影响（误抛「版本服务不可用」）。改用 `array_merge()`，返回值结构不变。**对外扩展接口无变化** |
| 1.4.1 | 2026-11-06 | 开发团队 | 新增辅助函数 `cmspro_sensitivewords_sanitize_text()`：把可能含非法字节的文本净化为合法 UTF-8（非法序列替换为占位字符、可读上下文保留）。**接入方若把数据库异常 message 拼进 JSON 响应，建议调用它**——否则 `json_encode` 失败会使接口返回 500「Malformed UTF-8」，掩盖真实根因。本次同时修复词条净化用 `trim()` 字节集合截断汉字的缺陷（详见使用指南 1.4.1） |
| 1.4.0 | 2026-11-05 | 开发团队 | 内置词库扩充至 192 条；`importBuiltinWords()` 由「存在 builtin 词条即跳过」改为**按词库文件 SHA-256 指纹增量补齐**（skip 模式保留管理员对既有词条的调整），`Install::upgrade()` 补调该方法使扩充对已安装站点生效；`importRows()` 改为分批 `whereIn` 预查已存在键，消除逐条查库的 N+1。**对外扩展接口无变化** |
| 1.3.0 | 2026-11-05 | 开发团队 | 修复 `SensitiveLibraryService::importFromFile()` 调用未定义的 `importFromZip()` 导致上传 zip 词库致命错误；zip 内词库文件定位放宽为 words.txt → 任意 .txt → .csv → .dic。调用方若曾捕获过该异常可移除兜底。**对外扩展接口（辅助函数/验证规则/Service/钩子）无变化** |
| 1.2.0 | 2026-11-05 | 开发团队 | 本版本仅调整后台设置页 UI（Tab 分类），**对外扩展接口无变化**，接入方无需改动 |
| 1.1.0 | 2026-11-05 | 开发团队 | 新增词库导出能力（`buildReleasePackage()`），导出与版本服务发布包同构的 zip，可直接上传 Versionsvr 发布；补充词库导出契约说明 |
| 1.0.0 | 2026-11-05 | 开发团队 | 初始版本：四种扩展接口（辅助函数/验证规则/Service/钩子）+ HTTP API |
