<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript\Parser;

use IndianOpinion\YtToPost\Transcript\Cue;
use IndianOpinion\YtToPost\Transcript\Lines;
use IndianOpinion\YtToPost\Transcript\ParseResult;
use IndianOpinion\YtToPost\Transcript\Time;

/**
 * Lines that start with `[h:mm:ss]`, `(m:ss)` or `h:mm:ss` and text. A
 * cue ends where the next one starts. Lines without a stamp continue the
 * previous cue.
 */
final class TimestampedTextParser implements TranscriptParser
{
    public const LINE = '/^\s*(?:\[((?:\d+:)?\d{1,2}:\d{2}(?:\.\d+)?)\]|\(((?:\d+:)?\d{1,2}:\d{2}(?:\.\d+)?)\)|((?:\d+:)?\d{1,2}:\d{2}(?:\.\d+)?)(?=\s))\s*[-:]?\s*(.*)$/u';

    public function format(): string
    {
        return 'text-timestamped';
    }

    public function parse(string $text): ParseResult
    {
        /** @var list<array{0:float,1:string}> $items */
        $items = [];
        $total = 0;
        $bad = 0;
        $firstBad = null;
        $last = null;
        foreach (Lines::of($text) as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match(self::LINE, $line, $m) === 1) {
                $stamp = $m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : $m[3]);
                $total++;
                $t = Time::parseClock($stamp);
                if ($t === null || ($last !== null && $t < $last)) {
                    $bad++;
                    $firstBad ??= $n;
                    continue;
                }
                $last = $t;
                $items[] = [$t, trim($m[4])];
                continue;
            }
            if ($items !== []) {
                $items[count($items) - 1][1] .= "\n" . trim($line);
            }
        }
        $cues = [];
        $count = count($items);
        foreach ($items as $i => [$start, $body]) {
            $end = $i + 1 < $count ? $items[$i + 1][0] : null;
            $cues[] = new Cue($start, $end ?? $start, null, $body);
        }
        return new ParseResult($cues, $total, $bad, $firstBad, $this->format());
    }

    /** True when most non-empty lines among the first 40 carry a stamp. */
    public static function looksLike(string $text): bool
    {
        $seen = 0;
        $hits = 0;
        foreach (Lines::of($text) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $seen++;
            if (preg_match(self::LINE, $line) === 1) {
                $hits++;
            }
            if ($seen >= 40) {
                break;
            }
        }
        return $seen > 0 && $hits * 2 >= $seen;
    }
}
