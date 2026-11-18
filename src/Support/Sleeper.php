<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Support;

interface Sleeper
{
    public function sleep(float $seconds): void;
}
