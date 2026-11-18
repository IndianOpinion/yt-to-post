<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Cli;

use IndianOpinion\YtToPost\Exception\CapReachedException;
use IndianOpinion\YtToPost\Exception\DisabledException;
use IndianOpinion\YtToPost\Exception\NeedsTranscriptException;
use IndianOpinion\YtToPost\Exception\QuotaException;
use IndianOpinion\YtToPost\Jobs\Job;
use IndianOpinion\YtToPost\Jobs\JobState;

/**
 * WP-CLI exit codes: 0 success, 1 error, 3 disabled, 4 cap or quota
 * reached, 5 needs transcript.
 */
final class ExitCodes
{
    public const OK = 0;
    public const ERROR = 1;
    public const DISABLED = 3;
    public const LIMIT = 4;
    public const NEEDS_TRANSCRIPT = 5;

    public static function forError(\Throwable $e): int
    {
        return match (true) {
            $e instanceof DisabledException => self::DISABLED,
            $e instanceof CapReachedException, $e instanceof QuotaException => self::LIMIT,
            $e instanceof NeedsTranscriptException => self::NEEDS_TRANSCRIPT,
            default => self::ERROR,
        };
    }

    public static function forJob(Job $job): int
    {
        return match ($job->state) {
            JobState::DONE => self::OK,
            JobState::HELD_DISABLED => self::DISABLED,
            JobState::HELD_CAP, JobState::HELD_QUOTA, JobState::HELD_ACCOUNT => self::LIMIT,
            JobState::NEEDS_TRANSCRIPT => self::NEEDS_TRANSCRIPT,
            JobState::FAILED, JobState::CANCELLED, JobState::NEEDS_AUTH, JobState::NEEDS_RIGHTS => self::ERROR,
            default => self::OK,
        };
    }
}
