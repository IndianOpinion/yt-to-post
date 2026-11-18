<?php

/**
 * Uninstall: revoke the token at Google (best effort, five seconds), delete
 * options, counters, transients and events, drop the jobs table and delete
 * the plugin's post meta. Drafts and posts stay.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

\IndianOpinion\YtToPost\Uninstaller::run(
    new \IndianOpinion\YtToPost\Http\WpHttpClient(),
    new \IndianOpinion\YtToPost\Support\SystemClock()
);
