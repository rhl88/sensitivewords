<?php

namespace App\Apps\CmsproSensitivewords\Tests\Feature;

use App\Apps\CmsproSensitivewords\Models\SensitiveWord;
use App\Apps\CmsproSensitivewords\Services\SensitiveLibraryService;
use App\Apps\CmsproSensitivewords\Tests\SensitivewordsTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * 词库文件导入测试（txt / csv / zip）。
 *
 * 重点覆盖两类此前缺失的场景：
 * 1. zip 导入 —— importFromFile 曾调用未定义的 importFromZip()，上传 zip 必然
 *    致命错误（Error: Call to undefined method），因无测试覆盖而长期未暴露；
 * 2. 导出 → 导入往返 —— 保证本站导出的文件、以及他人分享的词库包能被原样读回，
 *    词条属性（动作/级别/分类）在往返中不丢失、不错位。
 */
class LibraryImportTest extends SensitivewordsTestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    private function library(): SensitiveLibraryService
    {
        return app(SensitiveLibraryService::class);
    }

    /**
     * 构造 txt/csv 词库上传文件。
     */
    private function textFile(string $content, string $name = 'words.txt'): UploadedFile
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'imp_' . uniqid('', true) . '.' . pathinfo($name, PATHINFO_EXTENSION);
        File::put($path, $content);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, 'text/plain', null, true);
    }

    /**
     * 构造 zip 词库上传文件。
     *
     * @param array<string,string> $entries 包内文件名 => 内容
     * @param string|null $wrapDir 非空时套一层包裹目录
     */
    private function zipFile(array $entries, ?string $wrapDir = null, string $name = 'wordpack.zip'): UploadedFile
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'imp_' . uniqid('', true) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $prefix = $wrapDir !== null ? $wrapDir . '/' : '';
        foreach ($entries as $entryName => $content) {
            $zip->addFromString($prefix . $entryName, $content);
        }
        $zip->close();

        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }

    // ══ txt / csv 导入 ══════════════════════════════════════════════

    public function test_import_txt_file(): void
    {
        $file = $this->textFile("赌博,block,3,fraud\n广告词,replace\n");

        $result = $this->library()->importFromFile($file, 'skip');

        $this->assertSame(2, $result['inserted']);
        $this->assertSame('block', SensitiveWord::where('word', '赌博')->value('action'));
        $this->assertSame(SensitiveWord::SOURCE_IMPORT, SensitiveWord::where('word', '赌博')->value('source'));
    }

    public function test_import_csv_file(): void
    {
        $file = $this->textFile("赌博,block,3,fraud\n色情,replace,3,porn", 'words.csv');

        $result = $this->library()->importFromFile($file, 'skip');

        $this->assertSame(2, $result['inserted']);
    }

    public function test_import_gbk_encoded_file(): void
    {
        // GBK 词库需自动转 UTF-8，否则中文全部乱码导致漏过滤
        $gbk = mb_convert_encoding("赌博,block,3\n代开发票,replace,2", 'GBK', 'UTF-8');
        $file = $this->textFile($gbk);

        $this->library()->importFromFile($file, 'skip');

        $this->assertNotNull(SensitiveWord::where('word', '赌博')->first());
        $this->assertNotNull(SensitiveWord::where('word', '代开发票')->first());
    }

    public function test_import_rejects_unsupported_extension(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'imp_' . uniqid('', true) . '.png';
        File::put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC'));
        $this->tempFiles[] = $path;
        $file = new UploadedFile($path, 'img.png', 'image/png', null, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('仅支持');

        $this->library()->importFromFile($file, 'skip');
    }

    // ══ zip 导入（此前缺失覆盖，回归防护）══════════════════════════════

    public function test_import_zip_with_words_txt(): void
    {
        $zip = $this->zipFile(['words.txt' => "赌博,block,3,fraud\n广告词,replace"]);

        $result = $this->library()->importFromFile($zip, 'skip');

        $this->assertSame(2, $result['inserted']);
        $this->assertSame('block', SensitiveWord::where('word', '赌博')->value('action'));
    }

    public function test_import_zip_wrapped_in_directory(): void
    {
        $zip = $this->zipFile(['words.txt' => "赌博,block,3"], '词库包');

        $result = $this->library()->importFromFile($zip, 'skip');

        $this->assertSame(1, $result['inserted']);
    }

    public function test_import_zip_with_arbitrary_txt_name(): void
    {
        // 他人随手压缩的词库：文件名不是 words.txt 也应能导入
        $zip = $this->zipFile(['我的敏感词库.txt' => "赌博,block,3\n色情,replace,3"]);

        $result = $this->library()->importFromFile($zip, 'skip');

        $this->assertSame(2, $result['inserted']);
    }

    public function test_import_zip_ignores_meta_json(): void
    {
        // 含 meta.json 的发布包：应只解析 words.txt，不把 meta 当词库
        $zip = $this->zipFile([
            'words.txt' => "赌博,block,3",
            'meta.json' => '{"version":"1.0.0","word_count":1}',
        ]);

        $result = $this->library()->importFromFile($zip, 'skip');

        $this->assertSame(1, $result['inserted']);
        $this->assertNull(SensitiveWord::where('word', 'version')->first());
    }

    public function test_import_zip_rejects_package_without_word_file(): void
    {
        $zip = $this->zipFile(['readme.md' => '# 说明文档']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('未找到词库文件');

        $this->library()->importFromFile($zip, 'skip');
    }

    // ══ 导出 → 导入往返（分享/备份场景）══════════════════════════════

    public function test_exported_txt_can_be_imported_back(): void
    {
        $this->seedWord('赌博', ['action' => 'block', 'level' => 3, 'category_id' => $this->categoryId('fraud')]);
        $this->seedWord('代开票', ['action' => '', 'level' => 3, 'category_id' => $this->categoryId('fraud')]);
        $this->seedWord('广告词', ['action' => 'replace', 'category_id' => $this->categoryId('ad')]);

        // 导出为 txt
        $exported = $this->library()->exportToFile();
        $this->assertSame(3, $exported['word_count']);

        // 清空后重新导入，验证属性无损还原
        $this->seedWord('__placeholder__');
        SensitiveWord::whereNot('word', '__placeholder__')->delete();

        $file = $this->textFile($exported['content'], 'exported.txt');
        $result = $this->library()->importFromFile($file, 'skip');

        $this->assertSame(3, $result['inserted']);

        $gambling = SensitiveWord::where('word', '赌博')->first();
        $this->assertSame('block', $gambling->action);
        $this->assertSame(3, (int) $gambling->level);

        // 关键：动作为空的词条，其级别与分类不得因往返而错位
        $invoice = SensitiveWord::where('word', '代开票')->first();
        $this->assertSame('', $invoice->action);
        $this->assertSame(3, (int) $invoice->level);
        $this->assertSame($this->categoryId('fraud'), (int) $invoice->category_id);
    }

    public function test_exported_zip_can_be_imported_back(): void
    {
        $this->seedWord('赌博', ['action' => 'block', 'level' => 3, 'category_id' => $this->categoryId('fraud')]);
        $this->seedWord('代开票', ['action' => '', 'level' => 2, 'category_id' => $this->categoryId('ad')]);

        // 导出为 zip 发布包，再作为普通词库文件导入（模拟他人分享的包）
        $package = $this->library()->buildReleasePackage();

        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'imp_pkg_' . uniqid('', true) . '.zip';
        File::put($path, $package['content']);
        $this->tempFiles[] = $path;
        $upload = new UploadedFile($path, $package['file_name'], 'application/zip', null, true);

        // 清空后导入
        SensitiveWord::query()->delete();
        $result = $this->library()->importFromFile($upload, 'skip');

        $this->assertSame(2, $result['inserted']);

        $invoice = SensitiveWord::where('word', '代开票')->first();
        $this->assertNotNull($invoice);
        $this->assertSame('', $invoice->action);
        $this->assertSame(2, (int) $invoice->level);
        // 分类按 code 自动创建并归类
        $this->assertGreaterThan(0, (int) $invoice->category_id);
    }

    public function test_import_skip_mode_preserves_local_action(): void
    {
        // 本地已把「赌博」调整为 warn，导入他人词库（block）时 skip 模式不应覆盖
        $this->seedWord('赌博', ['action' => 'warn']);

        $file = $this->textFile("赌博,block,3");
        $this->library()->importFromFile($file, 'skip');

        $this->assertSame('warn', SensitiveWord::where('word', '赌博')->value('action'));
    }

    public function test_import_overwrite_mode_replaces_local_action(): void
    {
        $this->seedWord('赌博', ['action' => 'warn']);

        $file = $this->textFile("赌博,block,3");
        $this->library()->importFromFile($file, 'overwrite');

        $this->assertSame('block', SensitiveWord::where('word', '赌博')->value('action'));
    }

    public function test_import_rebuilds_dictionary_immediately(): void
    {
        // 导入后应立即可被过滤命中，无需手工重建词典
        $file = $this->textFile("新词赌博,block,3");
        $this->library()->importFromFile($file, 'skip');

        $result = $this->filterService()->check('含新词赌博的句子');

        $this->assertSame(1, $result['hit_count']);
        $this->assertFalse($result['passed']);
    }
}
