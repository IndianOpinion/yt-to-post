<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Chunking;

use IndianOpinion\YtToPost\Transcript\Cue;
use IndianOpinion\YtToPost\Video\Chapter;

/**
 * Splits a cue list into chunks of at most $chunkTokens estimated tokens
 * (PRD cases 47 to 50). A cue is never split unless it alone is larger
 * than a chunk; then it is cut at sentence ends, then whitespace, then a
 * character boundary, never inside a UTF-8 sequence.
 */
final class Chunker
{
    public function __construct(
        private readonly TokenEstimator $estimator,
        private readonly int $chunkTokens
    ) {
        if ($chunkTokens < 50) {
            throw new \InvalidArgumentException('Chunk size must be at least 50 tokens.');
        }
    }

    /**
     * @param list<Cue> $cues
     */
    public function totalTokens(array $cues): int
    {
        $t = 0;
        foreach ($cues as $c) {
            $t += $this->cost($c);
        }
        return $t;
    }

    /**
     * @param list<Cue> $cues
     * @param list<Chapter> $chapters
     * @return list<Chunk>
     */
    public function split(array $cues, array $chapters = []): array
    {
        $pieces = [];
        foreach ($cues as $c) {
            foreach ($this->splitGiant($c) as $p) {
                $pieces[] = $p;
            }
        }
        $groups = $this->groupByChapter($pieces, $chapters);
        $chunks = [];
        $current = [];
        $currentTokens = 0;
        foreach ($groups as $group) {
            $gTokens = 0;
            foreach ($group as $c) {
                $gTokens += $this->cost($c);
            }
            if ($current !== [] && $currentTokens + $gTokens <= $this->chunkTokens) {
                array_push($current, ...$group);
                $currentTokens += $gTokens;
                continue;
            }
            if ($current !== []) {
                $chunks[] = $current;
                $current = [];
                $currentTokens = 0;
            }
            if ($gTokens <= $this->chunkTokens) {
                $current = $group;
                $currentTokens = $gTokens;
                continue;
            }
            $parts = $this->splitByGaps($group);
            $last = array_pop($parts);
            foreach ($parts as $p) {
                $chunks[] = $p;
            }
            $current = $last ?? [];
            $currentTokens = $this->totalTokens($current);
        }
        if ($current !== []) {
            $chunks[] = $current;
        }
        $out = [];
        foreach ($chunks as $i => $list) {
            $out[] = $this->makeChunk($i, $list, $chapters);
        }
        return $out;
    }

    private function cost(Cue $c): int
    {
        return $this->estimator->estimate($c->text) + 1;
    }

    /**
     * @param list<Cue> $cues
     * @param list<Chapter> $chapters
     * @return list<list<Cue>>
     */
    private function groupByChapter(array $cues, array $chapters): array
    {
        if ($chapters === []) {
            return [$cues];
        }
        $groups = [];
        $idx = -1;
        $chapterIdx = 0;
        $n = count($chapters);
        foreach ($cues as $c) {
            $start = $c->start ?? 0.0;
            $target = $chapterIdx;
            while ($target + 1 < $n && $start >= $chapters[$target + 1]->start) {
                $target++;
            }
            if ($idx < 0 || $target !== $chapterIdx) {
                $chapterIdx = $target;
                $groups[] = [];
                $idx++;
            }
            $groups[$idx][] = $c;
        }
        return $groups;
    }

    /**
     * Splits a group larger than a chunk. Near the end of each chunk (the last
     * 10 per cent) the boundary goes at the largest gap between cues.
     *
     * @param list<Cue> $cues
     * @return list<list<Cue>>
     */
    private function splitByGaps(array $cues): array
    {
        $out = [];
        $i = 0;
        $n = count($cues);
        while ($i < $n) {
            $tokens = 0;
            $j = $i;
            while ($j < $n && ($j === $i || $tokens + $this->cost($cues[$j]) <= $this->chunkTokens)) {
                $tokens += $this->cost($cues[$j]);
                $j++;
            }
            // $j is the first cue that does not fit (or $n).
            $cut = $j;
            if ($j < $n) {
                $windowStart = (int) floor($this->chunkTokens * 0.9);
                $acc = 0;
                $bestGap = -1.0;
                for ($k = $i; $k < $j; $k++) {
                    $acc += $this->cost($cues[$k]);
                    if ($acc < $windowStart) {
                        continue;
                    }
                    $next = $cues[$k + 1] ?? null;
                    if ($next === null) {
                        continue;
                    }
                    $gap = ($next->start !== null && $cues[$k]->end !== null) ? $next->start - $cues[$k]->end : -0.5;
                    if ($gap > $bestGap) {
                        $bestGap = $gap;
                        $cut = $k + 1;
                    }
                }
            }
            $out[] = array_slice($cues, $i, $cut - $i);
            $i = $cut;
        }
        return $out;
    }

    /**
     * @return list<Cue>
     */
    private function splitGiant(Cue $cue): array
    {
        if ($this->cost($cue) <= $this->chunkTokens) {
            return [$cue];
        }
        $limit = $this->chunkTokens - 1;
        $pieces = [];
        $rest = $cue->text;
        while ($rest !== '' && $this->estimator->estimate($rest) > $limit) {
            $piece = $this->bestPrefix($rest, $limit);
            $pieces[] = trim($piece);
            $rest = ltrim(substr($rest, strlen($piece)));
        }
        if ($rest !== '') {
            $pieces[] = $rest;
        }
        $pieces = array_values(array_filter($pieces, static fn (string $p): bool => $p !== ''));
        $total = max(1, strlen($cue->text));
        $out = [];
        $offset = 0;
        foreach ($pieces as $p) {
            $start = $cue->start;
            $end = $cue->end;
            if ($cue->start !== null && $cue->end !== null) {
                $span = $cue->end - $cue->start;
                $start = $cue->start + $span * $offset / $total;
                $end = $cue->start + $span * min($total, $offset + strlen($p)) / $total;
            }
            $out[] = new Cue($start, $end, $cue->speaker, $p, $cue->turn && $out === []);
            $offset += strlen($p) + 1;
        }
        return $out;
    }

    /** The longest prefix within $limit tokens, cut at a sentence end, else whitespace, else a character. */
    private function bestPrefix(string $text, int $limit): string
    {
        $lo = 1;
        $hi = strlen($text);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if ($this->estimator->estimate(substr($text, 0, $mid)) <= $limit) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        $max = \IndianOpinion\YtToPost\Transcript\Utf8::cut($text, $lo);
        if ($max === '') {
            $max = (string) mb_substr($text, 0, 1);
        }
        if (preg_match_all('/[.!?\x{0964}\x{3002}\x{FF01}\x{FF1F}]["\')\]]*\s/u', $max, $m, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($m[0]);
            $cut = $last[1] + strlen($last[0]);
            if ($cut > strlen($max) / 3) {
                return substr($max, 0, $cut);
            }
        }
        $ws = strrpos($max, ' ');
        if ($ws !== false && $ws > strlen($max) / 3) {
            return substr($max, 0, $ws + 1);
        }
        return $max;
    }

    /**
     * @param list<Cue> $cues
     * @param list<Chapter> $chapters
     */
    private function makeChunk(int $index, array $cues, array $chapters): Chunk
    {
        $start = null;
        $end = null;
        foreach ($cues as $c) {
            if ($c->start !== null && $start === null) {
                $start = $c->start;
            }
            if ($c->end !== null) {
                $end = $c->end;
            }
        }
        $titles = [];
        foreach ($chapters as $i => $ch) {
            $chEnd = $chapters[$i + 1]->start ?? PHP_INT_MAX;
            if ($start !== null && $end !== null && $ch->start < $end && $chEnd > $start) {
                $titles[] = $ch->title;
            } elseif ($start !== null && $end !== null && $ch->start >= $start && $ch->start <= $end) {
                $titles[] = $ch->title;
            }
        }
        return new Chunk($index, $cues, $start, $end, array_values(array_unique($titles)), $this->totalTokens($cues));
    }
}
