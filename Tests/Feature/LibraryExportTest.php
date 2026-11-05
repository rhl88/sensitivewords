<?php

namespace App\Apps\CmsproSensitivewords\Tests\Feature;

use App\Apps\CmsproSensitivewords\Models\SensitiveWord;
use App\Apps\CmsproSensitivewords\Services\SensitiveLibraryService;
use App\Apps\CmsproSensitivewords\Tests\SensitivewordsTestCase;
use RuntimeException;
use ZipArchive;

/**
 * 词库导出为「版本服务发布包」测试。
 *
 * 覆盖：发布包 zip 结构（words.txt + meta.json，与 Versionsvr 规范化产物同构）、
 * meta 元信息、来源/分类筛选、自定义与自动版本号、空词库拒绝导出、
 * 发布包可被 Versionsvr 解析（字段不错位的往返验证）。
 */
class LibraryExportTest extends SensitivewordsTestCase
{
    private function library(): SensitiveLibraryService
    {
        return app(SensitiveLibraryService::class);
    }

    /**
     * 从 zip 二进制内容读取指定文件（写入临时文件后用 ZipArchive 打开）。
     */
    private function readZipEntry(string $zipContent, string $entryName): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sw_test_');
        file_put_contents($tmp, $zipContent);

        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                return null;
            }
            $content = $zip->getFromName($entryName);
            $zip->close();

            return $content === false ? null : $content;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function test_build_release_package_contains_words_and_meta(): void
    {
        $this->seedWord('赌博', ['action' => 'block', 'level' => 3, 'category_id' => $this->categoryId('fraud')]);
        $this->seedWord('广告词', ['action' => 'replace']);

        $package = $this->library()->buildReleasePackage();

        $this->assertSame(2, $package['word_count']);
        $this->assertStringEndsWith('.zip', $package['file_name']);
        $this->assertStringContainsString('cmspro-sensitivewords-', $package['file_name']);
        $this->assertNotEmpty($package['checksum']);
        $this->assertGreaterThan(0, $package['size']);

        $words = $this->readZipEntry($package['content'], 'words.txt');
        $this->assertNotNull($words, '发布包应含 words.txt');
        $this->assertStringContainsString('赌博,block,3,fraud', $words);
        $this->assertStringContainsString('广告词,replace', $words);

        $metaRaw = $this->readZipEntry($package['content'], 'meta.json');
        $this->assertNotNull($metaRaw, '发布包应含 meta.json');
        $meta = json_decode((string) $metaRaw, true);
        $this->assertSame($package['version'], $meta['version']);
        $this->assertSame(2, $meta['word_count']);
        $this->assertSame('word,action,level,category', $meta['format']);
        $this->assertContains('fraud', $meta['categories']);
    }

    public function test_build_release_package_uses_custom_version(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $package = $this->library()->buildReleasePackage([], '20261105001');

        $this->assertSame('20261105001', $package['version']);
        $this->assertSame('cmspro-sensitivewords-20261105001.zip', $package['file_name']);
    }

    public function test_build_release_package_auto_generates_version(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);

        $package = $this->library()->buildReleasePackage([], null);

        $this->assertMatchesRegularExpression('/^\d{14}$/', $package['version']);
    }

    public function test_build_release_package_filters_by_source(): void
    {
        $this->seedWord('手工词', ['action' => 'block']);
        SensitiveWord::create([
            'word' => '在线词', 'word_lower' => '在线词',
            'source' => SensitiveWord::SOURCE_LIBRARY, 'action' => 'block', 'status' => 1,
        ]);

        $package = $this->library()->buildReleasePackage(['sources' => SensitiveWord::SOURCE_LIBRARY]);

        $this->assertSame(1, $package['word_count']);
        $words = $this->readZipEntry($package['content'], 'words.txt');
        $this->assertStringContainsString('在线词', $words);
        $this->assertStringNotContainsString('手工词', $words);
    }

    public function test_build_release_package_rejects_empty_library(): void
    {
        // 无任何词条时应拒绝导出
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('没有可导出的词条');

        $this->library()->buildReleasePackage();
    }

    public function test_release_package_preserves_middle_empty_segment(): void
    {
        // 字段错位回归：动作为空但有级别与分类，导出包内必须保留中间空段
        $this->seedWord('代开票', ['action' => '', 'level' => 3, 'category_id' => $this->categoryId('fraud')]);

        $package = $this->library()->buildReleasePackage();
        $words = (string) $this->readZipEntry($package['content'], 'words.txt');

        $this->assertStringContainsString('代开票,,3,fraud', $words);
    }

    public function test_release_package_words_are_reparseable_by_same_app(): void
    {
        // 往返验证：导出包内 words.txt 可被本应用 parseWordLines 正确解析回四段
        $this->seedWord('赌博', ['action' => 'block', 'level' => 3, 'category_id' => $this->categoryId('fraud')]);
        $this->seedWord('代开票', ['action' => '', 'level' => 2, 'category_id' => $this->categoryId('ad')]);

        $package = $this->library()->buildReleasePackage();
        $words = (string) $this->readZipEntry($package['content'], 'words.txt');

        $rows = app(\App\Apps\CmsproSensitivewords\Services\SensitiveWordManageService::class)->parseWordLines($words);

        $byWord = [];
        foreach ($rows as $row) {
            $byWord[$row['word']] = $row;
        }

        $this->assertSame('block', $byWord['赌博']['action']);
        $this->assertSame(3, $byWord['赌博']['level']);
        $this->assertSame('fraud', $byWord['赌博']['category']);

        // 动作为空、级别与分类不错位
        $this->assertSame('', $byWord['代开票']['action']);
        $this->assertSame(2, $byWord['代开票']['level']);
        $this->assertSame('ad', $byWord['代开票']['category']);
    }
}
