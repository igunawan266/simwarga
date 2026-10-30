<?php

namespace App\Libraries\Knowledge;

/**
 * Lexical retrieval (TF-IDF over the knowledge base's own chunks). Small knowledge bases are
 * sent whole; larger ones contribute only the chunks that best match the task.
 */
class Retriever
{
    private const STOPWORDS = ['yang', 'dan', 'untuk', 'dengan', 'dari', 'pada', 'dalam', 'ini', 'itu', 'atau', 'akan', 'agar', 'oleh',
        'juga', 'ada', 'tidak', 'bisa', 'kami', 'anda', 'kita', 'saya', 'buat', 'buatkan', 'tolong', 'the', 'and', 'for', 'with', 'from',
        'this', 'that', 'are', 'was', 'you', 'your', 'our', 'have', 'has', 'into', 'about'];

    /**
     * @param list<array{id: int, seq: int, content: string}> $chunks ordered by seq
     *
     * @return list<array{id: int, seq: int, content: string}> selected chunks, in document order
     */
    public function select(array $chunks, string $query, int $fullCharLimit = 120000, int $topK = 12): array
    {
        $total = array_sum(array_map(static fn ($c) => mb_strlen($c['content']), $chunks));
        if ($total <= $fullCharLimit) {
            return $chunks;
        }

        $terms = array_unique($this->terms($query));
        if ($terms === []) {
            return array_slice($chunks, 0, $topK);
        }

        $tf = [];
        $df = array_fill_keys($terms, 0);
        foreach ($chunks as $i => $c) {
            $counts = array_count_values($this->terms($c['content']));
            foreach ($terms as $t) {
                if (isset($counts[$t])) {
                    $tf[$i][$t] = $counts[$t];
                    $df[$t]++;
                }
            }
        }
        $n      = count($chunks);
        $scores = [];
        foreach ($tf as $i => $termCounts) {
            $len        = max(1, mb_strlen($chunks[$i]['content']) / 1000);
            $scores[$i] = 0.0;
            foreach ($termCounts as $t => $count) {
                $scores[$i] += (1 + log($count)) * log(1 + $n / $df[$t]) / sqrt($len);
            }
        }
        arsort($scores);
        $picked = array_slice(array_keys($scores), 0, $topK);
        sort($picked);

        return array_map(static fn ($i) => $chunks[$i], $picked) ?: array_slice($chunks, 0, $topK);
    }

    /** @return list<string> */
    public function terms(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $m);

        return array_values(array_filter($m[0], static fn ($w) => ! in_array($w, self::STOPWORDS, true)));
    }
}
