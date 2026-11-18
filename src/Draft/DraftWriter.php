<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Draft;

use IndianOpinion\YtToPost\Article\Article;
use IndianOpinion\YtToPost\Exception\YtToPostException;
use IndianOpinion\YtToPost\Jobs\Job;
use IndianOpinion\YtToPost\Settings;
use IndianOpinion\YtToPost\Transcript\Transcript;
use IndianOpinion\YtToPost\Video\VideoMeta;

/**
 * Creates the draft (PRD cases 67 and 68). No filter can publish or
 * schedule it: status is forced back and dates are cleared after filters.
 */
final class DraftWriter
{
    public const META_VIDEO_ID = '_yt_to_post_video_id';
    public const META_SOURCE_KIND = '_yt_to_post_source_kind';
    public const META_TRANSCRIPT_LANGUAGE = '_yt_to_post_transcript_language';
    public const META_ARTICLE_LANGUAGE = '_yt_to_post_article_language';
    public const META_JOB_ID = '_yt_to_post_job_id';
    public const META_NOTES = '_yt_to_post_notes';
    public const META_TRANSCRIPT = '_yt_to_post_transcript';
    public const META_TRANSCRIPT_SAVED = '_yt_to_post_transcript_saved_at';
    public const META_THUMBNAIL = '_yt_to_post_suggested_thumbnail';

    public const META_KEYS = [
        self::META_VIDEO_ID, self::META_SOURCE_KIND, self::META_TRANSCRIPT_LANGUAGE, self::META_ARTICLE_LANGUAGE,
        self::META_JOB_ID, self::META_NOTES, self::META_TRANSCRIPT, self::META_TRANSCRIPT_SAVED, self::META_THUMBNAIL,
    ];

    /**
     * @param array<string,mixed> $notes
     */
    public function write(Job $job, Article $article, VideoMeta $meta, Transcript $transcript, array $notes, bool $offerThumbnail, int $now): int
    {
        $builder = new BlockBuilder(Settings::bool('privacy_embed'), $transcript->hasTimes());
        $content = $builder->build($article, $meta, $job->sourceKind, CreditBuilder::build($meta, $job->sourceKind));
        $filtered = apply_filters('yt_to_post_content', $content, $article, $job);
        if (is_string($filtered) && $filtered !== '') {
            $content = $filtered;
        }
        $status = self::status((string) $job->arg('status', Settings::string('default_status')));
        $author = (int) $job->userId;
        if ($author <= 0) {
            $author = Settings::int('default_author');
        }
        $category = (int) $job->arg('category', 0);
        if ($category <= 0) {
            $category = Settings::int('default_category');
        }
        $tags = array_values(array_filter(array_map('trim', explode(',', Settings::string('default_tags')))));
        if (Settings::bool('topics_as_tags')) {
            $tags = array_values(array_unique(array_merge($tags, $article->topics)));
        }
        $args = [
            'post_type' => 'post',
            'post_title' => $article->title,
            'post_excerpt' => $article->standfirst,
            'post_content' => $content,
            'post_status' => $status,
            'post_author' => $author,
            'tags_input' => $tags,
            'meta_input' => [],
        ];
        if ($category > 0) {
            $args['post_category'] = [$category];
        }
        $filteredArgs = apply_filters('yt_to_post_post_args', $args, $article, $job);
        if (is_array($filteredArgs)) {
            $args = $filteredArgs;
        }
        // Lesson: never trust a filter to keep a draft a draft.
        $args['post_status'] = in_array($args['post_status'] ?? null, ['draft', 'pending'], true) ? $args['post_status'] : $status;
        unset($args['post_date'], $args['post_date_gmt'], $args['edit_date'], $args['ID'], $args['import_id']);
        $args['post_name'] = '';

        $postId = wp_insert_post(wp_slash($args), true);
        if (is_wp_error($postId)) {
            throw new YtToPostException('Could not create the draft: ' . $postId->get_error_message());
        }
        $postId = (int) $postId;
        if ($postId <= 0) {
            throw new YtToPostException('Could not create the draft.');
        }
        update_post_meta($postId, self::META_VIDEO_ID, $meta->id);
        update_post_meta($postId, self::META_SOURCE_KIND, $job->sourceKind);
        update_post_meta($postId, self::META_TRANSCRIPT_LANGUAGE, $job->transcriptLanguage);
        update_post_meta($postId, self::META_ARTICLE_LANGUAGE, $article->language);
        update_post_meta($postId, self::META_JOB_ID, $job->id);
        update_post_meta($postId, self::META_NOTES, wp_slash((string) wp_json_encode($notes)));
        update_post_meta($postId, self::META_TRANSCRIPT, wp_slash($transcript->toReadableText()));
        update_post_meta($postId, self::META_TRANSCRIPT_SAVED, $now);
        if ($offerThumbnail && VideoMeta::isThumbnailUrl($meta->thumbnail)) {
            update_post_meta($postId, self::META_THUMBNAIL, $meta->thumbnail);
        }
        return $postId;
    }

    public static function status(string $s): string
    {
        return $s === 'pending' ? 'pending' : 'draft';
    }
}
