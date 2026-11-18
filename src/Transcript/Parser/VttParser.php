<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript\Parser;

use IndianOpinion\YtToPost\Transcript\Lines;
use IndianOpinion\YtToPost\Transcript\ParseResult;
use IndianOpinion\YtToPost\Transcript\Time;

/**
 * WebVTT. NOTE, STYLE and REGION blocks and cue settings are dropped here;
 * inline tags stay in the text for the cleaner.
 */
final class VttParser implements TranscriptParser
{
    public function format(): string
    {
        return 'vtt';
    }

    public function parse(string $text): ParseResult
    {
        $out = new CueCollector();
        $block = [];
        $blockLine = 0;
        $inHeader = true;
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
            if ($inHeader) {
                $inHeader = false;
                if (str_starts_with($block[0], 'WEBVTT')) {
                    $block = [];
                    continue;
                }
            }
            $this->block($block, $blockLine, $out);
            $block = [];
        }
        return $out->result($this->format());
    }

    /**
     * @param list<string> $block
     */
    private function block(array $block, int $line, CueCollector $out): void
    {
        if (preg_match('/^(NOTE|STYLE|REGION)(\s|$)/', $block[0]) === 1) {
            return;
        }
        $timing = null;
        foreach ([0, 1] as $i) {
            if (isset($block[$i]) && str_contains($block[$i], '-->')) {
                $timing = $i;
                break;
            }
        }
        if ($timing === null) {
            $out->bad($line);
            return;
        }
        [$a, $b] = array_map('trim', explode('-->', $block[$timing], 2));
        $b = preg_split('/\s+/', $b)[0] ?? '';
        $textLines = array_slice($block, $timing + 1);
        $out->add(Time::parseClock($a), Time::parseClock($b), implode("\n", $textLines), $line + $timing);
    }
}
