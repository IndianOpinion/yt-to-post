<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript\Parser;

use IndianOpinion\YtToPost\Transcript\Lines;
use IndianOpinion\YtToPost\Transcript\ParseResult;
use IndianOpinion\YtToPost\Transcript\Time;

/**
 * SubRip: numbered blocks, `00:00:01,000 --> 00:00:04,000`, then text.
 */
final class SrtParser implements TranscriptParser
{
    public function format(): string
    {
        return 'srt';
    }

    public function parse(string $text): ParseResult
    {
        $out = new CueCollector();
        $block = [];
        $blockLine = 0;
        foreach (Lines::of($text . "\n\n") as $n => $line) {
            if (trim($line) !== '') {
                if ($block === []) {
                    $blockLine = $n;
                }
                $block[] = $line;
                continue;
            }
            if ($block === []) {
                continue;
            }
            $timing = null;
            foreach ([0, 1] as $i) {
                if (isset($block[$i]) && str_contains($block[$i], '-->')) {
                    $timing = $i;
                    break;
                }
            }
            if ($timing === null) {
                $out->bad($blockLine);
            } else {
                [$a, $b] = array_map('trim', explode('-->', $block[$timing], 2));
                $b = preg_split('/\s+/', $b)[0] ?? '';
                $out->add(
                    Time::parseClock($a),
                    Time::parseClock($b),
                    implode("\n", array_slice($block, $timing + 1)),
                    $blockLine + $timing
                );
            }
            $block = [];
        }
        return $out->result($this->format());
    }
}
