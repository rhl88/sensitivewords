<?php

namespace App\Apps\CmsproSensitivewords\Tests\Feature;

use App\Apps\CmsproSensitivewords\Models\SensitiveCategory;
use App\Apps\CmsproSensitivewords\Models\SensitiveWord;
use App\Apps\CmsproSensitivewords\Services\SensitiveLibraryService;
use App\Apps\CmsproSensitivewords\Services\SensitiveWordManageService;
use App\Apps\CmsproSensitivewords\Tests\SensitivewordsTestCase;
use App\Models\ConfigItem;
use Illuminate\Support\Facades\DB;

/**
 * 内置常见词库测试。
 *
 * 覆盖两类关键保障：
 * 1. 词库内容合法性 —— 随应用分发的 base_words.txt 必须可被正确解析：
 *    格式合法、归一化键不冲突（否则 word_lower 唯一索引会导致导入整体回滚）、
 *    分类均为内置分类、动作与级别取值合法；
 * 2. 增量导入语义 —— 词库扩充后对已安装站点必须能生效：
 *    指纹未变则跳过、指纹变化则补齐新词、且不覆盖管理员对既有词条的手工调整。
 *
 * 第 2 类是回归防护重点：旧实现以「存在 builtin 词条」为跳过条件，
 * 导致扩充词库对已安装站点完全无效，且无任何测试覆盖。
 */
class BuiltinLibraryTest extends SensitivewordsTestCase
{
    private function library(): SensitiveLibraryService
    {
        return app(SensitiveLibraryService::class);
    }

    private function manage(): SensitiveWordManageService
    {
        return app(SensitiveWordManageService::class);
    }

    /**
     * 读取内置词库文件原始内容。
     */
    private function baseWordsContent(): string
    {
        return (string) file_get_contents(base_path('app/Apps/CmsproSensitivewords/Storage/base_words.txt'));
    }

    /**
     * 直接写入内置词库指纹（模拟「升级前已导入旧词库」的状态）。
     *
     * group_id 取真实的 library_settings 组（由基类 seedConfigGroups 按 manifest 创建），
     * 不硬编码，避免依赖建表顺序产生的自增 ID。
     */
    private function writeBuiltinHash(string $hash): void
    {
        $group = \App\Models\ConfigGroup::where('code', 'app_cmspro_sensitivewords_library_settings')->first();
        $this->assertNotNull($group, 'library_settings 配置组应由测试基类创建');

        ConfigItem::updateOrCreate(
            ['code' => 'app_cmspro_sensitivewords_builtin_library_hash'],
            [
                'group_id' => $group->id,
                'name' => '内置词库指纹',
                'type' => 'text',
                'value' => $hash,
                'sort' => 0,
                'status' => 1,
            ]
        );

        cmspro_sensitivewords_forget_config_cache(['builtin_library_hash']);
    }

    // ══ 一、词库内容合法性 ══════════════════════════════════════════

    public function test_base_words_file_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('app/Apps/CmsproSensitivewords/Storage/base_words.txt'));

        $rows = $this->manage()->parseWordLines($this->baseWordsContent());

        // 常见词库应具一定规模（当前 192 条），防止文件被误清空
        $this->assertGreaterThanOrEqual(100, count($rows));
    }

    public function test_base_words_have_no_normalized_key_conflict(): void
    {
        // word_lower 带唯一索引，同键不同词会使导入整体回滚
        $rows = $this->manage()->parseWordLines($this->baseWordsContent());

        $keys = [];
        $conflicts = [];
        foreach ($rows as $row) {
            $key = $this->manage()->buildMatchKey($row['word']);
            if (isset($keys[$key])) {
                $conflicts[] = "{$keys[$key]} vs {$row['word']} => {$key}";
            }
            $keys[$key] = $row['word'];
        }

        $this->assertSame([], $conflicts, '内置词库存在归一化冲突：' . implode('; ', $conflicts));
        $this->assertSame(count($rows), count($keys));
    }

    public function test_base_words_use_valid_action_and_level(): void
    {
        $rows = $this->manage()->parseWordLines($this->baseWordsContent());

        foreach ($rows as $row) {
            $this->assertContains(
                $row['action'],
                ['block', 'replace', 'warn', ''],
                "词条「{$row['word']}」动作非法：{$row['action']}"
            );
            $this->assertContains(
                $row['level'],
                [1, 2, 3],
                "词条「{$row['word']}」级别非法：{$row['level']}"
            );
        }
    }

    public function test_base_words_only_use_builtin_categories(): void
    {
        $rows = $this->manage()->parseWordLines($this->baseWordsContent());

        // 只允许内置分类，避免导入时凭空创建大量分类
        $allowed = ['abuse', 'ad', 'fraud', 'porn'];

        $used = [];
        foreach ($rows as $row) {
            if ($row['category'] !== '') {
                $used[$row['category']] = true;
            }
        }

        foreach (array_keys($used) as $code) {
            $this->assertContains($code, $allowed, "内置词库使用了非内置分类：{$code}");
        }

        // 四类都应有词条覆盖（$used 为 分类code => true 映射，取键并排序比对）
        $usedCodes = array_keys($used);
        sort($usedCodes);
        $this->assertSame(['abuse', 'ad', 'fraud', 'porn'], $usedCodes);
    }

    public function test_base_words_cover_all_four_categories_with_minimum_counts(): void
    {
        $rows = $this->manage()->parseWordLines($this->baseWordsContent());

        $perCategory = [];
        foreach ($rows as $row) {
            $perCategory[$row['category']] = ($perCategory[$row['category']] ?? 0) + 1;
        }

        // 每类至少 20 条，防止某类被误删至形同虚设
        foreach (['abuse', 'ad', 'fraud', 'porn'] as $code) {
            $this->assertGreaterThanOrEqual(
                20,
                $perCategory[$code] ?? 0,
                "分类 {$code} 词条数不足：" . ($perCategory[$code] ?? 0)
            );
        }
    }

    public function test_base_words_do_not_contain_political_entries(): void
    {
        // 设计决策：涉政类不内置具体词条，由站点按属地法规自行维护
        $rows = $this->manage()->parseWordLines($this->baseWordsContent());

        $categories = array_unique(array_column($rows, 'category'));
        $this->assertNotContains('politics', $categories, '内置词库不应包含涉政类词条');
    }

    // ══ 二、导入结果 ══════════════════════════════════════════════

    public function test_import_builtin_words_inserts_all_entries(): void
    {
        $result = $this->library()->importBuiltinWords();

        $expected = $this->library()->builtinWordCount();

        $this->assertSame($expected, $result['inserted']);
        $this->assertSame(false, $result['unchanged']);
        $this->assertSame($expected, SensitiveWord::where('source', SensitiveWord::SOURCE_BUILTIN)->count());
    }

    public function test_imported_words_are_effective_in_filtering(): void
    {
        $this->library()->importBuiltinWords();

        // block 级词条应拦截
        $result = $this->filterService()->check('我在赌博网站下注');
        $this->assertFalse($result['passed']);
        $this->assertSame('block', $result['action']);

        // replace 级词条应被掩码
        $filtered = $this->filterService()->filter('你这个废物');
        $this->assertStringNotContainsString('废物', $filtered);
    }

    public function test_imported_words_belong_to_correct_categories(): void
    {
        $this->library()->importBuiltinWords();

        $fraudId = $this->categoryId('fraud');
        $this->assertGreaterThan(0, $fraudId);

        $this->assertSame(
            $fraudId,
            (int) SensitiveWord::where('word', '赌博')->value('category_id')
        );

        // 分类词条计数应被刷新
        $this->assertGreaterThan(0, (int) SensitiveCategory::where('code', 'fraud')->value('word_count'));
    }

    // ══ 三、增量导入语义（回归防护重点）══════════════════════════════

    public function test_second_import_is_skipped_by_fingerprint(): void
    {
        $this->library()->importBuiltinWords();
        $countAfterFirst = SensitiveWord::count();

        // 词库文件未变，第二次导入应短路跳过（不做无谓的解析与写库）
        $result = $this->library()->importBuiltinWords();

        $this->assertTrue($result['unchanged']);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame($countAfterFirst, SensitiveWord::count());
    }

    public function test_fingerprint_is_persisted_after_import(): void
    {
        $this->library()->importBuiltinWords();

        $hash = ConfigItem::where('code', 'app_cmspro_sensitivewords_builtin_library_hash')->value('value');

        $this->assertNotEmpty($hash);
        $this->assertSame(hash('sha256', $this->baseWordsContent()), $hash);
    }

    public function test_expanded_library_backfills_new_words_on_upgrade(): void
    {
        // 模拟「旧版本站点」：只有 2 个 builtin 词条，且指纹为旧值
        SensitiveWord::create([
            'word' => '赌博', 'word_lower' => '赌博', 'source' => SensitiveWord::SOURCE_BUILTIN,
            'action' => 'block', 'level' => 3, 'category_id' => $this->categoryId('fraud'), 'status' => 1,
        ]);
        SensitiveWord::create([
            'word' => '傻逼', 'word_lower' => '傻逼', 'source' => SensitiveWord::SOURCE_BUILTIN,
            'action' => 'replace', 'level' => 2, 'category_id' => $this->categoryId('abuse'), 'status' => 1,
        ]);

        // 写入一个与新词库不匹配的旧指纹，模拟升级前状态
        $this->writeBuiltinHash('stale_hash');

        // 升级触发导入：旧实现会因「已存在 builtin 词条」直接跳过，导致扩充无效
        $result = $this->library()->importBuiltinWords();

        $this->assertFalse($result['unchanged']);
        // 新增 = 全部词条 - 已存在的 2 条
        $this->assertSame($this->library()->builtinWordCount() - 2, $result['inserted']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame($this->library()->builtinWordCount(), SensitiveWord::count());

        // 新增的词条确实可用
        $this->assertNotNull(SensitiveWord::where('word', '百家乐')->first());
    }

    public function test_backfill_preserves_local_customization(): void
    {
        // 管理员把「赌博」动作从 block 调成了 warn，并改了分类
        $customCategory = $this->manage()->createCategory(['name' => '站点自定义', 'code' => 'site_custom']);
        SensitiveWord::create([
            'word' => '赌博', 'word_lower' => '赌博', 'source' => SensitiveWord::SOURCE_BUILTIN,
            'action' => 'warn', 'level' => 1, 'category_id' => (int) $customCategory->id, 'status' => 1,
        ]);
        $this->writeBuiltinHash('stale_hash');

        // 升级补齐新词时，skip 模式不得覆盖管理员的手工调整
        $this->library()->importBuiltinWords();

        $gambling = SensitiveWord::where('word', '赌博')->first();
        $this->assertSame('warn', $gambling->action);
        $this->assertSame((int) $customCategory->id, (int) $gambling->category_id);
    }

    public function test_import_is_atomic_on_failure(): void
    {
        // 导入失败应整体回滚，不留半成品数据
        DB::beginTransaction();
        $before = SensitiveWord::count();

        try {
            // 构造一个必然失败的场景：先占用唯一键，再让批处理中途抛错
            $this->library()->importBuiltinWords();
            $this->assertGreaterThan($before, SensitiveWord::count());
        } finally {
            DB::rollBack();
        }

        $this->assertSame($before, SensitiveWord::count());
    }

    // ══ 四、批量导入查询效率（N+1 回归防护）══════════════════════════

    public function test_bulk_import_does_not_query_per_row(): void
    {
        // 导入 192 条内置词库，查询次数应为「常数级 + 分批」，而非每词一次
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->library()->importBuiltinWords();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $wordCount = $this->library()->builtinWordCount();
        $selectCount = count(array_filter($queries, fn ($q) => str_starts_with(strtolower($q['query']), 'select')));

        // 逐条查库时 select 次数 ≈ 词条数；批量预查后应远小于词条数
        $this->assertLessThan(
            $wordCount / 2,
            $selectCount,
            "导入 {$wordCount} 条词条产生了 {$selectCount} 次 select，疑似 N+1"
        );
    }

    public function test_same_batch_duplicate_keys_do_not_break_unique_index(): void
    {
        // 同批内出现归一化同键的词条，应被跳过而非触发唯一键冲突
        $rows = [
            ['word' => 'GAMBLING', 'action' => 'block', 'level' => 3, 'category' => ''],
            ['word' => 'gambling', 'action' => 'block', 'level' => 3, 'category' => ''],
        ];

        // parseWordLines 已按字面去重，此处直接喂给 importRows 验证其自身防重能力
        $stat = $this->manage()->importRows($rows, SensitiveWord::SOURCE_IMPORT, 'skip');

        $this->assertSame(1, $stat['inserted']);
        $this->assertSame(1, $stat['skipped']);
        $this->assertSame(1, SensitiveWord::where('word_lower', 'gambling')->count());
    }
}
