<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

/**
 * Job states (PRD case 80). Steps run in order; side states hold a job
 * with the step it resumes at kept in `step`.
 */
final class JobState
{
    public const QUEUED = 'queued';
    public const METADATA = 'metadata';
    public const CAPTIONS = 'captions';
    public const NORMALISE = 'normalise';
    public const MAP = 'map';
    public const REDUCE = 'reduce';
    public const DRAFT = 'draft';
    public const DONE = 'done';

    public const NEEDS_TRANSCRIPT = 'needs_transcript';
    public const NEEDS_AUTH = 'needs_auth';
    public const NEEDS_RIGHTS = 'needs_rights';
    public const HELD_DISABLED = 'held_disabled';
    public const HELD_CAP = 'held_cap';
    public const HELD_QUOTA = 'held_quota';
    public const HELD_ACCOUNT = 'held_account';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    public const STEPS = [self::QUEUED, self::METADATA, self::CAPTIONS, self::NORMALISE, self::MAP, self::REDUCE, self::DRAFT];
    public const HELD = [self::HELD_DISABLED, self::HELD_CAP, self::HELD_QUOTA, self::HELD_ACCOUNT];
    public const WAITING = [self::NEEDS_TRANSCRIPT, self::NEEDS_AUTH, self::NEEDS_RIGHTS];
    public const TERMINAL = [self::DONE, self::FAILED, self::CANCELLED];

    public const ALL = [
        self::QUEUED, self::METADATA, self::CAPTIONS, self::NORMALISE, self::MAP, self::REDUCE, self::DRAFT, self::DONE,
        self::NEEDS_TRANSCRIPT, self::NEEDS_AUTH, self::NEEDS_RIGHTS, self::HELD_DISABLED, self::HELD_CAP,
        self::HELD_QUOTA, self::HELD_ACCOUNT, self::FAILED, self::CANCELLED,
    ];

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }

    public static function isActive(string $state): bool
    {
        return in_array($state, self::STEPS, true);
    }
}
