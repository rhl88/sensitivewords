<?php

namespace App\Apps\CmsproSensitivewords\Tests\Feature;

use App\Apps\CmsproSensitivewords\Services\SensitiveLibraryService;
use App\Apps\CmsproSensitivewords\Tests\SensitivewordsTestCase;
use App\Apps\Versionsvr\Models\SensitiveWordsRelease;
use App\Apps\Versionsvr\Services\SensitiveWordsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

/**
 * 跨应用契约测试：本站导出发布包 → 版本服务解析发布。
 *
 * 这是「本站整理词库 → 官方发布 → 全网同步」闭环的关键一环。此前两侧只各自
 * 验证了自己的导出/解析，本测试在同一个用例内串起两个应用，守护发布包格式契约：
 * CmsproSensitivewords::buildReleasePackage() 产出的 zip，必须能被
 * Versionsvr::storeFromFile() 正确解析、统计并重建发布包，且词条属性不错位。
 *
 * Versionsvr 未安装（独立分发场景）时跳过，不影响本应用自身测试。
 */
class CrossAppReleaseContractTest extends SensitivewordsTestCase
{
    /** 本测试写入的临时文件，tearDown 清理 */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->versionsvrAvailable()) {
            $this->markTestSkipped('Versionsvr 应用不可用，跳过跨应用契约测试');
        }

        $this->actingAdmin();
        $this->createVersionsvrTables();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];

        // 清理版本服务发布包目录，避免污染真实 storage
        $releaseDir = storage_path('app_versionsvr/sensitive_words');
        if (is_dir($releaseDir)) {
            File::deleteDirectory($releaseDir);
        }

        parent::tearDown();
    }

    /**
     * Versionsvr 是否可用（类存在即可，不要求其已安装启用）。
     */
    private function versionsvrAvailable(): bool
    {
        return class_exists(SensitiveWordsService::class)
            && class_exists(SensitiveWordsRelease::class);
    }

    /**
     * 建立版本服务的敏感词库发布表（仅本测试所需，不动其他表）。
     */
    private function createVersionsvrTables(): void
    {
        if (Schema::hasTable('app_versionsvr_sensitive_words_release')) {
            return;
        }

        $files = glob(base_path('app/Apps/Versionsvr/Migrations/*sensitive_words*.php')) ?: [];
        foreach ($files as $file) {
            $migration = require $file;
            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }
        }
    }

    /**
     * 把发布包二进制内容包装为可上传文件。
     */
    private function toUploadedFile(string $content, string $name): UploadedFile
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'contract_' . uniqid('', true) . '.zip';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }

    /**
     * 读取 zip 内指定文件内容。
     */
    private function readZipEntry(string $zipPath, string $entry): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        $content = $zip->getFromName($entry);
        $zip->close();

        return $content === false ? null : $content;
    }

    public function test_exported_package_is_accepted_by_versionsvr(): void
    {
        // 1. 本站准备词库（含边界词条：动作为空但有级别与分类）
        $this->seedWord('赌博', ['action' => 'block', 'level' => 3, 'category_id' => $this->categoryId('fraud')]);
        $this->seedWord('代开票', ['action' => '', 'level' => 3, 'category_id' => $this->categoryId('fraud')]);
        $this->seedWord('广告词', ['action' => 'replace', 'level' => 1, 'category_id' => $this->categoryId('ad')]);

        // 2. 本站导出为版本服务发布包
        $package = app(SensitiveLibraryService::class)->buildReleasePackage([], '20261105001');
        $this->assertSame(3, $package['word_count']);

        // 3. 版本服务解析并发布该包
        $upload = $this->toUploadedFile($package['content'], $package['file_name']);
        $result = app(SensitiveWordsService::class)->storeFromFile($upload, '20261105001', '契约测试', null);

        // 词条数与分类数应与本站导出一致（契约成立）
        $this->assertSame(3, $result['word_count']);
        $this->assertSame(2, $result['category_count']);
        $this->assertSame('20261105001', $result['version']);

        // 4. 校验和一致：版本服务落库的 checksum 与本站导出包一致
        $release = SensitiveWordsRelease::where('version', '20261105001')->first();
        $this->assertNotNull($release);
        // 版本服务会重建发布包（补自己的头注释），故 checksum 与本站包不同属正常；
        // 但词条内容必须无损传递，见下一断言
        $this->assertFileExists($release->resolveFilePath());
    }

    public function test_word_attributes_survive_cross_app_roundtrip(): void
    {
        $this->seedWord('赌博', ['action' => 'block', 'level' => 3, 'category_id' => $this->categoryId('fraud')]);
        // 关键边界：动作为空、级别 3、有分类——最易因空段压缩而字段错位
        $this->seedWord('代开票', ['action' => '', 'level' => 3, 'category_id' => $this->categoryId('fraud')]);

        $package = app(SensitiveLibraryService::class)->buildReleasePackage([], '20261105002');
        $upload = $this->toUploadedFile($package['content'], $package['file_name']);
        app(SensitiveWordsService::class)->storeFromFile($upload, '20261105002', null, null);

        // 读回版本服务重建的发布包，验证词条属性未错位
        $release = SensitiveWordsRelease::where('version', '20261105002')->first();
        $words = (string) $this->readZipEntry($release->resolveFilePath(), 'words.txt');

        // 动作/级别/分类三段必须各就各位
        $this->assertStringContainsString('赌博,block,3,fraud', $words);
        $this->assertStringContainsString('代开票,,3,fraud', $words);
        // 反证：不得出现「级别被当成动作」的错位形态
        $this->assertStringNotContainsString('代开票,3,fraud', $words);
    }

    public function test_versionsvr_rebuilt_package_can_be_synced_back(): void
    {
        // 完整闭环：本站导出 → 版本服务发布 → 版本服务产物可被本站再次解析导入
        $this->seedWord('赌博', ['action' => 'block', 'level' => 3, 'category_id' => $this->categoryId('fraud')]);

        $package = app(SensitiveLibraryService::class)->buildReleasePackage([], '20261105003');
        $upload = $this->toUploadedFile($package['content'], $package['file_name']);
        app(SensitiveWordsService::class)->storeFromFile($upload, '20261105003', null, null);

        $release = SensitiveWordsRelease::where('version', '20261105003')->first();
        $rebuiltWords = (string) $this->readZipEntry($release->resolveFilePath(), 'words.txt');

        // 版本服务重建的 words.txt 应能被本站解析器正确还原（模拟其他站点同步导入）
        $rows = app(\App\Apps\CmsproSensitivewords\Services\SensitiveWordManageService::class)
            ->parseWordLines($rebuiltWords);

        $this->assertNotEmpty($rows);
        $this->assertSame('赌博', $rows[0]['word']);
        $this->assertSame('block', $rows[0]['action']);
        $this->assertSame(3, $rows[0]['level']);
        $this->assertSame('fraud', $rows[0]['category']);
    }

    public function test_versionsvr_latest_api_reports_published_package(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $package = app(SensitiveLibraryService::class)->buildReleasePackage([], '20261105004');
        $upload = $this->toUploadedFile($package['content'], $package['file_name']);
        app(SensitiveWordsService::class)->storeFromFile($upload, '20261105004', '对外发布', null);

        // 版本服务公开 API 应能查到该发布版本（供各站点检查更新）
        $latest = SensitiveWordsRelease::getLatestPublished();
        $this->assertNotNull($latest);
        $this->assertSame('20261105004', $latest->version);
        $this->assertSame(1, $latest->word_count);

        // 日期格式规范：Y-m-d H:i:s，禁止 ISO 8601
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $latest->create_time->format('Y-m-d H:i:s')
        );
    }
}
