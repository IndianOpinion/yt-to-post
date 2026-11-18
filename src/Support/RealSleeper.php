<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Support;

final class RealSleeper implements Sleeper
{
    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1000000));
        }
    }
}
