<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

use IndianOpinion\YtToPost\Support\Options;

/**
 * One runner per site: an option holding the lock's expiry time. add_option
 * is an atomic insert, so two runners cannot both take a free lock.
 */
final class SiteLock
{
    public const OPTION = 'yt_to_post_runner_lock';

    public static function acquire(int $now, int $ttl): bool
    {
        if (add_option(self::OPTION, $now + $ttl, '', false)) {
            return true;
        }
        $until = Options::freshInt(self::OPTION);
        if ($until > $now) {
            return false;
        }
        update_option(self::OPTION, $now + $ttl, false);
        return true;
    }

    public static function refresh(int $now, int $ttl): void
    {
        update_option(self::OPTION, $now + $ttl, false);
    }

    public static function release(): void
    {
        delete_option(self::OPTION);
    }

    /** Per-video lock with a 15 minute time-to-live, refreshed each step. */
    public static function video(string $videoId, int $jobId): bool
    {
        $key = 'yt_to_post_vlock_' . $videoId;
        $holder = get_transient($key);
        if ($holder !== false && (int) $holder !== $jobId) {
            return false;
        }
        set_transient($key, $jobId, 900);
        return true;
    }

    public static function releaseVideo(string $videoId): void
    {
        delete_transient('yt_to_post_vlock_' . $videoId);
    }
}
