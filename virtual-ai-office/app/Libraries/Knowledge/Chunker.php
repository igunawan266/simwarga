<?php

namespace App\Libraries\Knowledge;

/**
 * Splits text into ~1.5k character chunks on paragraph/sentence boundaries, with a small
 * overlap so facts spanning a boundary remain retrievable.
 */
class Chunker
{
    public function __construct(private readonly int $size = 1500, private readonly int $overlap = 150)
    {
    }

    /** @return list<string> */
    public function chunk(string $text): array
    {
        $paragraphs = preg_split("/\n{2,}/", $text) ?: [];
        $chunks     = [];
        $current    = '';

        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            foreach ($this->splitLong($p) as $piece) {
                if ($current !== '' && mb_strlen($current) + mb_strlen($piece) + 2 > $this->size) {
                    $chunks[] = $current;
                    $tail     = mb_substr($current, -$this->overlap);
                    $current  = (str_contains($tail, ' ') ? mb_substr($tail, mb_strpos($tail, ' ') + 1) : $tail) . "\n\n" . $piece;
                } else {
                    $current = $current === '' ? $piece : $current . "\n\n" . $piece;
                }
            }
        }
        if (trim($current) !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /** Paragraphs longer than one chunk are split on sentence ends, then hard-wrapped. */
    private function splitLong(string $p): array
    {
        if (mb_strlen($p) <= $this->size) {
            return [$p];
        }
        $out  = [];
        $buf  = '';
        foreach (preg_split('/(?<=[.!?])\s+/u', $p) ?: [$p] as $sentence) {
            while (mb_strlen($sentence) > $this->size) {
                $out[]    = mb_substr($sentence, 0, $this->size);
                $sentence = mb_substr($sentence, $this->size);
            }
            if ($buf !== '' && mb_strlen($buf) + mb_strlen($sentence) + 1 > $this->size) {
                $out[] = $buf;
                $buf   = $sentence;
            } else {
                $buf = $buf === '' ? $sentence : "{$buf} {$sentence}";
            }
        }
        if ($buf !== '') {
            $out[] = $buf;
        }

        return $out;
    }
}
