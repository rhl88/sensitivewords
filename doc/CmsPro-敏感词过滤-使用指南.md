# 敏感词过滤 · 使用指南

> 版本：1.4.2 | 更新日期：2026-11-06
> 应用版本：1.4.2 | 应用 ID：`cmspro.sensitivewords`

> 本指南帮助使用者快速安装、配置、运行和验证「敏感词过滤」应用。

---

## 一、应用概述

敏感词过滤应用是 CmsPro 系统的**通用内容安全能力**：基于 DFA 字典树引擎提供敏感词检测、替换、拦截三种处理能力，并向**任意应用**开放四种接入方式（辅助函数 / Service / 验证规则 / 钩子），使其他应用无需自建词库与过滤逻辑即可获得内容安全防护。

### 核心功能

- **词库管理**：词条分类、增删改查、批量启停、粘贴导入、文件导入（txt/csv/zip）、导出
- **三种处理动作**：禁止（拦截报错）/ 替换（掩码）/ 提醒（放行记日志），词条级 → 分类级 → 全局三级可配
- **DFA 匹配引擎**：10 万词条毫秒级检测，支持全角/大小写/干扰符变体识别（「敏★感★词」也能命中）
- **命中日志**：记录来源应用、场景、命中词、原文快照、动作、IP，供审计与词库调优
- **在线词库更新**：对接版本服务（Versionsvr）同步官方词库，词库更新与应用发版解耦
- **内置常见词库**：随应用分发 192 条常见违规词条（辱骂攻击 50 / 广告推广 41 / 诈骗违禁 69 / 色情低俗 32），安装即导入、开箱可用；应用升级扩充词库时按内容指纹**增量补齐**，不覆盖管理员对既有词条的手工调整

### 适用场景

评论过滤、文章审核、用户昵称校验、表单内容安全、小程序文本检测等一切需要敏感词防护的场景。

---

## 二、环境要求

| 项目 | 要求 |
|------|------|
| PHP | >= 8.3 |
| CmsPro | >= 5.0.0 |
| 数据库 | MySQL 5.7+ / MariaDB 10.3+ |
| 可选依赖 | `versionsvr`（在线词库更新，弱依赖，未安装不影响本地过滤） |

---

## 三、安装

### 3.1 通过后台应用市场安装

1. 后台「应用市场」找到「敏感词过滤」，点击安装
2. 安装过程自动：建表（3 张）→ 注册配置（4 组）→ 创建内置分类（8 个）→ 导入内置常见词库（192 条）→ 注册菜单与权限
3. 安装后在「角色管理」为需要的角色分配「敏感词过滤管理」等权限

### 3.2 验证安装

| 检查项 | 预期 |
|--------|------|
| 后台菜单 | 出现「敏感词过滤」目录，含词条管理/分类管理/词库维护/命中日志/过滤设置 5 个子菜单 |
| 数据表 | `app_cmspro_sensitivewords_categories`（8 条内置分类）、`_words`（内置词条）、`_hit_logs`（空） |
| `apps` 表 path 字段 | 相对路径 `app/Apps/CmsproSensitivewords`（绝对路径会导致路由加载失败） |
| 配置项 | `config_items` 中出现 `app_cmspro_sensitivewords_*` 前缀的配置项 |
| 词典缓存目录 | `storage/app_cmspro_sensitivewords/` 已创建 |

---

## 四、功能验证

### 4.1 后台管理端

| 页面 | URL | 验证要点 |
|------|-----|---------|
| 词条管理 | `/admin/cmspro/sensitivewords/words` | 列表分页、按分类/动作/来源/状态筛选、新增/编辑弹窗、状态开关无感知更新、批量启停/删除、批量导入（粘贴文本 + 上传文件）、导出、在线检测试用 |
| 分类管理 | `/admin/cmspro/sensitivewords/categories` | 分类 CRUD；系统内置分类不可删除、code 不可改；有词条的分类不可删 |
| 词库维护 | `/admin/cmspro/sensitivewords/library` | 词库概览（分来源统计）、检查更新/立即同步（在线词库）、导入内置词库、重建词典缓存、按来源清理、**词库导入 / 导出（txt/csv/zip，双向互认）** |
| 命中日志 | `/admin/cmspro/sensitivewords/hit-logs` | 概览卡片、按来源/场景/动作/日期筛选、详情弹窗、删除/清理超期/清空（二次确认） |
| 过滤设置 | `/admin/cmspro/sensitivewords/settings` | 四组配置按类型 **Tab 分类**（过滤设置/日志设置/钩子接入/词库设置）；底部统一保存对四个分页全部生效，保存后配置立即生效（清缓存 + 重建词典） |

### 4.2 前端公开 API（限流 60 次/分钟）

| 方法 | URL | 参数 | 说明 |
|------|-----|------|------|
| GET | `/api/cmspro/sensitivewords/status` | 无 | 探测能力：enabled/ready/word_total |
| POST | `/api/cmspro/sensitivewords/check` | `text`（必填，≤10000 字）、`scene`（可选） | 检测：返回 passed/action/hits/filtered |
| POST | `/api/cmspro/sensitivewords/filter` | `text`（必填）、`scene`（可选） | 过滤：返回替换后文本 |

调用示例：

```bash
curl -X POST https://your-domain/api/cmspro/sensitivewords/check \
  -H 'Content-Type: application/json' \
  -d '{"text":"我在赌博网站下注","scene":"comment.create"}'
```

```json
{
  "code": 0,
  "message": "success",
  "data": {
    "passed": false,
    "action": "block",
    "message": "内容包含违规词：赌博",
    "hit_count": 1,
    "hits": [{"word": "赌博", "start": 2, "end": 3, "level": 3, "action": "block"}],
    "filtered": "我在**网站下注"
  },
  "timestamp": 1762000000
}
```

> PHP 侧调用**不要走 HTTP**，直接用辅助函数或 Service（见扩展指南），性能更好且无网络开销。

### 4.3 快速自测（后台「词条管理」页）

1. 词条管理页新增词条「测试违规词」，动作选「禁止」
2. 点工具栏「在线检测」，输入含「测试违规词」的文本 → 应显示命中且 passed=false
3. 「命中日志」页应出现该次检测记录（scene=admin.test）

---

## 五、配置说明

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| filter_enabled | 1 | 过滤总开关，关闭后所有调用直接放行原文（紧急排障用） |
| default_action | replace | 全局默认动作（block/replace/warn），词条与分类都未指定时兜底 |
| replacement | * | 替换掩码字符，按命中词长度重复 |
| ignore_case | 1 | 忽略英文大小写（改后自动重建词典） |
| full_width_to_half | 1 | 全角转半角，防全角绕过 |
| skip_symbols | 1 | 跳过干扰字符，识别「敏★感★词」变体 |
| log_enabled | 1 | 记录命中日志 |
| log_content_length | 200 | 日志原文快照长度（0-2000，0 不存原文） |
| log_keep_days | 90 | 日志保留天数（0 永久；超期日志在命中日志页清理） |
| stat_hit_count | 1 | 累计词条命中次数（高并发可关闭降低写库压力） |
| hook_content_filter | 0 | 监听系统内容模块钩子自动过滤标题/正文（默认关） |
| hook_user_register | 0 | 监听前台注册钩子检测昵称（默认关） |
| remote_update_enabled | 1 | 在线词库更新开关 |
| remote_update_mode | merge | 在线更新方式：merge 增量合并 / replace 覆盖官方库 |
| library_category_id | 0 | 在线词库归类分类 ID（0 = 内置「官方词库」分类） |
| library_version | （空） | 本地已同步词库版本（系统自动维护，勿手改） |
| builtin_library_hash | （空） | 内置词库文件指纹（系统自动维护，升级时据此增量补齐内置词库，勿手改） |

> 修改匹配策略三项（ignore_case / full_width_to_half / skip_symbols）后系统自动重建词典缓存，无需手工操作。

---

## 六、在线词库更新

1. 前提：本站 `apps.market.api_url` 指向的版本服务已部署 Versionsvr v1.6.0+ 且已发布敏感词库
2. 「词库维护」页点「检查更新」→ 显示远端最新版本、词条数、发布时间
3. 点「立即同步」：下载词库 zip → SHA-256 校验 → 按 merge/replace 模式导入 → 自动重建词典
4. 同步只影响 `source=library` 的词条，手工添加与文件导入的词条不受影响

---

## 七、词库导入 / 导出

「词库维护」页的「词库导入 / 导出」面板用于**备份本站词库**与**接收他人分享的词库**：导出格式与导入格式一致，本站导出的文件可原样被其他站点导入。

### 7.1 导入词库

| 项目 | 说明 |
|------|------|
| 支持格式 | `txt` / `csv` / `zip`（zip 内取 `words.txt` 或任意 `.txt`/`.csv`/`.dic`，兼容多一层包裹目录） |
| 编码 | UTF-8 与 GBK 均可，GBK 自动转换；自动去除 BOM |
| 行格式 | 每行一词，支持「词,动作,级别,分类」四段式；`#` 与 `//` 开头为注释 |
| 重复词条 | 跳过已存在（推荐）/ 补齐空字段 / 覆盖已有词条 |
| 默认分类 | 文件内已声明分类的词条按其声明归类；未声明的归入所选默认分类 |

导入后词条来源标记为 `import`，词典自动重建，**无需手工清缓存即可立即生效**。

### 7.2 导出词库

| 格式 | 产物 | 说明 |
|------|------|------|
| **纯文本 txt**（推荐） | `cmspro-sensitivewords-{来源}-{时间}.txt` | 标准四段式词库文件，便于人工审阅、纳入版本管理，可直接被「导入词库」读回 |
| 词库包 zip | `cmspro-sensitivewords-{版本}.zip` | 内含 `words.txt` + `meta.json`（版本、词条数、分类清单等元信息） |

筛选项：

- **词条来源**：手工添加 / 文件导入 / 在线词库 / 内置词库，可多选（至少选一个）
- **所属分类**：按分类导出，留空为全部分类
- **词条状态**：仅启用（推荐，导出实际生效的词条）/ 全部 / 仅禁用

### 7.3 词库文件格式

```
# 敏感词,动作(block/replace/warn),级别(1-3),分类code
赌博,block,3,fraud
代开票,,3,fraud        # 动作留空表示跟随分类/全局策略，中间空段必须保留
广告词,replace          # 级别 1 为默认值，尾部空段自动省略
```

zip 词库包结构：

```
cmspro-sensitivewords-{version}.zip
├── words.txt   # 上述四段式词库
└── meta.json   # {version, word_count, category_count, categories, generated_at, format, source_site}
```

> **格式要点**：动作为空但存在级别/分类时，中间空段会保留（如 `代开票,,3,fraud`）。若省略会被解析成「动作=3、级别=fraud、分类丢失」的字段错位——导入导出两侧均已按此口径处理并有测试守护。

---

## 八、卸载验证

| 检查项 | 预期 |
|--------|------|
| 数据表 | 3 张 `app_cmspro_sensitivewords_*` 表已删除（migrations 记录同步清理） |
| 配置 | `app_cmspro_sensitivewords_*` 配置项与配置组已删除 |
| 菜单/权限 | 本应用菜单与权限已清除 |
| 运行时目录 | `storage/app_cmspro_sensitivewords/` 已删除 |
| 其他应用 | 调用了本应用辅助函数的应用自动降级放行（函数内置能力探测），不报错 |

---

## 九、目录结构

```
CmsproSensitivewords/
├── manifest.json            # 应用声明（菜单/权限/配置组/runtime_files）
├── icon.svg                 # 应用图标
├── ServiceProvider.php      # 入口：helpers/配置合并/单例绑定/验证规则/路由/视图/钩子
├── Hooks.php                # 钩子注册：系统钩子监听（可配置）+ 自定义钩子点
├── Install.php              # 安装/卸载/升级：迁移兜底、配置三向同步、内置分类与词库
├── helpers.php              # 全局辅助函数（其他应用零依赖入口）
├── Config/sensitivewords.php # 默认配置（数据库无记录时兜底）
├── Routes/
│   ├── admin.php            # 后台页面 + api/ 接口
│   └── home-api.php         # 前端公开 API
├── Controllers/
│   ├── Admin/               # 词条/分类/词库/日志/设置 5 个控制器 + BaseController
│   └── HomeApi/             # FilterApiController（check/filter/status）
├── Services/
│   ├── SensitiveWordEngine.php       # DFA 匹配引擎（纯算法，可单测）
│   ├── SensitiveWordService.php      # 运行时过滤核心（检测/替换/日志/词典缓存）
│   ├── SensitiveWordManageService.php # 管理态（CRUD/导入导出/分类/日志管理）
│   └── SensitiveLibraryService.php   # 词库维护（内置词库/文件导入/在线同步）
├── Models/                  # SensitiveCategory / SensitiveWord / SensitiveHitLog
├── Migrations/              # 3 张建表迁移（幂等 + SQLite 兼容）
├── Rules/SensitiveWordRule.php # Laravel ValidationRule 对象规则
├── Storage/base_words.txt   # 内置基础词库（随应用分发）
├── Views/Admin/             # words/categories/hit-logs/library/settings 五个模块
├── Tests/                   # 应用测试（Unit + Feature，85 个用例）
└── doc/                     # 三类应用文档
```

---

## 十、常见问题

**Q1：其他应用调用辅助函数不生效？**
- 确认本应用已安装且**已启用**、`filter_enabled` 总开关为开
- 用 `cmspro_sensitivewords_available()` 探测；返回 false 时函数一律原样放行（设计如此，弱依赖安全）

**Q2：新增词条后前台检测不到？**
- 词条写入会自动重建词典；若手工直接改过数据库，到「过滤设置 → 重建词典缓存」或「词库维护」页手动重建

**Q3：「敏★感★词」这类变体没命中？**
- 确认 `skip_symbols`（跳过干扰字符）已开启；干扰字符指空白、标点与符号

**Q4：命中日志增长太快？**
- 调小 `log_content_length`（不存原文）、设置 `log_keep_days` 并定期在命中日志页「清理超期日志」；或关闭 `stat_hit_count` 降低写库量

**Q5：在线更新提示「版本服务不可用」？**
- 检查 `apps.market.api_url` 配置与网络连通性；确认服务端 Versionsvr 已升级到 v1.6.0+ 且「敏感词库」已发布

**Q6：误过滤了正常词（误伤）？**
- 让接入方注册白名单钩子 `cmspro.sensitivewords.whitelist`（见扩展指南 4.2），或在后台直接禁用该词条

---

## 十一、版本历史

| 版本 | 日期 | 说明 |
|------|------|------|
| 1.4.2 | 2026-11-06 | **修复严重缺陷**：`checkRemoteLibrary()` 用 `$base + ['available'=>true, ...]` 返回成功结果，但 PHP 数组 `+` 运算符「左键优先」，`$base` 已含全部键致右侧补丁被整体丢弃——无论远程版本服务多正常，该方法成功路径永远返回 `available=false`，`syncRemoteLibrary()` 随即抛「版本服务不可用」，「检查更新 / 立即同步」接口返回 **50002**（实连 v5.cmspro.cn 已发布 192 条词库仍报不可用）。5 处 `$base +` 改用 `array_merge()`。新增 RemoteLibrarySyncTest 9 个用例（Http::fake 覆盖成功/HTTP错误/无发布/连接失败/校验和/端到端六路径）；此前该链路零测试覆盖故缺陷长期潜伏 |
| 1.4.1 | 2026-11-06 | **修复严重缺陷**：`normalizeInputWord()` 用 `trim($word, "\"'“”‘’ \t")` 去引号，而 trim 第二参数是**字节集合**——中文弯引号被拆成字节 `E2 80 9C/9D/98/99` 混入，导致尾字节落在 `{80,98,99,9C,9D}` 的汉字被咬掉一字节产生非法 UTF-8（内置词库 192 条中 **12 条**受害：传销/杀猪盘/成人网站/黄色网站/庞氏骗局/资金盘/高薪招聘/厂家直销/断子绝孙/智障/智障操作/死开）。后果链条：写库触发 MySQL 1366 → 异常 message 内嵌原始坏字节 → `json_encode` 失败 → 「导入内置词库」接口返回 500「Malformed UTF-8 characters」**掩盖真实根因**。改用带 `/u` 的 Unicode 正则只剥离首尾边界；并在 Service 异常包装处净化 `getMessage()`，确保错误信息始终可读可编码 |
| 1.4.0 | 2026-11-05 | 内置词库由 44 条扩充至 **192 条**（辱骂攻击 50 / 广告推广 41 / 诈骗违禁 69 / 色情低俗 32），按「明确违法 block / 不雅 replace / 高误伤 warn」分级；导入改为**按词库文件指纹增量补齐**，升级后自动补入新词且保留管理员对既有词条的手工调整（旧实现以「存在 builtin 词条」为跳过条件，扩充对已安装站点无效）；批量导入消除逐条查库的 N+1；涉政类按原设计不内置具体词条 |
| 1.3.0 | 2026-11-05 | 「词库维护」页「导出词库」面板改为「词库导入 / 导出」双 Tab：新增文件导入入口（txt/csv/zip，支持冲突策略与默认分类），导出默认格式改为 txt，本站导出物可原样导入其他站点；移除发布相关说明。修复 zip 导入调用未定义方法导致上传 zip 致命错误的缺陷；zip 内词库文件定位放宽（words.txt → 任意 .txt → .csv → .dic） |
| 1.2.0 | 2026-11-05 | 「过滤设置」页改为按类型 Tab 分类（过滤设置/日志设置/钩子接入/词库设置），单一表单包裹四个分页、底部统一保存全部生效；切换 Tab 自动重渲染表单组件；「重置」改为「还原为已保存值」（重新拉取服务端配置，语义准确）；新增设置页渲染与配置接口测试 |
| 1.1.0 | 2026-11-05 | 「词库维护」页新增导出词库能力：可导出与版本服务发布包同构的 zip（words.txt + meta.json），支持按来源/分类/状态筛选与自定义版本号，导出物可直接上传 Versionsvr 发布；导出四段式格式修复中间空段丢失导致的字段错位问题 |
| 1.0.0 | 2026-11-05 | 初始版本 |
