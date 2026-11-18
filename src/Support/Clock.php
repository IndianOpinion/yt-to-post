<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Support;

interface Clock
{
    /** Unix seconds. */
    public function now(): int;

    /** Seconds with fractions, for measuring step lengths. */
    public function micro(): float;
}
