<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost;

use IndianOpinion\YtToPost\Draft\DraftWriter;
use IndianOpinion\YtToPost\Http\HttpClient;
use IndianOpinion\YtToPost\Jobs\Housekeeping;
use IndianOpinion\YtToPost\Jobs\Pipeline;
use IndianOpinion\YtToPost\Jobs\Runner;
use IndianOpinion\YtToPost\Jobs\Scheduler;
use IndianOpinion\YtToPost\Jobs\SchemaInstaller;
use IndianOpinion\YtToPost\Jobs\SiteLock;
use IndianOpinion\YtToPost\Limits\DailyCaps;
use IndianOpinion\YtToPost\Limits\QuotaMeter;
use IndianOpinion\YtToPost\Support\Clock;
use IndianOpinion\YtToPost\Support\Days;
use IndianOpinion\YtToPost\YouTube\AccessTokens;
use IndianOpinion\YtToPost\YouTube\OAuthClient;
use IndianOpinion\YtToPost\YouTube\TokenVault;

/**
 * Removes everything the plugin created, on every site of a network.
 * Drafts and posts stay: they belong to the site.
 */
final class Uninstaller
{
    public const OPTIONS = [
        Settings::OPTION, 'yt_to_post_connection', 'yt_to_post_version', SchemaInstaller::VERSION_OPTION,
        SiteLock::OPTION, Runner::LAST_TICK_OPTION, Runner::STEP_TIMES_OPTION, Pipeline::ACCOUNT_HOLD_OPTION,
        QuotaMeter::EXHAUSTED_OPTION,
    ];

    public static function run(HttpClient $http, Clock $clock, ?TokenVault $vault = null): void
    {
        if (function_exists('is_multisite') && is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::site($http, $clock, $vault);
                restore_current_blog();
            }
            return;
        }
        self::site($http, $clock, $vault);
    }

    public static function site(HttpClient $http, Clock $clock, ?TokenVault $vault = null): void
    {
        global $wpdb;
        // Best effort revoke with a five-second timeout; local data goes either way.
        (new OAuthClient($http, $vault ?? new TokenVault(), $clock))->disconnect(5);

        foreach (self::OPTIONS as $o) {
            delete_option($o);
        }
        $now = $clock->now();
        for ($d = -1; $d <= Housekeeping::COUNTER_DAYS_KEPT + 40; $d++) {
            $t = $now - $d * 86400;
            delete_option(DailyCaps::VIDEOS_PREFIX . Days::siteKey($t));
            delete_option(DailyCaps::CALLS_PREFIX . Days::siteKey($t));
            delete_option(QuotaMeter::PREFIX . Days::pacificKey($t));
        }
        delete_transient(AccessTokens::TRANSIENT);
        if ($wpdb instanceof \wpdb) {
            $like = $wpdb->esc_like('_transient_yt_to_post_') . '%';
            $likeTimeout = $wpdb->esc_like('_transient_timeout_yt_to_post_') . '%';
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $likeTimeout));
            $table = SchemaInstaller::table($wpdb);
            $wpdb->query("DROP TABLE IF EXISTS `$table`");
        }
        Scheduler::clear();
        foreach (DraftWriter::META_KEYS as $key) {
            delete_post_meta_by_key($key);
        }
    }
}
