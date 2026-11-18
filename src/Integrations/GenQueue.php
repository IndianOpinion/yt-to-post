<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Integrations;

/**
 * Soft integration with gen-queue (PRD case 82), written against its
 * published interface: the job type is registered on the
 * `gen_queue_register_types` action with `gen_queue_register_type()`, and
 * jobs are enqueued through the `gen_queue_enqueue_job` filter, which
 * returns a job id when gen-queue is active, null when it is not, and a
 * WP_Error when the enqueue failed.
 *
 * Active only when GEN_QUEUE_VERSION is defined. The plugin's own switch,
 * caps and quota still apply first, inside the job handler.
 */
final class GenQueue
{
    public const JOB_TYPE = 'yt_to_post.article';
    public const SOURCE = 'yt-to-post';

    public static function active(): bool
    {
        return defined('GEN_QUEUE_VERSION');
    }

    /**
     * The video id plus the force counter (PRD case 82). A resumed job (retry,
     * supplied transcript, confirmed rights) adds its resume counter, because
     * gen-queue never requeues a finished job under the same key.
     */
    public static function idempotencyKey(string $videoId, int $force, int $resume = 0): string
    {
        return 'yt-to-post:' . $videoId . ':' . $force . ($resume > 0 ? ':r' . $resume : '');
    }

    /**
     * Registers the job type. The handler gets the plugin's job id and the
     * seconds it may work, and returns what it did plus the model cost it
     * spent, so the queue's own cap counts it.
     *
     * @param callable(int,float):array{summary:array<string,mixed>,cost:float} $handler
     */
    public static function register(callable $handler): void
    {
        if (!self::active()) {
            return;
        }
        $register = static function () use ($handler): void {
            if (!function_exists('gen_queue_register_type')) {
                return;
            }
            gen_queue_register_type(self::JOB_TYPE, self::handler($handler), []);
        };
        if (function_exists('did_action') && did_action('gen_queue_register_types') > 0) {
            $register();
            return;
        }
        add_action('gen_queue_register_types', $register);
    }

    /**
     * The handler gen-queue calls with its Job and JobContext. Their classes
     * belong to gen-queue, so they are read by their documented members only.
     *
     * @param callable(int,float):array{summary:array<string,mixed>,cost:float} $handler
     * @return \Closure(object,object):array<string,mixed>
     */
    public static function handler(callable $handler): \Closure
    {
        return static function (object $job, object $ctx) use ($handler): array {
            $payload = property_exists($job, 'payload') && is_array($job->payload) ? $job->payload : [];
            $jobId = isset($payload['job_id']) && is_numeric($payload['job_id']) ? (int) $payload['job_id'] : 0;
            $seconds = 25.0;
            if (method_exists($ctx, 'deadline')) {
                $seconds = max(5.0, (float) $ctx->deadline() - time() - 5);
            }
            $out = $handler($jobId, $seconds);
            if ($out['cost'] > 0 && method_exists($ctx, 'addCost')) {
                // With spend-meter present the plugin has already recorded the
                // call there, so gen-queue must not forward it again.
                $ctx->addCost(sprintf('%.9F', $out['cost']), ['metered' => SpendMeter::active()]);
            }
            return $out['summary'];
        };
    }

    /**
     * Enqueues through gen-queue's soft-enqueue filter. True when gen-queue
     * accepted it (it answered with a job id); false means the plugin runs
     * the job itself.
     */
    public static function enqueue(int $jobId, string $videoId, int $force, int $resume = 0): bool
    {
        if (!self::active()) {
            return false;
        }
        $result = apply_filters(
            'gen_queue_enqueue_job',
            null,
            self::JOB_TYPE,
            ['job_id' => $jobId, 'video_id' => $videoId],
            ['idempotency_key' => self::idempotencyKey($videoId, $force, $resume), 'source' => self::SOURCE]
        );
        return is_int($result) && $result > 0;
    }
}
