<?php

/**
 * Global wrappers, so other code can test for them with function_exists().
 */

declare(strict_types=1);

use IndianOpinion\YtToPost\Jobs\Job;
use IndianOpinion\YtToPost\Jobs\RunSummary;
use IndianOpinion\YtToPost\Limits\KillSwitch;
use IndianOpinion\YtToPost\Plugin;
use IndianOpinion\YtToPost\Video\VideoIdParser;

if (!function_exists('yt_to_post_create')) {
    /**
     * Creates a job and returns its id. Returns the existing job's id when
     * one exists (or 0 when only an older draft exists without a job record).
     *
     * @param array<string,mixed> $args
     */
    function yt_to_post_create(string $video, array $args = []): int
    {
        $caller = get_current_user_id();
        $manage = current_user_can(\IndianOpinion\YtToPost\Capabilities::settings());
        if ($caller === 0 && !isset($args['user_id'])) {
            $args['user_id'] = \IndianOpinion\YtToPost\Settings::int('default_author');
        }
        if ($caller === 0) {
            $caller = (int) ($args['user_id'] ?? 0);
            $manage = true;
        }
        return Plugin::jobs()->create($video, $args, $caller, $manage)->jobId();
    }

    function yt_to_post_get_job(int $id): ?Job
    {
        return Plugin::services()->jobs->find($id);
    }

    function yt_to_post_find_by_video(string $video_id): ?Job
    {
        return Plugin::services()->jobs->latestForVideo($video_id);
    }

    /**
     * @param array<string,mixed> $args
     */
    function yt_to_post_supply_transcript(int $job_id, string $transcript, array $args = []): bool
    {
        return Plugin::jobs()->supplyTranscript($job_id, $transcript, $args);
    }

    function yt_to_post_cancel(int $id): bool
    {
        return Plugin::jobs()->cancel($id);
    }

    function yt_to_post_retry(int $id): bool
    {
        return Plugin::jobs()->retry($id);
    }

    function yt_to_post_confirm_rights(int $id): bool
    {
        return Plugin::jobs()->confirmRights($id, get_current_user_id());
    }

    /**
     * @param array{time?:float|int|null,max?:int|null} $opts
     */
    function yt_to_post_run(array $opts = []): RunSummary
    {
        return Plugin::runner()->run($opts);
    }

    function yt_to_post_parse_video_id(string $input): ?string
    {
        return VideoIdParser::videoId($input);
    }

    function yt_to_post_is_enabled(): bool
    {
        return KillSwitch::isEnabled();
    }
}
