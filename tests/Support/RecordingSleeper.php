<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Support;

use IndianOpinion\YtToPost\Support\Sleeper;

final class RecordingSleeper implements Sleeper
{
    /** @var list<float> */
    public array $sleeps = [];

    public function __construct(private readonly ?FakeClock $clock = null)
    {
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->clock?->advance($seconds);
    }
}
