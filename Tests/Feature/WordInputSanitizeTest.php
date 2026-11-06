<?php

namespace App\Apps\CmsproSensitivewords\Tests\Feature;

use App\Apps\CmsproSensitivewords\Models\SensitiveWord;
use App\Apps\CmsproSensitivewords\Services\SensitiveLibraryService;
use App\Apps\CmsproSensitivewords\Services\SensitiveWordManageService;
use App\Apps\CmsproSensitivewords\Tests\SensitivewordsTestCase;

/**
 * 词条输入净化测试（回归防护：trim 字节剥离截断汉字）。
 *
 * 事故背景：normalizeInputWord() 曾用 trim($word, "\"'“”‘’ \t") 去除首尾引号，
 * 但 trim() 的第二参数是「字节集合」而非「字符集合」——中文弯引号 U+201C/201D/
 * U+2018/2019 被拆成字节 E2 80 9C / E2 80 9D / E2 80 98 / E2 80 99 混入集合，
 * 于是任何尾字节为 80/98/99/9C/9D 的汉字都会被咬掉一个字节：
 *
 *   智障   E699BA E99A9C → E699BA E99A   （非法 UTF-8）
 *   传销   E4BCA0 E99480 → E4BCA0 E994   （非法 UTF-8）
 *   断子绝孙 ... E5AD99   → ... E5AD     （非法 UTF-8）
 *
 * 后果链条：非法 UTF-8 写库 → MySQL 1366 Incorrect string value →
 * QueryException message 内嵌原始坏字节 → 透传至 ApiResponse::error() →
 * json_encode 失败 → 接口返回 500 "Malformed UTF-8 characters"，
 * 掩盖了真实根因。内置词库 192 条中 12 条受影响（传销/杀猪盘/成人网站等高频词）。
 */
class WordInputSanitizeTest extends SensitivewordsTestCase
{
    private function manage(): SensitiveWordManageService
    {
        return app(SensitiveWordManageService::class);
    }

    /**
     * 受该缺陷影响的真实词条（尾字节落在 trim 剥离集合内）。
     *
     * @return array<int,array{0:string,1:string}> [原始词条, 被破坏后的字节]
     */
    private function vulnerableWords(): array
    {
        return [
            ['智障', "\xE6\x99\xBA\xE9\x9A"],
            ['死开', "\xE6\xAD\xBB\xE5\xBC"],
            ['断子绝孙', "\xE6\x96\xAD\xE5\xAD\x90\xE7\xBB\x9D\xE5\xAD"],
            ['厂家直销', "\xE5\x8E\x82\xE5\xAE\xB6\xE7\x9B\xB4\xE9\x94"],
            ['高薪招聘', "\xE9\xAB\x98\xE8\x96\xAA\xE6\x8B\x9B\xE8\x81"],
            ['资金盘', "\xE8\xB5\x84\xE9\x87\x91\xE7\x9B"],
            ['传销', "\xE4\xBC\xA0\xE9\x94"],
            ['庞氏骗局', "\xE5\xBA\x9E\xE6\xB0\x8F\xE9\xAA\x97\xE5\xB1"],
            ['杀猪盘', "\xE6\x9D\x80\xE7\x8C\xAA\xE7\x9B"],
            ['成人网站', "\xE6\x88\x90\xE4\xBA\xBA\xE7\xBD\x91\xE7\xAB"],
            ['黄色网站', "\xE9\xBB\x84\xE8\x89\xB2\xE7\xBD\x91\xE7\xAB"],
        ];
    }

    // ══ 一、核心回归：不得截断汉字尾字节 ══════════════════════════════

    public function test_words_ending_with_multibyte_chars_are_not_truncated(): void
    {
        $broken = [];

        foreach ($this->vulnerableWords() as [$word, $mangled]) {
            $rows = $this->manage()->parseWordLines($word . ',block,3');

            if ($rows === []) {
                $broken[] = "{$word}: 词条被整体丢弃";
                continue;
            }

            $actual = $rows[0]['word'];
            $actualHex = bin2hex($actual);

            if ($actualHex === bin2hex($mangled)) {
                $broken[] = "{$word}: 尾字节被剥离 → {$actualHex}";
                continue;
            }

            $this->assertSame($word, $actual, "词条「{$word}」被改写");
            $this->assertTrue(
                mb_check_encoding($actual, 'UTF-8'),
                "词条「{$word}」净化后为非法 UTF-8：{$actualHex}"
            );
        }

        $this->assertSame([], $broken, '以下词条被 trim 字节剥离破坏：' . implode('; ', $broken));
    }

    public function test_single_character_words_survive_sanitization(): void
    {
        // 单字词条更易被整体剥离成空串
        foreach (['死', '盘', '销', '站', '局', '骗', '聘', '障'] as $char) {
            $rows = $this->manage()->parseWordLines($char . ',replace,2');

            $this->assertNotEmpty($rows, "单字词条「{$char}」被整体丢弃");
            $this->assertSame($char, $rows[0]['word']);
            $this->assertTrue(mb_check_encoding($rows[0]['word'], 'UTF-8'));
        }
    }

    // ══ 二、引号剥离功能仍须保留（不得因修复而丢失能力）══════════════

    public function test_surrounding_quotes_are_stripped(): void
    {
        $cases = [
            '"赌博"' => '赌博',
            "'赌博'" => '赌博',
            '“赌博”' => '赌博',      // 中文双引号 U+201C/201D
            '‘赌博’' => '赌博',      // 中文单引号 U+2018/2019
            '  赌博  ' => '赌博',
            "\t赌博\t" => '赌博',
            '赌博' => '赌博',
        ];

        foreach ($cases as $input => $expected) {
            $rows = $this->manage()->parseWordLines($input . ',block,3');

            $this->assertNotEmpty($rows, "输入「{$input}」未解析出词条");
            $this->assertSame($expected, $rows[0]['word'], "输入「{$input}」净化结果不符");
        }
    }

    public function test_inner_quotes_are_preserved(): void
    {
        // 只剥离首尾引号，词中间的引号属词条本体
        $rows = $this->manage()->parseWordLines('a"b,block,3');

        $this->assertNotEmpty($rows);
        $this->assertSame('a"b', $rows[0]['word']);
    }

    // ══ 三、内置词库整体健康度 ══════════════════════════════════════

    public function test_entire_builtin_library_produces_valid_utf8(): void
    {
        $content = (string) file_get_contents(base_path('app/Apps/CmsproSensitivewords/Storage/base_words.txt'));
        $rows = $this->manage()->parseWordLines($content);

        $this->assertNotEmpty($rows);

        $invalid = [];
        foreach ($rows as $row) {
            if (! mb_check_encoding($row['word'], 'UTF-8')) {
                $invalid[] = $row['word'] . ' (' . bin2hex($row['word']) . ')';
            }
        }

        $this->assertSame([], $invalid, '内置词库存在非法 UTF-8 词条：' . implode(', ', $invalid));
    }

    public function test_builtin_library_imports_without_encoding_error(): void
    {
        // 端到端：整个内置词库应能成功导入（此前 12 条会触发 MySQL 1366）
        $result = app(SensitiveLibraryService::class)->importBuiltinWords();

        $this->assertSame($result['total'], $result['inserted'] + $result['skipped'] + $result['updated']);
        $this->assertGreaterThan(0, $result['inserted']);

        // 高频拦截词确实落库
        foreach (['传销', '杀猪盘', '成人网站', '智障', '断子绝孙'] as $word) {
            $this->assertNotNull(
                SensitiveWord::where('word', $word)->first(),
                "词条「{$word}」未成功导入"
            );
        }
    }

    // ══ 四、纵深防御：错误响应不得因 message 含坏字节而崩溃 ═══════════

    public function test_error_messages_passed_to_json_are_valid_utf8(): void
    {
        // 模拟「异常 message 携带原始坏字节」的场景（QueryException 会内嵌 SQL 原值）。
        // 实测 json_encode 遇非法 UTF-8 返回 false + JSON_ERROR_UTF8(5)，
        // Symfony JsonResponse 随即抛 InvalidArgumentException → 接口 500，
        // 把可读的业务错误退化成「服务器内部错误」并掩盖真实根因。
        $badByte = "\xE6\x99\xBA\xE9\x9A"; // 截断后的非法序列
        $message = '词条导入失败：' . $badByte;

        $this->assertFalse(mb_check_encoding($message, 'UTF-8'), '构造的坏字节前提应非法 UTF-8');
        $this->assertFalse(json_encode($message, JSON_UNESCAPED_UNICODE), 'json_encode 遇非法 UTF-8 应返回 false');

        // 净化后必须可编码，且保留可读的业务上下文
        $sanitized = cmspro_sensitivewords_sanitize_text($message);

        $this->assertTrue(mb_check_encoding($sanitized, 'UTF-8'));
        $this->assertNotFalse(json_encode($sanitized, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('词条导入失败', $sanitized);
    }

    public function test_sanitize_text_repairs_bad_bytes_and_keeps_context(): void
    {
        // 真实事故场景：MySQL 1366 的 message 内嵌原始非法字节
        $raw = "词条导入失败：SQLSTATE[HY000]: 1366 Incorrect string value: '\xE6\x99\xBA\xE9\x9A' for column 'word'";

        $this->assertFalse(mb_check_encoding($raw, 'UTF-8'), '前提：原始 message 含非法字节');

        $clean = cmspro_sensitivewords_sanitize_text($raw);

        $this->assertTrue(mb_check_encoding($clean, 'UTF-8'));
        $this->assertNotFalse(json_encode($clean, JSON_UNESCAPED_UNICODE));
        // 排查所需上下文必须完整保留（这正是本次事故最该看到的信息）
        $this->assertStringContainsString('1366', $clean);
        $this->assertStringContainsString("for column 'word'", $clean);
    }

    public function test_sanitize_text_passes_valid_text_through(): void
    {
        foreach (['', '正常中文文本', 'Mixed 中英文 123', 'emoji 🎉'] as $text) {
            $this->assertSame($text, cmspro_sensitivewords_sanitize_text($text));
        }

        $this->assertSame('', cmspro_sensitivewords_sanitize_text(null));
    }

    public function test_sanitize_text_handles_bad_bytes_at_boundaries(): void
    {
        $cases = [
            '开头坏' => "\xE6\x99\xBA\xE9\x9A 后面正常",
            '中间坏' => "前面 \xE6\x99\xBA\xE9\x9A 后面",
            '结尾坏' => "前面正常 \xE6\x99\xBA\xE9\x9A",
            '全是坏字节' => "\xE6\x99\xBA\xE9\x9A\xE4\xBC\xA0\xE9\x94",
        ];

        foreach ($cases as $label => $raw) {
            $clean = cmspro_sensitivewords_sanitize_text($raw);

            $this->assertTrue(mb_check_encoding($clean, 'UTF-8'), "{$label}：净化后仍非法");
            $this->assertNotFalse(json_encode($clean, JSON_UNESCAPED_UNICODE), "{$label}：无法 json_encode");
        }
    }

    public function test_sanitize_text_truncates_overlong_message(): void
    {
        $long = str_repeat('错', 900);

        $this->assertSame(500, mb_strlen(cmspro_sensitivewords_sanitize_text($long)));
        $this->assertSame(100, mb_strlen(cmspro_sensitivewords_sanitize_text($long, 100)));
    }

    // ══ 五、接口层端到端（用户实际报错的入口）══════════════════════════

    public function test_import_builtin_endpoint_returns_readable_json(): void
    {
        // 用户报错入口：POST /api/library/import-builtin 曾返回
        // 500 + InvalidArgumentException「Malformed UTF-8 characters」
        $this->actingAdmin();

        $response = $this->postJson('/admin/cmspro/sensitivewords/api/library/import-builtin');

        $response->assertOk();
        $response->assertJsonPath('code', 0);

        $payload = $response->json();
        $this->assertNotNull($payload, '响应体必须是可解析 JSON');
        $this->assertTrue(mb_check_encoding((string) $payload['message'], 'UTF-8'));
        $this->assertGreaterThan(0, $payload['data']['inserted'] ?? 0);

        // 受该缺陷影响的高频拦截词必须真正落库
        foreach (['传销', '杀猪盘', '成人网站', '黄色网站', '智障', '断子绝孙', '厂家直销', '高薪招聘'] as $word) {
            $this->assertNotNull(
                SensitiveWord::where('word', $word)->first(),
                "词条「{$word}」未导入成功"
            );
        }
    }

    public function test_import_builtin_endpoint_is_idempotent_on_second_call(): void
    {
        $this->actingAdmin();

        $this->postJson('/admin/cmspro/sensitivewords/api/library/import-builtin')->assertOk();

        // 第二次调用：词库指纹未变，应短路跳过且仍返回可读 JSON
        $second = $this->postJson('/admin/cmspro/sensitivewords/api/library/import-builtin');

        $second->assertOk();
        $second->assertJsonPath('code', 0);
        $this->assertSame(0, $second->json('data.inserted'));
    }
}
