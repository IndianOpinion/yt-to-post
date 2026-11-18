<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Integrations;

/**
 * Soft integration with gen-queue (PRD case 82). Active only when
 * GEN_QUEUE_VERSION is defined. The plugin's own switch, caps and quota
 * still apply first, inside the job handler.
 */
final class GenQueue
{
    public const JOB_TYPE = 'yt_to_post.article';

    public static function active(): bool
    {
        return defined('GEN_QUEUE_VERSION');
    }

    public static function idempotencyKey(string $videoId, int $force): string
    {
        return 'yt-to-post:' . $videoId . ':' . $force;
    }

    /**
     * Registers the job type. The handler runs one job for up to the given
     * number of seconds.
     *
     * @param callable(int,float):mixed $handler
     */
    public static function register(callable $handler): void
    {
        if (!self::active()) {
            return;
        }
        add_filter(Hooks::name('gen_queue_register'), static function ($types) use ($handler) {
            $types = is_array($types) ? $types : [];
            $types[self::JOB_TYPE] = [
                'label' => 'Video to draft',
                'handler' => static function (array $payload, array $context = []) use ($handler) {
                    $seconds = isset($context['seconds']) && is_numeric($context['seconds']) ? (float) $context['seconds'] : 25.0;
                    return $handler((int) ($payload['job_id'] ?? 0), $seconds);
                },
            ];
            return $types;
        });
    }

    /**
     * Enqueues through gen-queue's soft-enqueue filter. True when gen-queue
     * accepted it; false means the plugin should run the job itself.
     */
    public static function enqueue(int $jobId, string $videoId, int $force): bool
    {
        if (!self::active()) {
            return false;
        }
        $result = apply_filters(
            Hooks::name('gen_queue_enqueue'),
            null,
            self::JOB_TYPE,
            ['job_id' => $jobId, 'video_id' => $videoId],
            ['idempotency_key' => self::idempotencyKey($videoId, $force), 'caller' => 'yt-to-post']
        );
        return $result !== null && $result !== false && !(function_exists('is_wp_error') && is_wp_error($result));
    }
}
