<?php

namespace App\Apps\CmsproSensitivewords\Services;

use App\Apps\CmsproSensitivewords\Models\SensitiveCategory;
use App\Apps\CmsproSensitivewords\Models\SensitiveWord;
use App\Enums\Status;
use App\Helpers\AppPathHelper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * 词库维护服务：内置基础词库、文件导入导出、在线词库更新（对接版本服务 Versionsvr）。
 *
 * 词库来源共三种，通过 words.source 区分，互不干扰：
 * - builtin ：安装时导入的内置基础词库（Storage/base_words.txt）
 * - manual/import：后台手工添加与文件导入
 * - library ：在线词库（由 Versionsvr「敏感词库」模块发布，各站点在线同步）
 *
 * 在线更新走 Versionsvr 公开 API（与开发助手同步规则技能包同一套机制）：
 *   GET {api_url}/api/versionsvr/sensitive-words/latest   → 版本、词条数、校验值
 *   GET {api_url}/api/versionsvr/sensitive-words/download/{version} → 词库 zip
 * 下载后校验 SHA-256，解包读取 words.txt，按 merge/replace 两种模式落库。
 */
class SensitiveLibraryService
{
    /** 词库包下载与临时目录（相对 storage/） */
    private const PACK_DIR = 'app_cmspro_sensitivewords/wordpack';

    private const TEMP_DIR = 'app_cmspro_sensitivewords/temp';

    /** 词库包内的固定文件名 */
    private const WORDS_FILE = 'words.txt';

    private const META_FILE = 'meta.json';

    /** 内置基础词库文件（应用目录内，随应用分发） */
    private const BASE_WORDS_FILE = 'Storage/base_words.txt';

    public function __construct(
        protected SensitiveWordManageService $manage,
        protected SensitiveWordService $filter
    ) {
    }

    // ══════════════════════════════════════════════════════════════
    // 一、内置基础词库
    // ══════════════════════════════════════════════════════════════

    /**
     * 导入内置常见词库（安装与升级时调用，增量补齐语义）。
     *
     * 幂等策略：以词库文件内容的指纹（SHA-256）判断是否需要导入——
     * - 指纹与上次导入一致：跳过（无重复导入开销）
     * - 指纹不同（应用升级扩充了词库）：以 skip 模式补齐——新词插入，
     *   已存在的词条跳过，**保留管理员对既有词条的手工调整**（如改过动作/分类）
     *
     * 这样「扩充内置词库」对已安装站点也能生效：升级后自动补入新增词条，
     * 而不会覆盖站点已有的个性化配置。
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,unchanged:bool}
     */
    public function importBuiltinWords(): array
    {
        $path = base_path('app/Apps/CmsproSensitivewords/' . self::BASE_WORDS_FILE);
        if (! is_file($path)) {
            return ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'unchanged' => true];
        }

        $content = (string) File::get($path);
        $hash = hash('sha256', $content);

        // 指纹一致：词库未变化，无需重复导入
        if ($this->localBuiltinHash() === $hash) {
            return ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'unchanged' => true];
        }

        // skip 模式：新增词条插入，已存在词条保留本地配置不动
        $stat = $this->importFromText(
            $content,
            SensitiveWord::SOURCE_BUILTIN,
            'skip',
            'builtin'
        );

        // 记录本次导入的词库指纹，供下次升级比对
        $this->saveBuiltinHash($hash);

        return $stat + ['unchanged' => false];
    }

    /**
     * 本地已导入的内置词库指纹（空表示从未导入）。
     */
    public function localBuiltinHash(): string
    {
        try {
            return (string) $this->config('builtin_library_hash', '');
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * 记录内置词库指纹（写入配置项，随应用卸载一并清理）。
     */
    protected function saveBuiltinHash(string $hash): void
    {
        try {
            $code = 'app_cmspro_sensitivewords_builtin_library_hash';
            $item = \App\Models\ConfigItem::where('code', $code)->first();

            if ($item !== null) {
                $item->update(['value' => $hash]);
            } else {
                $group = \App\Models\ConfigGroup::where('code', 'app_cmspro_sensitivewords_library_settings')->first();
                if ($group === null) {
                    return;
                }

                \App\Models\ConfigItem::create([
                    'code' => $code,
                    'group_id' => $group->id,
                    'name' => '内置词库指纹',
                    'type' => 'text',
                    'value' => $hash,
                    'tips' => '内置词库文件的内容指纹，用于升级时增量补齐新词，由系统自动维护，请勿手工修改',
                    'sort' => 98,
                    'status' => Status::ENABLED->value,
                ]);
            }

            if (function_exists('cmspro_sensitivewords_forget_config_cache')) {
                cmspro_sensitivewords_forget_config_cache(['builtin_library_hash']);
            }
        } catch (Throwable $e) {
            Log::warning('[cmspro.sensitivewords] 内置词库指纹记录失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 内置常见词库词条数（供后台展示与文档核对）。
     */
    public function builtinWordCount(): int
    {
        $path = base_path('app/Apps/CmsproSensitivewords/' . self::BASE_WORDS_FILE);
        if (! is_file($path)) {
            return 0;
        }

        return count($this->manage->parseWordLines((string) File::get($path)));
    }

    // ══════════════════════════════════════════════════════════════
    // 二、文件导入导出
    // ══════════════════════════════════════════════════════════════

    /**
     * 从上传文件导入词库（支持 txt/csv 纯文本与 zip 包）。
     *
     * zip 包按在线词库格式解析（words.txt + meta.json），txt/csv 按行解析。
     *
     * @param string $mode 冲突处理：skip/update/overwrite
     * @return array{total:int,inserted:int,updated:int,skipped:int,categories:array<int,string>}
     * @throws RuntimeException 文件格式不合法或解析为空时
     */
    public function importFromFile(UploadedFile $file, string $mode = 'skip', int $defaultCategoryId = 0): array
    {
        $extension = mb_strtolower((string) $file->getClientOriginalExtension());
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw new RuntimeException('上传文件不可读');
        }

        if ($extension === 'zip') {
            return $this->importFromZip($realPath, $mode, $defaultCategoryId);
        }

        if (! in_array($extension, ['txt', 'csv', 'dic', ''], true)) {
            throw new RuntimeException('仅支持 txt / csv / zip 格式的词库文件');
        }

        $content = (string) File::get($realPath);
        $content = $this->convertToUtf8($content);

        return $this->importFromText($content, SensitiveWord::SOURCE_IMPORT, $mode, 'import', $defaultCategoryId);
    }

    /**
     * 从 zip 包导入词库。
     *
     * 兼容三类 zip：本站/其他站点导出的词库包（内含 words.txt）、版本服务下载的
     * 官方词库发布包、以及把 txt 词库随手压缩的包（回退匹配任意 .txt）。
     *
     * @param string $mode 冲突处理：skip/update/overwrite
     * @return array{total:int,inserted:int,updated:int,skipped:int,categories:array<int,string>}
     *
     * @throws RuntimeException 包结构不合法或解析为空时
     */
    protected function importFromZip(string $zipPath, string $mode, int $defaultCategoryId): array
    {
        $content = $this->readWordsFromZip($zipPath);

        return $this->importFromText($content, SensitiveWord::SOURCE_IMPORT, $mode, 'import', $defaultCategoryId);
    }

    /**
     * 从文本内容导入词库。
     *
     * @param string $content 词库文本
     * @param string $source   来源标记（import/library/builtin）
     * @param string $mode     冲突处理策略：skip=跳过 update=补齐 overwrite=覆盖（merge 等价 update）
     * @param string $defaultCategoryKey 未声明分类时归入的分类（取 SOURCE_CATEGORIES 映射，空表示未分类）
     * @return array{total:int,inserted:int,updated:int,skipped:int,categories:array<int,string>}
     */
    public function importFromText(
        string $content,
        string $source,
        string $mode = 'skip',
        string $defaultCategoryKey = '',
        int $defaultCategoryId = 0
    ): array {
        $rows = $this->manage->parseWordLines($content);
        if ($rows === []) {
            throw new RuntimeException('未从文件中解析到任何有效词条');
        }

        $categoryNames = array_values(array_unique(array_filter(array_column($rows, 'category'))));

        if ($defaultCategoryId <= 0 && $defaultCategoryKey !== '') {
            $defaultCategoryId = (int) $this->ensureSourceCategory($defaultCategoryKey)->id;
        }

        // merge 与 update 同义：仅补齐空动作/空分类，保留管理员的手工调整
        $importMode = match ($mode) {
            'overwrite', 'replace' => 'overwrite',
            'merge', 'update' => 'update',
            default => 'skip',
        };

        $stat = $this->manage->importRows($rows, $source, $importMode, $defaultCategoryId);

        return $stat + ['categories' => $categoryNames];
    }

    /**
     * 导出词库为纯文本下载内容。
     *
     * @param array<string,mixed> $conditions 过滤条件（source/sources/category_id/status）
     * @return array{content:string,file_name:string,word_count:int,category_count:int}
     */
    public function exportToFile(array $conditions = []): array
    {
        $exported = $this->manage->exportWords($conditions);
        $suffix = $this->exportSuffix($conditions);

        return [
            'content' => $exported['content'],
            'file_name' => 'cmspro-sensitivewords-' . $suffix . '-' . date('YmdHis') . '.txt',
            'word_count' => $exported['word_count'],
            'category_count' => $exported['category_count'],
        ];
    }

    /**
     * 导出为「版本服务发布包」（zip），可直接上传到 Versionsvr「敏感词库」发布。
     *
     * 发布包结构与 Versionsvr SensitiveWordsService 的规范化产物完全同构：
     *   words.txt —— 标准四段式词库（带版本头注释）
     *   meta.json —— {version, word_count, category_count, categories, generated_at, format, source_site}
     * 这样「本站导出 → 版本服务发布 → 其他站点在线同步」形成完整闭环，
     * 消费端（含本应用的在线同步）无需任何适配即可直接消费。
     *
     * @param array<string,mixed> $conditions 过滤条件（source/sources/category_id/status）
     * @param string|null $version 词库版本号，留空自动生成 YYYYMMDDHHMMSS
     * @return array{content:string,file_name:string,word_count:int,category_count:int,version:string,checksum:string,size:int}
     *
     * @throws RuntimeException 词库为空或打包失败时
     */
    public function buildReleasePackage(array $conditions = [], ?string $version = null): array
    {
        $exported = $this->manage->exportWords($conditions);

        if ($exported['word_count'] === 0) {
            throw new RuntimeException('当前筛选条件下没有可导出的词条');
        }

        $version = $this->resolveExportVersion($version);

        // words.txt：复用 exportWords 的规范化内容，替换头部版本注释为发布包版本
        $wordsContent = $this->buildReleaseWordsContent($exported['content'], $version, $exported);

        $meta = json_encode([
            'version' => $version,
            'word_count' => $exported['word_count'],
            'category_count' => $exported['category_count'],
            'categories' => $exported['categories'],
            'generated_at' => date('Y-m-d H:i:s'),
            'format' => 'word,action,level,category',
            'source_site' => (string) config('app.url', ''),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $content = $this->makeZipInMemory([
            self::WORDS_FILE => $wordsContent,
            self::META_FILE => (string) $meta,
        ]);

        $fileName = 'cmspro-sensitivewords-' . $version . '.zip';

        return [
            'content' => $content,
            'file_name' => $fileName,
            'word_count' => $exported['word_count'],
            'category_count' => $exported['category_count'],
            'version' => $version,
            'checksum' => hash('sha256', $content),
            'size' => strlen($content),
        ];
    }

    /**
     * 生成发布包 words.txt 内容（在导出文本前补发布包头注释）。
     *
     * @param array{content:string,word_count:int,category_count:int,categories:array<int,string>} $exported
     */
    protected function buildReleaseWordsContent(string $exportedContent, string $version, array $exported): string
    {
        // 去掉 exportWords 自带的头注释（以 # 开头的连续行），改用发布包头
        $bodyLines = [];
        foreach (preg_split('/\r\n|\r|\n/', $exportedContent) ?: [] as $line) {
            if (str_starts_with($line, '#') || trim($line) === '') {
                // 头部注释与空行跳过；词条行不以 # 开头故不受影响
                continue;
            }
            $bodyLines[] = $line;
        }

        $header = [
            '# CmsPro 官方敏感词库（发布包）',
            '# 版本：' . $version,
            '# 生成时间：' . date('Y-m-d H:i:s'),
            '# 词条数：' . $exported['word_count'] . '　分类数：' . $exported['category_count'],
            '# 格式：敏感词,动作(block/replace/warn),级别(1-3),分类code',
            '# 由敏感词过滤应用导出，供版本服务（Versionsvr）发布、各站点在线同步',
            '',
        ];

        return implode("\n", $header) . implode("\n", $bodyLines) . ($bodyLines === [] ? '' : "\n");
    }

    /**
     * 在内存中构建 zip（不落磁盘，直接作为下载响应体）。
     *
     * ZipArchive 需要文件路径，故写入系统临时目录后读回并删除。
     *
     * @param array<string,string> $files 文件名 => 内容
     * @return string zip 二进制内容
     *
     * @throws RuntimeException 打包失败时
     */
    protected function makeZipInMemory(array $files): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'sw_pkg_');
        if ($tmpPath === false) {
            throw new RuntimeException('无法创建临时文件，导出失败');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('创建导出包失败');
            }

            foreach ($files as $name => $content) {
                $zip->addFromString($name, $content);
            }
            $zip->close();

            $content = File::get($tmpPath);
            if ($content === false || $content === '') {
                throw new RuntimeException('导出包内容为空');
            }

            return $content;
        } finally {
            if (File::exists($tmpPath)) {
                File::delete($tmpPath);
            }
        }
    }

    /**
     * 解析导出版本号：显式传入则用之，否则按 YYYYMMDDHHMMSS 生成。
     */
    protected function resolveExportVersion(?string $version): string
    {
        $version = trim((string) $version);

        return $version !== '' ? mb_substr($version, 0, 32) : date('YmdHis');
    }

    /**
     * 导出文件名来源后缀（多来源用 all，单一来源用其标识）。
     *
     * @param array<string,mixed> $conditions
     */
    protected function exportSuffix(array $conditions): string
    {
        if (! empty($conditions['source'])) {
            return (string) $conditions['source'];
        }
        if (! empty($conditions['sources'])) {
            $sources = is_array($conditions['sources']) ? $conditions['sources'] : explode(',', (string) $conditions['sources']);
            $sources = array_values(array_filter(array_map(fn ($s) => trim((string) $s), $sources), fn ($s) => $s !== ''));
            if (count($sources) === 1) {
                return $sources[0];
            }
        }

        return 'all';
    }

    // ══════════════════════════════════════════════════════════════
    // 三、在线词库更新（对接 Versionsvr）
    // ══════════════════════════════════════════════════════════════

    /**
     * 查询远端最新词库版本信息。
     *
     * @return array{available:bool,message:string,version:string,word_count:int,file_size:int,checksum:string,release_time:string,changelog:string,local_version:string,local_count:int,need_update:bool}
     */
    public function checkRemoteLibrary(): array
    {
        $localVersion = $this->localLibraryVersion();
        $localCount = SensitiveWord::where('source', SensitiveWord::SOURCE_LIBRARY)->count();

        $base = [
            'available' => false,
            'message' => '',
            'version' => '',
            'word_count' => 0,
            'file_size' => 0,
            'checksum' => '',
            'release_time' => '',
            'changelog' => '',
            'local_version' => $localVersion,
            'local_count' => $localCount,
            'need_update' => false,
        ];

        if (! $this->remoteEnabled()) {
            return $base + ['message' => '在线词库更新已在「过滤设置 → 词库设置」中关闭'];
        }

        try {
            $response = $this->httpClient()->get($this->apiUrl('/api/versionsvr/sensitive-words/latest'));

            if (! $response->successful()) {
                return $base + ['message' => '版本服务返回异常（HTTP ' . $response->status() . '）'];
            }

            $payload = $response->json();
            if ((int) ($payload['code'] ?? -1) !== 0 || empty($payload['data'])) {
                return $base + ['message' => (string) ($payload['message'] ?? '暂无已发布的官方词库')];
            }

            $data = $payload['data'];
            $version = (string) ($data['version'] ?? '');

            return $base + [
                'available' => true,
                'message' => 'ok',
                'version' => $version,
                'word_count' => (int) ($data['word_count'] ?? 0),
                'file_size' => (int) ($data['file_size'] ?? 0),
                'checksum' => (string) ($data['checksum'] ?? ''),
                'release_time' => (string) ($data['release_time'] ?? ''),
                'changelog' => (string) ($data['changelog'] ?? ''),
                'need_update' => $version !== '' && $version !== $localVersion,
            ];
        } catch (Throwable $e) {
            Log::warning('[cmspro.sensitivewords] 词库版本检查失败', ['error' => $e->getMessage()]);

            return $base + ['message' => '无法连接版本服务：' . $e->getMessage()];
        }
    }

    /**
     * 下载并同步官方词库。
     *
     * @param string|null $version 指定版本，null 表示最新版本
     * @param string|null $mode    merge=增量合并 replace=覆盖官方库，null 表示取配置值
     * @return array{version:string,total:int,inserted:int,updated:int,skipped:int,removed:int,mode:string}
     * @throws RuntimeException 下载失败、校验失败或解析为空时
     */
    public function syncRemoteLibrary(?string $version = null, ?string $mode = null): array
    {
        if (! $this->remoteEnabled()) {
            throw new RuntimeException('在线词库更新已关闭');
        }

        $mode = $mode === 'replace' ? 'replace' : ($mode === 'merge' ? 'merge' : $this->remoteMode());
        $info = $this->checkRemoteLibrary();
        if (! $info['available']) {
            throw new RuntimeException($info['message'] !== '' ? $info['message'] : '版本服务不可用');
        }

        $version = trim((string) $version) !== '' ? trim((string) $version) : $info['version'];
        $expectedChecksum = $version === $info['version'] ? $info['checksum'] : '';

        $packDir = storage_path(self::PACK_DIR);
        $this->ensureDir($packDir);
        $zipPath = $packDir . '/cmspro-sensitivewords-' . $version . '.zip';

        try {
            $response = $this->httpClient()->get(
                $this->apiUrl('/api/versionsvr/sensitive-words/download/' . rawurlencode($version))
            );

            if (! $response->successful()) {
                throw new RuntimeException('词库包下载失败（HTTP ' . $response->status() . '）');
            }

            File::put($zipPath, $response->body());

            if ($expectedChecksum !== '' && hash_file('sha256', $zipPath) !== $expectedChecksum) {
                throw new RuntimeException('词库包校验失败，可能已损坏或被篡改');
            }

            $wordsContent = $this->readWordsFromZip($zipPath);

            // replace 模式：先清除旧的官方来源词条（本地手工词条不受影响）
            $removed = 0;
            if ($mode === 'replace') {
                $removed = $this->manage->deleteWordsBy(['source' => SensitiveWord::SOURCE_LIBRARY]);
            }

            $stat = $this->importFromText(
                $wordsContent,
                SensitiveWord::SOURCE_LIBRARY,
                $mode === 'replace' ? 'overwrite' : 'merge',
                'library',
                (int) $this->config('library_category_id', '0')
            );

            $this->saveLibraryVersion($version);
            $this->cleanOldPacks($packDir);

            Log::info('[cmspro.sensitivewords] 官方词库同步完成', [
                'version' => $version,
                'mode' => $mode,
                'inserted' => $stat['inserted'],
            ]);

            return [
                'version' => $version,
                'total' => $stat['total'],
                'inserted' => $stat['inserted'],
                'updated' => $stat['updated'],
                'skipped' => $stat['skipped'],
                'removed' => $removed,
                'mode' => $mode,
            ];
        } catch (Throwable $e) {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }

            throw $e instanceof RuntimeException ? $e : new RuntimeException('词库同步失败：' . $e->getMessage());
        }
    }

    /**
     * 本地已同步的官方词库版本号（空字符串表示从未同步）。
     */
    public function localLibraryVersion(): string
    {
        try {
            // 走配置读取函数（带缓存），同步完成后由 saveLibraryVersion() 主动清缓存
            return (string) $this->config('library_version', '');
        } catch (Throwable $e) {
            return '';
        }
    }

    // ══════════════════════════════════════════════════════════════
    // 四、内部工具
    // ══════════════════════════════════════════════════════════════

    /**
     * 词库来源 → 默认归类（内置词库与在线词库分别归入独立分类，便于分来源管理与统计）。
     */
    private const SOURCE_CATEGORIES = [
        'builtin' => ['code' => 'builtin', 'name' => '内置基础词库', 'sort' => 1],
        'library' => ['code' => 'official', 'name' => '官方词库', 'sort' => 2],
        'import' => ['code' => 'imported', 'name' => '文件导入', 'sort' => 3],
    ];

    /**
     * 取来源默认分类（不存在则按固定 code 创建，保证幂等与归类稳定）。
     */
    protected function ensureSourceCategory(string $sourceKey): SensitiveCategory
    {
        $preset = self::SOURCE_CATEGORIES[$sourceKey] ?? null;
        if ($preset === null) {
            return $this->ensureCategory($sourceKey);
        }

        $category = SensitiveCategory::where('code', $preset['code'])->first();
        if ($category !== null) {
            return $category;
        }

        return $this->manage->createCategory([
            'name' => $preset['name'],
            'code' => $preset['code'],
            'description' => '系统内置分类，用于归类' . $preset['name'] . '词条',
            'is_system' => 1,
            'sort' => $preset['sort'],
        ]);
    }

    /**
     * 取分类（不存在则按 code/名称创建）。
     */
    protected function ensureCategory(string $codeOrName): SensitiveCategory
    {
        $category = SensitiveCategory::where('code', $codeOrName)->orWhere('name', $codeOrName)->first();
        if ($category !== null) {
            return $category;
        }

        return $this->manage->createCategory([
            'name' => $codeOrName,
            'code' => $codeOrName,
            'description' => '词库导入自动创建的分类',
            'sort' => 50,
        ]);
    }

    /**
     * 解包 zip 并读取词库文件（兼容包内多一层包裹目录）。
     *
     * 文件定位按优先级逐级放宽：words.txt → 任意 .txt → .csv → .dic，
     * 使本站导出的发布包、版本服务下载的官方包、他人随手压缩的词库都能导入。
     *
     * @throws RuntimeException 包结构不合法或找不到词库文件时
     */
    protected function readWordsFromZip(string $zipPath): string
    {
        $tempDir = storage_path(self::TEMP_DIR) . '/library_' . uniqid('', true);
        $this->ensureDir($tempDir);

        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('词库包损坏或无法打开');
            }

            // 防 Zip Slip
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = (string) $zip->getNameIndex($i);
                if (str_contains($entryName, '..')
                    || str_starts_with($entryName, '/')
                    || preg_match('/^[A-Za-z]:/', $entryName) === 1) {
                    $zip->close();
                    throw new RuntimeException('词库包包含非法的路径条目');
                }
            }

            if ($zip->extractTo($tempDir) !== true) {
                $zip->close();
                throw new RuntimeException('词库包解包失败');
            }
            $zip->close();

            // 候选词库文件：优先 words.txt，其次任意 .txt/.csv/.dic（兼容他人导出的包）。
            // 逐级放宽并只取第一个命中，meta.json 等非词库文件天然被扩展名过滤掉。
            $patterns = [
                $tempDir . '/' . self::WORDS_FILE,
                $tempDir . '/*/' . self::WORDS_FILE,
                $tempDir . '/*.txt',
                $tempDir . '/*/*.txt',
                $tempDir . '/*.csv',
                $tempDir . '/*/*.csv',
                $tempDir . '/*.dic',
                $tempDir . '/*/*.dic',
            ];

            foreach ($patterns as $pattern) {
                $candidates = glob($pattern) ?: [];
                if ($candidates !== []) {
                    return $this->convertToUtf8((string) File::get($candidates[0]));
                }
            }

            throw new RuntimeException('词库包内未找到词库文件（words.txt 或任意 .txt/.csv/.dic）');
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    /**
     * 编码兜底：GBK/GB2312 词库文件统一转 UTF-8（否则中文词条全部乱码漏过滤）。
     */
    protected function convertToUtf8(string $content): string
    {
        // 去除 BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        $converted = mb_convert_encoding($content, 'UTF-8', 'GBK');

        return is_string($converted) && $converted !== '' ? $converted : $content;
    }

    /**
     * 记录本地已同步的词库版本（写入配置项，随应用卸载一并清理）。
     *
     * 写入后必须清掉 cmspro_sensitivewords_config() 的缓存，否则
     * localLibraryVersion() 仍读到旧值，后台会持续提示「有新版本可更新」。
     */
    protected function saveLibraryVersion(string $version): void
    {
        try {
            $code = 'app_cmspro_sensitivewords_library_version';
            $item = \App\Models\ConfigItem::where('code', $code)->first();

            if ($item !== null) {
                $item->update(['value' => $version]);
            } else {
                // 配置项不存在时挂到词库设置组下（组不存在则跳过写入，不影响同步结果）
                $group = \App\Models\ConfigGroup::where('code', 'app_cmspro_sensitivewords_library_settings')->first();
                if ($group === null) {
                    return;
                }

                \App\Models\ConfigItem::create([
                    'code' => $code,
                    'group_id' => $group->id,
                    'name' => '本地词库版本',
                    'type' => 'text',
                    'value' => $version,
                    'tips' => '最近一次在线同步的官方词库版本号（系统自动维护，请勿手工修改）',
                    'sort' => 99,
                    'status' => Status::ENABLED->value,
                ]);
            }

            if (function_exists('cmspro_sensitivewords_forget_config_cache')) {
                cmspro_sensitivewords_forget_config_cache(['library_version']);
            }
        } catch (Throwable $e) {
            Log::warning('[cmspro.sensitivewords] 词库版本记录失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 清理旧词库包（保留最近 3 个，与 manifest runtime_files 声明一致）。
     */
    protected function cleanOldPacks(string $packDir): void
    {
        try {
            $files = glob($packDir . '/*.zip') ?: [];
            if (count($files) <= 3) {
                return;
            }

            usort($files, fn ($a, $b) => (int) filemtime($b) <=> (int) filemtime($a));
            foreach (array_slice($files, 3) as $old) {
                @unlink($old);
            }
        } catch (Throwable $e) {
            // 清理失败不影响同步结果
        }
    }

    /**
     * 词库信息汇总（后台「词库维护」页数据源）。
     *
     * @return array<string,mixed>
     */
    public function libraryInfo(): array
    {
        $bySource = [];
        try {
            $bySource = SensitiveWord::query()
                ->selectRaw('source, COUNT(*) AS c')
                ->groupBy('source')
                ->pluck('c', 'source')
                ->all();
        } catch (Throwable $e) {
            $bySource = [];
        }

        $packDir = storage_path(self::PACK_DIR);

        return [
            'total' => array_sum($bySource),
            'by_source' => [
                'builtin' => (int) ($bySource['builtin'] ?? 0),
                'manual' => (int) ($bySource['manual'] ?? 0),
                'import' => (int) ($bySource['import'] ?? 0),
                'library' => (int) ($bySource['library'] ?? 0),
            ],
            'builtin_available' => is_file(base_path('app/Apps/CmsproSensitivewords/' . self::BASE_WORDS_FILE)),
            'builtin_count' => $this->builtinWordCount(),
            'category_count' => SensitiveCategory::count(),
            'local_version' => $this->localLibraryVersion(),
            'pack_dir' => $packDir,
            'pack_writable' => $this->dirWritable($packDir),
            'remote_enabled' => $this->remoteEnabled(),
            'remote_mode' => $this->remoteMode(),
            'api_base' => rtrim((string) config('apps.market.api_url', 'https://v5.cmspro.cn'), '/'),
            'dictionary_version' => $this->filter->wordTotal(),
        ];
    }

    /**
     * 目录可写性探测（不可写时创建，仍失败返回 false）。
     */
    protected function dirWritable(string $dir): bool
    {
        $this->ensureDir($dir);

        return is_dir($dir) && is_writable($dir);
    }

    protected function ensureDir(string $dir): void
    {
        if (! File::isDirectory($dir)) {
            try {
                File::makeDirectory($dir, 0755, true);
            } catch (Throwable $e) {
                // 目录创建失败由调用方通过 dirWritable 感知
            }
        }
    }

    /**
     * 在线更新开关。
     */
    protected function remoteEnabled(): bool
    {
        return (string) $this->config('remote_update_enabled', '1') === '1';
    }

    /**
     * 在线更新方式（merge/replace）。
     */
    protected function remoteMode(): string
    {
        $mode = (string) $this->config('remote_update_mode', 'merge');

        return $mode === 'replace' ? 'replace' : 'merge';
    }

    /**
     * 构造版本服务 API 完整地址（沿用系统既有 apps.market.api_url 约定）。
     */
    protected function apiUrl(string $path): string
    {
        return rtrim((string) config('apps.market.api_url', 'https://v5.cmspro.cn'), '/') . $path;
    }

    /**
     * HTTP 客户端（超时 30 秒，与 CmsproAidev 同步规则包保持一致的既有约定）。
     */
    protected function httpClient()
    {
        return Http::withOptions(['verify' => false])
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(30);
    }

    /**
     * 读取应用配置。
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        if (function_exists('cmspro_sensitivewords_config')) {
            return cmspro_sensitivewords_config($key, $default);
        }

        return config('apps.cmspro.sensitivewords.' . $key, $default);
    }
}
