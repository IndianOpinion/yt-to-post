<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

/**
 * Which jobs the runner picks up.
 */
final class RunnablePolicy
{
    public static function isRunnable(Job $job, int $now, bool $enabled): bool
    {
        if (JobState::isActive($job->state)) {
            return $job->heldUntil <= $now;
        }
        if ($job->state === JobState::HELD_DISABLED) {
            return $enabled;
        }
        if (in_array($job->state, [JobState::HELD_CAP, JobState::HELD_QUOTA, JobState::HELD_ACCOUNT], true)) {
            return $job->heldUntil <= $now;
        }
        return false;
    }
}
