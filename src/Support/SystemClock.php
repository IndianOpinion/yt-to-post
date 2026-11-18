<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Support;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }

    public function micro(): float
    {
        return microtime(true);
    }
}
