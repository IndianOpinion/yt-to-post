<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Support;

use IndianOpinion\YtToPost\Support\Clock;

final class FakeClock implements Clock
{
    public float $t;

    public function __construct(int $start = 1794736000)
    {
        $this->t = (float) $start;
        \WpStub::$now = $start;
    }

    public function now(): int
    {
        return (int) floor($this->t);
    }

    public function micro(): float
    {
        return $this->t;
    }

    public function advance(float $seconds): void
    {
        $this->t += $seconds;
        \WpStub::$now = (int) floor($this->t);
    }

    public function set(int $ts): void
    {
        $this->t = (float) $ts;
        \WpStub::$now = $ts;
    }
}
