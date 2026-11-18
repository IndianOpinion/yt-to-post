<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Draft;

use IndianOpinion\YtToPost\Transcript\Transcript;
use IndianOpinion\YtToPost\Video\VideoMeta;

/**
 * The source credit (PRD case 66). It cannot be turned off; a filter may
 * restyle it, but a result without the watch URL falls back to the default.
 */
final class CreditBuilder
{
    public static function build(VideoMeta $meta, string $sourceKind): string
    {
        $default = self::defaultHtml($meta, $sourceKind);
        if (!function_exists('apply_filters')) {
            return $default;
        }
        $filtered = apply_filters('yt_to_post_credit_html', $default, $meta, $sourceKind);
        if (!is_string($filtered) || !str_contains($filtered, BlockBuilder::watchUrl($meta->id))) {
            if ($filtered !== $default && function_exists('error_log') && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('yt-to-post: yt_to_post_credit_html result has no watch URL; default credit used.');
            }
            return $default;
        }
        return $filtered;
    }

    public static function defaultHtml(VideoMeta $meta, string $sourceKind): string
    {
        $watch = BlockBuilder::watchUrl($meta->id);
        $title = '<a href="' . esc_url($watch) . '">' . esc_html($meta->title !== '' ? $meta->title : $meta->id) . '</a>';
        $channelLink = $meta->channelLink();
        $channel = $meta->channelTitle !== ''
            ? ($channelLink !== '' ? '<a href="' . esc_url($channelLink) . '">' . esc_html($meta->channelTitle) . '</a>' : esc_html($meta->channelTitle))
            : '';
        $line = 'Source: ' . $title . ($channel !== '' ? ' by ' . $channel : '');
        $ts = $meta->publishedAt !== '' ? strtotime($meta->publishedAt) : false;
        if ($ts !== false) {
            $date = function_exists('wp_date') ? (string) wp_date((string) get_option('date_format', 'j F Y'), $ts) : gmdate('j F Y', $ts);
            $line .= ', published on YouTube on ' . esc_html($date) . '.';
        } else {
            $line .= ', on YouTube.';
        }
        $source = match ($sourceKind) {
            Transcript::KIND_CAPTIONS => "Based on the video's captions.",
            Transcript::KIND_CAPTIONS_ASR => "Based on YouTube's automatic captions.",
            default => 'Based on a transcript supplied by the editor.',
        };
        return BlockBuilder::paragraph($line) . "\n\n" . BlockBuilder::paragraph(esc_html($source));
    }
}
