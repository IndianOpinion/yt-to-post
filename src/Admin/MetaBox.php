<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Admin;

use IndianOpinion\YtToPost\Draft\DraftWriter;

/**
 * The draft notes on the edit screen: what the draft was built from, what
 * was dropped, the transcript to check quotes against, and the suggested
 * featured image.
 */
final class MetaBox
{
    public static function register(string $postType, mixed $post): void
    {
        if (!$post instanceof \WP_Post || get_post_meta($post->ID, DraftWriter::META_JOB_ID, true) === '') {
            return;
        }
        add_meta_box('yt-to-post-notes', __('Video to draft notes', 'yt-to-post'), [self::class, 'render'], $postType, 'normal', 'default');
    }

    public static function render(\WP_Post $post): void
    {
        $notes = json_decode((string) get_post_meta($post->ID, DraftWriter::META_NOTES, true), true);
        $notes = is_array($notes) ? $notes : [];
        echo '<ul>';
        foreach ($notes as $k => $v) {
            if (is_array($v)) {
                $v = implode('; ', array_map(static fn ($x): string => is_scalar($x) ? (string) $x : '', $v));
            } elseif (is_bool($v)) {
                $v = $v ? 'yes' : 'no';
            }
            echo '<li><strong>' . esc_html((string) $k) . ':</strong> ' . esc_html((string) $v) . '</li>';
        }
        echo '</ul>';
        $thumb = (string) get_post_meta($post->ID, DraftWriter::META_THUMBNAIL, true);
        if ($thumb !== '' && current_user_can('edit_post', $post->ID)) {
            $url = wp_nonce_url(add_query_arg(['action' => 'yt_to_post_use_thumbnail', 'post' => $post->ID], admin_url('admin-post.php')), 'yt_to_post_thumb_' . $post->ID);
            echo '<p><img src="' . esc_url($thumb) . '" alt="" style="max-width:240px;height:auto"><br>';
            echo '<a class="button" href="' . esc_url($url) . '">' . esc_html__('Use the video thumbnail as the featured image', 'yt-to-post') . '</a></p>';
        }
        $transcript = (string) get_post_meta($post->ID, DraftWriter::META_TRANSCRIPT, true);
        if ($transcript !== '') {
            echo '<details><summary>' . esc_html__('Transcript', 'yt-to-post') . '</summary><pre style="white-space:pre-wrap;max-height:400px;overflow:auto">' . esc_html($transcript) . '</pre></details>';
        }
    }
}
