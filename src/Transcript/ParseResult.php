<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript;

final class ParseResult
{
    /**
     * @param list<Cue> $cues
     */
    public function __construct(
        public readonly array $cues,
        public readonly int $total,
        public readonly int $malformed,
        public readonly ?int $firstBadLine,
        public readonly string $format
    ) {
    }
}
