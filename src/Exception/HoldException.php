<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Exception;

/**
 * A veto before a paid call (the `yt_to_post_can_run` filter or a
 * spend-meter deny). The job is held, not failed.
 */
final class HoldException extends CapReachedException
{
    public function __construct(string $message, private readonly int $until)
    {
        parent::__construct($message);
    }

    public function until(): int
    {
        return $this->until;
    }
}
