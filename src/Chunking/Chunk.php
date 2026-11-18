<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Chunking;

use IndianOpinion\YtToPost\Transcript\Cue;

final class Chunk
{
    /**
     * @param list<Cue> $cues
     * @param list<string> $chapterTitles
     */
    public function __construct(
        public readonly int $index,
        public readonly array $cues,
        public readonly ?float $start,
        public readonly ?float $end,
        public readonly array $chapterTitles,
        public readonly int $tokens
    ) {
    }
}
