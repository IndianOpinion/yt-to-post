<?php

/**
 * Plugin Name:       yt-to-post
 * Plugin URI:        https://github.com/IndianOpinion/yt-to-post
 * Description:       Turns one YouTube video into one draft: captions through the official Data API with your own OAuth, or a transcript you supply, then a structured article with the video embedded and the source credited. Drafts only.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       yt-to-post
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

\IndianOpinion\YtToPost\Plugin::boot(__FILE__);
