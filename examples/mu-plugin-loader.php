<?php

/**
 * Must-use loader. Copy this file to wp-content/mu-plugins/yt-to-post-loader.php
 * and the plugin folder to wp-content/mu-plugins/yt-to-post/.
 *
 * Must-use plugins never run activation hooks, so this loader creates the
 * jobs table on first admin load instead.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/yt-to-post/autoload.php';

\IndianOpinion\YtToPost\Plugin::boot(__DIR__ . '/yt-to-post/yt-to-post.php');
