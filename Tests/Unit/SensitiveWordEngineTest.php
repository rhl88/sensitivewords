<?php

namespace App\Apps\CmsproSensitivewords\Tests\Unit;

use App\Apps\CmsproSensitivewords\Services\SensitiveWordEngine;
use PHPUnit\Framework\TestCase;

/**
 * DFA 匹配引擎单元测试（纯算法层，不依赖数据库与容器）。
 *
 * 覆盖：基础命中、最长匹配优先、重叠去重、大小写归一化、全角转半角、
 * 干扰符跳过、词典树导入导出（编译缓存往返）、空输入与空词典边界。
 */
class SensitiveWordEngineTest extends TestCase
{
    /**
     * 用词条构建一个默认策略（三项归一化全开）的引擎。
     *
     * @param array<int,array{id:int,word:string}> $entries
     */
    private function engine(array $entries): SensitiveWordEngine
    {
        $engine = new SensitiveWordEngine(true, true, true);
        $engine->build($entries);

        return $engine;
    }

    public function test_matches_single_word_and_returns_original_position(): void
    {
        $engine = $this->engine([['id' => 1, 'word' => '赌博']]);
        $hits = $engine->match('我在赌博网站下注');

        $this->assertCount(1, $hits);
        $this->assertSame('赌博', $hits[0]['raw']);
        $this->assertSame([1], $hits[0]['word_ids']);
        // 「赌博」在「我在赌博…」中起始下标为 2
        $this->assertSame(2, $hits[0]['start']);
        $this->assertSame(3, $hits[0]['end']);
    }

    public function test_prefers_longest_match(): void
    {
        // 同时存在「赌博」与「赌博网站」，命中更长的一个，不重复报告
        $engine = $this->engine([
            ['id' => 1, 'word' => '赌博'],
            ['id' => 2, 'word' => '赌博网站'],
        ]);
        $hits = $engine->match('访问赌博网站');

        $this->assertCount(1, $hits);
        $this->assertSame('赌博网站', $hits[0]['raw']);
        $this->assertSame([2], $hits[0]['word_ids']);
    }

    public function test_matches_multiple_distinct_words(): void
    {
        $engine = $this->engine([
            ['id' => 1, 'word' => '赌博'],
            ['id' => 2, 'word' => '色情'],
        ]);
        $hits = $engine->match('赌博和色情都违规');

        $this->assertCount(2, $hits);
        $this->assertSame(['赌博', '色情'], array_column($hits, 'raw'));
    }

    public function test_ignores_english_case_when_enabled(): void
    {
        $engine = $this->engine([['id' => 1, 'word' => 'gambling']]);

        $this->assertTrue($engine->contains('I like GAMBLING sites'));
        $this->assertTrue($engine->contains('gambling'));
    }

    public function test_matches_full_width_characters(): void
    {
        // 全角「ｇａｍｅ」应被归一化为半角命中
        $engine = $this->engine([['id' => 1, 'word' => 'game']]);

        $this->assertTrue($engine->contains('play ｇａｍｅ now'));
    }

    public function test_skips_interference_symbols(): void
    {
        // 「赌★博」「赌 博」等插入干扰符的变体应命中
        $engine = $this->engine([['id' => 1, 'word' => '赌博']]);

        $this->assertTrue($engine->contains('赌★博'));
        $this->assertTrue($engine->contains('赌 博'));
        $this->assertTrue($engine->contains('赌-博-网'));
    }

    public function test_no_skip_symbols_when_disabled(): void
    {
        // 关闭跳过干扰符后，「赌★博」不再命中（★ 参与匹配，树中无此路径）
        $engine = new SensitiveWordEngine(true, true, false);
        $engine->build([['id' => 1, 'word' => '赌博']]);

        $this->assertFalse($engine->contains('赌★博'));
        // 但连写的「赌博」仍命中
        $this->assertTrue($engine->contains('赌博'));
    }

    public function test_case_sensitive_when_ignore_case_disabled(): void
    {
        $engine = new SensitiveWordEngine(false, true, true);
        $engine->build([['id' => 1, 'word' => 'gambling']]);

        $this->assertTrue($engine->contains('gambling'));
        $this->assertFalse($engine->contains('GAMBLING'));
    }

    public function test_empty_text_and_empty_dictionary_return_no_hits(): void
    {
        $engine = $this->engine([['id' => 1, 'word' => '赌博']]);
        $this->assertSame([], $engine->match(''));

        $emptyEngine = $this->engine([]);
        $this->assertTrue($emptyEngine->isEmpty());
        $this->assertSame([], $emptyEngine->match('赌博网站'));
    }

    public function test_words_normalizing_to_empty_are_ignored(): void
    {
        // 纯符号词条归一化后为空，不应加入词典
        $engine = $this->engine([['id' => 1, 'word' => '★★★']]);

        $this->assertTrue($engine->isEmpty());
        $this->assertSame(0, $engine->count());
    }

    public function test_export_and_load_roundtrip_preserves_matching(): void
    {
        $engine = $this->engine([
            ['id' => 1, 'word' => '赌博'],
            ['id' => 2, 'word' => '色情'],
        ]);
        $exported = $engine->export();

        // 模拟从编译缓存反序列化后重建引擎
        $restored = new SensitiveWordEngine(true, true, true);
        $restored->loadTree($exported['tree'], $exported['word_count']);

        $this->assertSame($engine->count(), $restored->count());
        $this->assertTrue($restored->contains('赌博和色情'));

        $hits = $restored->match('赌博');
        $this->assertSame([1], $hits[0]['word_ids']);
    }

    public function test_overlapping_occurrences_do_not_double_report(): void
    {
        // 「哈哈哈哈」中出现两次「哈哈」，应各自命中且互不重叠
        $engine = $this->engine([['id' => 1, 'word' => '哈哈']]);
        $hits = $engine->match('哈哈哈哈');

        $this->assertCount(2, $hits);
    }
}
