<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

use IndianOpinion\YtToPost\Support\Options;

/**
 * One runner per site: an option holding the lock's expiry time. add_option
 * is an atomic insert, so two runners cannot both take a free lock, and an
 * expired lock is taken over with a conditional UPDATE. The daily caps and
 * the pipeline's quota reservations are only written under this lock.
 */
final class SiteLock
{
    public const OPTION = 'yt_to_post_runner_lock';

    public static function acquire(int $now, int $ttl): bool
    {
        if (add_option(self::OPTION, $now + $ttl, '', false)) {
            return true;
        }
        $raw = Options::fresh(self::OPTION, 0);
        $until = is_numeric($raw) ? (int) $raw : 0;
        if ($until > $now) {
            return false;
        }
        // Take over an expired lock with a compare-and-swap, so two runners
        // that both saw it expire cannot both win.
        global $wpdb;
        if (is_object($wpdb) && method_exists($wpdb, 'query') && method_exists($wpdb, 'prepare') && isset($wpdb->options)) {
            $won = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                (string) ($now + $ttl),
                self::OPTION,
                is_scalar($raw) ? (string) $raw : ''
            ));
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete(self::OPTION, 'options');
            }
            if ($won !== 1) {
                return false;
            }
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
