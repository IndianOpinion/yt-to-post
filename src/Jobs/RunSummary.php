<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

final class RunSummary
{
    public function __construct(
        public readonly int $claimed = 0,
        public readonly int $done = 0,
        public readonly int $held = 0,
        public readonly int $failed = 0,
        public readonly float $seconds = 0.0,
        public readonly string $stopReason = 'idle'
    ) {
    }

    /** @return array<string,int|float|string> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
