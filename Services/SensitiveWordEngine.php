<?php

namespace App\Apps\CmsproSensitivewords\Services;

/**
 * DFA 敏感词匹配引擎（纯算法层，不依赖数据库与容器）。
 *
 * 为什么用 DFA 字典树而不是逐词 strpos：词库通常上万条，逐词扫描的复杂度是
 * O(文本长度 × 词条数)，而 DFA 建一次树后单次检测为 O(文本长度)，
 * 与词条数量无关，10 万词条下检测一段千字文本仍在毫秒级。
 *
 * 匹配前会对文本做归一化（全角转半角、英文转小写），并支持跳过干扰字符
 * （空格与标点），使「敏★感★词」「敏 感 词」等变体也能命中。
 * 归一化仅用于匹配，替换操作始终基于原文位置进行，不会改写原文其他内容。
 */
class SensitiveWordEngine
{
    /** 词典树中的结束标记键（敏感词均为单字符键，多字符键不会冲突） */
    private const END_KEY = '__end__';

    /**
     * 词典树：嵌套数组，键为归一化后的单字符，叶子带 END_KEY 标记。
     *
     * @var array<string,mixed>
     */
    protected array $tree = [];

    /** 是否忽略英文大小写 */
    protected bool $ignoreCase = true;

    /** 是否全角转半角 */
    protected bool $fullWidthToHalf = true;

    /** 是否跳过干扰字符 */
    protected bool $skipSymbols = true;

    /**
     * 词条数量（已加入词典树的归一化键去重后计数）。
     */
    protected int $wordCount = 0;

    public function __construct(bool $ignoreCase = true, bool $fullWidthToHalf = true, bool $skipSymbols = true)
    {
        $this->ignoreCase = $ignoreCase;
        $this->fullWidthToHalf = $fullWidthToHalf;
        $this->skipSymbols = $skipSymbols;
    }

    /**
     * 用词条列表重建词典树。
     *
     * @param iterable<array{id:int,word:string}> $entries 词条集合（含 ID 与原文，归一化后为空的自动忽略）
     */
    public function build(iterable $entries): void
    {
        $this->tree = [];
        $this->wordCount = 0;

        foreach ($entries as $entry) {
            $word = is_array($entry) ? (string) ($entry['word'] ?? '') : (string) $entry;
            $id = is_array($entry) ? (int) ($entry['id'] ?? 0) : 0;
            $this->addWord($word, $id);
        }
    }

    /**
     * 直接载入已编译好的词典树（编译缓存反序列化时使用）。
     *
     * @param array<string,mixed> $tree
     */
    public function loadTree(array $tree, int $wordCount = 0): void
    {
        $this->tree = $tree;
        $this->wordCount = $wordCount > 0 ? $wordCount : $this->countTreeWords($tree);
    }

    /**
     * 导出词典树（供编译缓存持久化）。
     *
     * @return array{tree:array<string,mixed>,word_count:int}
     */
    public function export(): array
    {
        return ['tree' => $this->tree, 'word_count' => $this->wordCount];
    }

    /**
     * 加入单个词条。
     *
     * 叶子节点的 END_KEY 存储命中该路径的词条 ID 列表（通常为 1 个），
     * 使匹配结果能直接定位词条 ID，无需再按归一化键回查数据库——
     * 这样即便匹配策略配置事后变更，也不影响命中定位的准确性。
     */
    public function addWord(string $word, int $id = 0): void
    {
        $chars = $this->normalizeWord($word);
        if ($chars === []) {
            return;
        }

        $node = &$this->tree;
        foreach ($chars as $char) {
            if (! isset($node[$char]) || ! is_array($node[$char])) {
                $node[$char] = [];
            }
            $node = &$node[$char];
        }

        if (! isset($node[self::END_KEY])) {
            $node[self::END_KEY] = [];
            $this->wordCount++;
        }
        if ($id > 0 && ! in_array($id, $node[self::END_KEY], true)) {
            $node[self::END_KEY][] = $id;
        }
        unset($node);
    }

    /**
     * 词典是否为空。
     */
    public function isEmpty(): bool
    {
        return $this->tree === [];
    }

    /**
     * 词条数量。
     */
    public function count(): int
    {
        return $this->wordCount;
    }

    /**
     * 检测文本，返回全部命中片段（按出现顺序，互不重叠）。
     *
     * 采用「最长匹配优先」：同时存在「赌博」与「赌博网站」两个词条时，
     * 命中结果取更长的「赌博网站」，避免同一片段被重复报告。
     *
     * @return array<int,array{
     *     start:int,end:int,raw:string,normalized:string,word_ids:array<int,int>
     * }> start/end 为原文的字符下标（0 起、含端点），raw 为原文片段，
     *    normalized 为归一化匹配键，word_ids 为命中该路径的词条 ID 列表
     */
    public function match(string $text): array
    {
        if ($text === '' || $this->tree === []) {
            return [];
        }

        $items = $this->toItems($text);
        $total = count($items);
        $hits = [];

        for ($start = 0; $start < $total; $start++) {
            if ($items[$start]['skip']) {
                continue;
            }

            $node = $this->tree;
            $matchedEnd = -1;
            $matchedChars = [];
            $matchedIds = [];
            $pendingChars = [];

            for ($j = $start; $j < $total; $j++) {
                if ($items[$j]['skip']) {
                    // 干扰字符仅在已开始匹配后跳过，且不计入匹配串
                    continue;
                }

                $char = $items[$j]['n'];
                if (! isset($node[$char]) || ! is_array($node[$char])) {
                    break;
                }

                $node = $node[$char];
                $pendingChars[] = $char;

                // 叶子节点 END_KEY 为非空 ID 数组即表示此处可结束一个敏感词
                if (! empty($node[self::END_KEY]) && is_array($node[self::END_KEY])) {
                    $matchedEnd = $j;
                    $matchedChars = $pendingChars;
                    $matchedIds = $node[self::END_KEY];
                }
            }

            if ($matchedEnd >= 0) {
                $startIdx = $items[$start]['i'];
                $endIdx = $items[$matchedEnd]['i'];
                $hits[] = [
                    'start' => $startIdx,
                    'end' => $endIdx,
                    'raw' => mb_substr($text, $startIdx, $endIdx - $startIdx + 1),
                    'normalized' => implode('', $matchedChars),
                    'word_ids' => array_values(array_map('intval', $matchedIds)),
                ];
                // 已匹配区间不再参与后续匹配，避免重叠重复命中
                $start = $matchedEnd;
            }
        }

        return $hits;
    }

    /**
     * 判断文本是否包含敏感词。
     */
    public function contains(string $text): bool
    {
        return $this->match($text) !== [];
    }

    /**
     * 将词条归一化为字符数组（用于建树）。
     *
     * 建树时直接剔除干扰字符，使「赌 博」与「赌博」建出同一条路径。
     *
     * @return array<int,string>
     */
    public function normalizeWord(string $word): array
    {
        $chars = [];
        $length = mb_strlen($word);

        for ($i = 0; $i < $length; $i++) {
            $char = $this->normalizeChar(mb_substr($word, $i, 1));
            if ($char === '' || $this->isSkipChar($char)) {
                continue;
            }
            $chars[] = $char;
        }

        return $chars;
    }

    /**
     * 单字符归一化：全角转半角 + 按需转小写。
     */
    protected function normalizeChar(string $char): string
    {
        if ($this->fullWidthToHalf) {
            $code = mb_ord($char, 'UTF-8');
            // 全角空格
            if ($code === 0x3000) {
                $char = ' ';
            } elseif ($code >= 0xFF01 && $code <= 0xFF5E) {
                // 全角 ASCII 可见字符区（！→!，Ａ→A）
                $char = mb_chr($code - 0xFEE0, 'UTF-8');
            }
        }

        return $this->ignoreCase ? mb_strtolower($char, 'UTF-8') : $char;
    }

    /**
     * 判断是否为干扰字符（跳过不参与匹配）。
     *
     * 干扰字符 = 空白 + 各类标点符号 + 常见装饰符号（★☆-* 等）。
     * 未开启 skipSymbols 时一律返回 false，即干扰字符也参与匹配。
     */
    protected function isSkipChar(string $char): bool
    {
        if (! $this->skipSymbols) {
            return false;
        }

        if (trim($char) === '') {
            return true;
        }

        // C 与 P 系列 Unicode 属性覆盖标点、符号与不可见控制字符
        return (bool) preg_match('/^[\p{P}\p{S}\p{C}]$/u', $char);
    }

    /**
     * 把原文拆解为「归一化字符 + 原文下标 + 是否干扰字符」序列。
     *
     * 保留原文下标是为了让替换操作能精确定位原文位置，
     * 归一化只影响匹配，不改动原文内容。
     *
     * @return array<int,array{n:string,i:int,skip:bool}>
     */
    protected function toItems(string $text): array
    {
        $items = [];
        $length = mb_strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1);
            $normalized = $this->normalizeChar($char);
            $items[] = [
                'n' => $normalized,
                'i' => $i,
                'skip' => $this->isSkipChar($normalized),
            ];
        }

        return $items;
    }

    /**
     * 递归统计词典树中的词条数（载入编译缓存但未带计数时兜底）。
     *
     * @param array<string,mixed> $node
     */
    protected function countTreeWords(array $node): int
    {
        $count = 0;
        foreach ($node as $key => $child) {
            if ($key === self::END_KEY) {
                // 非空 ID 数组表示此处结束一个敏感词，计 1（word_lower 唯一，一路径对应一条词条）
                if (! empty($child)) {
                    $count++;
                }
                continue;
            }
            if (is_array($child)) {
                $count += $this->countTreeWords($child);
            }
        }

        return $count;
    }
}
