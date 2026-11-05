<?php

namespace App\Apps\CmsproSensitivewords\Tests\Feature;

use App\Apps\CmsproSensitivewords\Models\SensitiveCategory;
use App\Apps\CmsproSensitivewords\Models\SensitiveWord;
use App\Apps\CmsproSensitivewords\Services\SensitiveWordManageService;
use App\Apps\CmsproSensitivewords\Tests\SensitivewordsTestCase;
use RuntimeException;

/**
 * 词条与分类管理服务测试。
 *
 * 覆盖：词条 CRUD、归一化去重、批量启停删除、分类 CRUD、系统分类保护、
 * 词条解析（多格式）、批量导入（skip/update/overwrite）、分类计数刷新。
 */
class WordManageServiceTest extends SensitivewordsTestCase
{
    private function service(): SensitiveWordManageService
    {
        return app(SensitiveWordManageService::class);
    }

    // ══ 词条 CRUD ══════════════════════════════════════════════════

    public function test_create_word_generates_match_key(): void
    {
        $word = $this->service()->createWord(['word' => '赌博', 'action' => 'block', 'level' => 3]);

        $this->assertSame('赌博', $word->word);
        $this->assertSame('赌博', $word->word_lower);
        $this->assertSame('block', $word->action);
        $this->assertSame(SensitiveWord::SOURCE_MANUAL, $word->source);
    }

    public function test_create_word_normalizes_match_key(): void
    {
        // 全角 + 大写归一化为小写半角
        $word = $this->service()->createWord(['word' => 'ＧＡＭＢＬＩＮＧ']);

        $this->assertSame('gambling', $word->word_lower);
    }

    public function test_create_word_rejects_duplicate_normalized_key(): void
    {
        $this->service()->createWord(['word' => 'gambling']);

        $this->expectException(RuntimeException::class);
        // GAMBLING 归一化后与 gambling 相同，应被拒绝
        $this->service()->createWord(['word' => 'GAMBLING']);
    }

    public function test_create_word_rejects_empty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->createWord(['word' => '   ']);
    }

    public function test_create_word_normalizes_action_alias(): void
    {
        // 中文动作名归一化为英文标识
        $word = $this->service()->createWord(['word' => '测试词', 'action' => '禁止']);

        $this->assertSame('block', $word->action);
    }

    public function test_update_word_changes_fields(): void
    {
        $word = $this->service()->createWord(['word' => '赌博', 'level' => 1]);

        $updated = $this->service()->updateWord((int) $word->id, ['level' => 3, 'action' => 'block']);

        $this->assertSame(3, (int) $updated->level);
        $this->assertSame('block', $updated->action);
    }

    public function test_update_word_rejects_conflicting_normalization(): void
    {
        $this->service()->createWord(['word' => 'abc']);
        $b = $this->service()->createWord(['word' => 'def']);

        $this->expectException(RuntimeException::class);
        // 把 def 改成 ABC，与已存在的 abc 归一化冲突
        $this->service()->updateWord((int) $b->id, ['word' => 'ABC']);
    }

    public function test_delete_words_returns_count(): void
    {
        $a = $this->service()->createWord(['word' => '词一']);
        $b = $this->service()->createWord(['word' => '词二']);

        $deleted = $this->service()->deleteWords([(int) $a->id, (int) $b->id]);

        $this->assertSame(2, $deleted);
        $this->assertSame(0, SensitiveWord::count());
    }

    public function test_batch_update_status(): void
    {
        $a = $this->service()->createWord(['word' => '词一']);
        $b = $this->service()->createWord(['word' => '词二']);

        $affected = $this->service()->batchUpdateStatus([(int) $a->id, (int) $b->id], 0);

        $this->assertSame(2, $affected);
        $this->assertSame(0, SensitiveWord::enabled()->count());
    }

    public function test_delete_words_by_source(): void
    {
        $this->service()->createWord(['word' => '手工词']);
        SensitiveWord::create(['word' => '在线词', 'word_lower' => '在线词', 'source' => SensitiveWord::SOURCE_LIBRARY, 'status' => 1]);

        $deleted = $this->service()->deleteWordsBy(['source' => SensitiveWord::SOURCE_LIBRARY]);

        $this->assertSame(1, $deleted);
        // 手工词条保留
        $this->assertTrue(SensitiveWord::where('word', '手工词')->exists());
    }

    // ══ 分类 CRUD ══════════════════════════════════════════════════

    public function test_create_category_validates_code_format(): void
    {
        $this->expectException(RuntimeException::class);
        // 大写 code 非法
        $this->service()->createCategory(['name' => '测试', 'code' => 'InvalidCode']);
    }

    public function test_create_category_rejects_duplicate_name(): void
    {
        $this->expectException(RuntimeException::class);
        // fraud 分类已由基类创建
        $this->service()->createCategory(['name' => '诈骗违禁']);
    }

    public function test_delete_system_category_is_rejected(): void
    {
        $fraud = SensitiveCategory::where('code', 'fraud')->first();

        $this->expectException(RuntimeException::class);
        $this->service()->deleteCategory((int) $fraud->id);
    }

    public function test_delete_category_with_words_is_rejected(): void
    {
        $category = $this->service()->createCategory(['name' => '临时分类', 'code' => 'temp_cat']);
        $this->service()->createWord(['word' => '某词', 'category_id' => (int) $category->id]);

        $this->expectException(RuntimeException::class);
        $this->service()->deleteCategory((int) $category->id);
    }

    public function test_delete_empty_custom_category_succeeds(): void
    {
        $category = $this->service()->createCategory(['name' => '空分类', 'code' => 'empty_cat']);

        $this->service()->deleteCategory((int) $category->id);

        $this->assertNull(SensitiveCategory::find($category->id));
    }

    public function test_system_category_code_cannot_be_changed(): void
    {
        $fraud = SensitiveCategory::where('code', 'fraud')->first();

        // 尝试修改系统分类 code，应被忽略（保持 fraud）
        $updated = $this->service()->updateCategory((int) $fraud->id, ['code' => 'newcode', 'name' => '诈骗违禁改名']);

        $this->assertSame('fraud', $updated->code);
        $this->assertSame('诈骗违禁改名', $updated->name);
    }

    public function test_category_word_count_refreshed(): void
    {
        $category = $this->service()->createCategory(['name' => '计数分类', 'code' => 'count_cat']);
        $this->service()->createWord(['word' => '词A', 'category_id' => (int) $category->id]);
        $this->service()->createWord(['word' => '词B', 'category_id' => (int) $category->id]);

        $this->assertSame(2, (int) $category->fresh()->word_count);
    }

    // ══ 词条解析与导入 ══════════════════════════════════════════════

    public function test_parse_word_lines_supports_multiple_formats(): void
    {
        $content = "# 注释行\n赌博\n代开发票,block,3\n色情|replace|2|porn\n\n// 另一注释\n";
        $rows = $this->service()->parseWordLines($content);

        $this->assertCount(3, $rows);
        $this->assertSame('赌博', $rows[0]['word']);
        $this->assertSame('', $rows[0]['action']);
        $this->assertSame('代开发票', $rows[1]['word']);
        $this->assertSame('block', $rows[1]['action']);
        $this->assertSame(3, $rows[1]['level']);
        $this->assertSame('色情', $rows[2]['word']);
        $this->assertSame('replace', $rows[2]['action']);
        $this->assertSame('porn', $rows[2]['category']);
    }

    public function test_parse_word_lines_dedupes_within_file(): void
    {
        $rows = $this->service()->parseWordLines("赌博\n赌博\nGAMBLING\n赌博");

        // 重复的「赌博」只保留一次（GAMBLING 归一化不同词面，此处按字面去重）
        $words = array_column($rows, 'word');
        $this->assertSame(['赌博', 'GAMBLING'], $words);
    }

    public function test_import_rows_skip_mode_keeps_existing(): void
    {
        $this->service()->createWord(['word' => '赌博', 'action' => 'block']);
        $rows = $this->service()->parseWordLines("赌博,replace\n新词,block");

        $stat = $this->service()->importRows($rows, SensitiveWord::SOURCE_IMPORT, 'skip');

        $this->assertSame(1, $stat['inserted']);
        $this->assertSame(1, $stat['skipped']);
        // 已存在的「赌博」动作保持 block，未被 skip 模式改动
        $this->assertSame('block', SensitiveWord::where('word', '赌博')->value('action'));
    }

    public function test_import_rows_overwrite_mode_replaces_existing(): void
    {
        $this->service()->createWord(['word' => '赌博', 'action' => 'block']);
        $rows = $this->service()->parseWordLines('赌博,replace,2');

        $stat = $this->service()->importRows($rows, SensitiveWord::SOURCE_IMPORT, 'overwrite');

        $this->assertSame(1, $stat['updated']);
        $word = SensitiveWord::where('word', '赌博')->first();
        $this->assertSame('replace', $word->action);
        $this->assertSame(2, (int) $word->level);
    }

    public function test_import_rows_creates_missing_category(): void
    {
        $rows = $this->service()->parseWordLines('新词,block,3,全新分类');

        $this->service()->importRows($rows, SensitiveWord::SOURCE_IMPORT, 'skip');

        $this->assertNotNull(SensitiveCategory::where('name', '全新分类')->first());
    }

    public function test_export_words_roundtrip(): void
    {
        $this->service()->createWord(['word' => '赌博', 'action' => 'block', 'level' => 3, 'category_id' => $this->categoryId('fraud')]);

        $exported = $this->service()->exportWords();

        $this->assertStringContainsString('赌博,block,3,fraud', $exported);
    }

    public function test_hit_log_list_and_delete(): void
    {
        // 造两条日志
        \App\Apps\CmsproSensitivewords\Models\SensitiveHitLog::create([
            'source_app' => 'cmspro.blog', 'scene' => 'a', 'words' => '赌博',
            'word_count' => 1, 'action' => 'block', 'content' => 'x', 'create_time' => now(),
        ]);
        $log2 = \App\Apps\CmsproSensitivewords\Models\SensitiveHitLog::create([
            'source_app' => 'cmspro.blog', 'scene' => 'b', 'words' => '色情',
            'word_count' => 1, 'action' => 'replace', 'content' => 'y', 'create_time' => now(),
        ]);

        $paginator = $this->service()->listHitLogs(['source_app' => 'cmspro.blog']);
        $this->assertSame(2, $paginator->total());

        $deleted = $this->service()->deleteHitLogs([(int) $log2->id]);
        $this->assertSame(1, $deleted);
        $this->assertSame(1, \App\Apps\CmsproSensitivewords\Models\SensitiveHitLog::count());
    }
}
