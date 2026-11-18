<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

/**
 * Saves a job and fires `yt_to_post_job_state` when its state changed. With
 * WP_DEBUG_LOG on, one line per change goes to the debug log, without
 * transcript text.
 */
final class JobEvents
{
    public static function save(JobRepository $repo, Job $job, string $from): void
    {
        $repo->save($job);
        if ($from === $job->state) {
            return;
        }
        if (function_exists('do_action')) {
            do_action('yt_to_post_job_state', $job, $from, $job->state);
        }
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf(
                'yt-to-post: job %d (%s) %s -> %s%s',
                $job->id,
                $job->videoId,
                $from,
                $job->state,
                $job->lastError !== '' ? ' (' . substr($job->lastError, 0, 200) . ')' : ''
            ));
        }
    }
}
