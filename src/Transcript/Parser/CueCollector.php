<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript\Parser;

use IndianOpinion\YtToPost\Transcript\Cue;
use IndianOpinion\YtToPost\Transcript\ParseResult;

/**
 * Collects timed cues and counts malformed ones: unparsable times, an end
 * before its start, or a start earlier than the previous cue's start.
 */
final class CueCollector
{
    /** @var list<Cue> */
    private array $cues = [];
    private int $total = 0;
    private int $malformed = 0;
    private ?int $firstBad = null;
    private ?float $lastStart = null;

    public function add(?float $start, ?float $end, string $text, int $line): void
    {
        $this->total++;
        if ($start === null || $end === null || $end < $start || ($this->lastStart !== null && $start < $this->lastStart)) {
            $this->markBad($line);
            return;
        }
        $this->lastStart = $start;
        $this->cues[] = new Cue($start, $end, null, $text);
    }

    /** A cue block whose timing line could not be read at all. */
    public function bad(int $line): void
    {
        $this->total++;
        $this->markBad($line);
    }

    private function markBad(int $line): void
    {
        $this->malformed++;
        $this->firstBad ??= $line;
    }

    public function result(string $format): ParseResult
    {
        return new ParseResult($this->cues, $this->total, $this->malformed, $this->firstBad, $format);
    }
}
