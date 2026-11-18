<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

/**
 * WP-Cron scheduling for the runner and the daily housekeeping.
 */
final class Scheduler
{
    public const RUN_HOOK = 'yt_to_post_run';
    public const HOUSEKEEPING_HOOK = 'yt_to_post_housekeeping';

    /** Schedules a run now unless one is already scheduled. */
    public static function kick(int $at = 0): void
    {
        if (!function_exists('wp_schedule_single_event')) {
            return;
        }
        $at = $at > 0 ? $at : time();
        $next = wp_next_scheduled(self::RUN_HOOK);
        if ($next !== false && $next <= $at) {
            return;
        }
        if ($next !== false) {
            wp_unschedule_event($next, self::RUN_HOOK);
        }
        wp_schedule_single_event($at, self::RUN_HOOK);
    }

    public static function ensureHousekeeping(): void
    {
        if (function_exists('wp_next_scheduled') && wp_next_scheduled(self::HOUSEKEEPING_HOOK) === false) {
            wp_schedule_event(time() + 3600, 'daily', self::HOUSEKEEPING_HOOK);
        }
    }

    public static function clear(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::RUN_HOOK);
            wp_clear_scheduled_hook(self::HOUSEKEEPING_HOOK);
        }
    }
}
