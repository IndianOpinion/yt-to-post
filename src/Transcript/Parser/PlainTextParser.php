<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript\Parser;

use IndianOpinion\YtToPost\Transcript\Cue;
use IndianOpinion\YtToPost\Transcript\Lines;
use IndianOpinion\YtToPost\Transcript\ParseResult;

/**
 * Text without times. Each paragraph (blank-line separated) is one cue.
 */
final class PlainTextParser implements TranscriptParser
{
    public function format(): string
    {
        return 'text';
    }

    public function parse(string $text): ParseResult
    {
        $cues = [];
        $buf = [];
        foreach (Lines::of($text . "\n\n") as $line) {
            if (trim($line) === '') {
                if ($buf !== []) {
                    $cues[] = new Cue(null, null, null, implode("\n", $buf));
                    $buf = [];
                }
                continue;
            }
            $buf[] = trim($line);
        }
        return new ParseResult($cues, count($cues), 0, null, $this->format());
    }
}
