<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript\Parser;

use IndianOpinion\YtToPost\Transcript\Lines;
use IndianOpinion\YtToPost\Transcript\ParseResult;
use IndianOpinion\YtToPost\Transcript\Time;

/**
 * SBV: a `h:mm:ss.mmm,h:mm:ss.mmm` line, then text lines, then a blank line.
 */
final class SbvParser implements TranscriptParser
{
    public function format(): string
    {
        return 'sbv';
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
            $parts = explode(',', trim($block[0]));
            if (count($parts) !== 2) {
                $out->bad($blockLine);
            } else {
                $out->add(
                    Time::parseClock($parts[0]),
                    Time::parseClock($parts[1]),
                    implode("\n", array_slice($block, 1)),
                    $blockLine
                );
            }
            $block = [];
        }
        return $out->result($this->format());
    }
}
