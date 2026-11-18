<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

use IndianOpinion\YtToPost\Draft\DraftWriter;
use IndianOpinion\YtToPost\Limits\DailyCaps;
use IndianOpinion\YtToPost\Limits\QuotaMeter;
use IndianOpinion\YtToPost\Services;
use IndianOpinion\YtToPost\Settings;
use IndianOpinion\YtToPost\Support\Days;
use IndianOpinion\YtToPost\Video\VideoMeta;
use IndianOpinion\YtToPost\YouTube\YouTubeClient;

/**
 * The daily retention pass. Every removal here follows an operator
 * setting and leaves a line in the job log; nothing is purged anywhere else.
 */
final class Housekeeping
{
    public const COUNTER_DAYS_KEPT = 7;
    public const MAX_REFRESH_PER_RUN = 100;

    public function __construct(private readonly Services $s)
    {
    }

    /**
     * @return array{working_data_cleared:int,rows_deleted:int,metadata_purged:int,metadata_refreshed:int,draft_transcripts_removed:int}
     */
    public function run(bool $dryRun = false): array
    {
        $now = $this->s->clock->now();
        $days = $this->retentionDays();
        $stats = ['working_data_cleared' => 0, 'rows_deleted' => 0, 'metadata_purged' => 0, 'metadata_refreshed' => 0, 'draft_transcripts_removed' => 0];

        foreach ($this->s->jobs->endedBefore($now - 2 * $days * 86400, 500) as $job) {
            $stats['rows_deleted']++;
            if (!$dryRun) {
                $this->s->jobs->delete($job->id);
            }
        }
        foreach ($this->s->jobs->endedBefore($now - $days * 86400, 500) as $job) {
            if ($job->source === null && $job->transcript === null && $job->notes === [] && $job->article === null) {
                continue;
            }
            $stats['working_data_cleared']++;
            if (!$dryRun) {
                $this->s->jobs->save($job->with(['source' => null, 'transcript' => null, 'notes' => [], 'article' => null])
                    ->withLog(sprintf('transcript and notes cleared under the retention setting (%d days)', $days), $now));
            }
        }

        $apiDays = Settings::int('api_data_days');
        $refresh = Settings::string('api_data_on_expiry') === 'refresh' && YouTubeClient::canReadMetadata();
        $refreshed = 0;
        foreach ($this->s->jobs->metadataBefore($now - $apiDays * 86400, 500) as $job) {
            if ($refresh && $refreshed < self::MAX_REFRESH_PER_RUN) {
                if ($dryRun) {
                    $stats['metadata_refreshed']++;
                    $refreshed++;
                    continue;
                }
                try {
                    $item = $this->s->youtube()->video($job->videoId);
                    $refreshed++;
                    if ($item !== null) {
                        $this->s->jobs->save($job->with([
                            'metadata' => VideoMeta::fromApiItem($item)->toArray(),
                            'metadataAt' => $now,
                        ])->withLog(sprintf('video details refreshed under the API data setting (%d days)', $apiDays), $now));
                        $stats['metadata_refreshed']++;
                        continue;
                    }
                } catch (\Throwable $e) {
                    // Could not refresh today: try again tomorrow; never purge as a side effect of a failure.
                    $this->s->jobs->save($job->withLog('video details refresh failed, will retry: ' . $e->getMessage(), $now));
                    continue;
                }
            } elseif ($refresh) {
                continue;
            }
            $stats['metadata_purged']++;
            if (!$dryRun) {
                $this->s->jobs->save($job->with(['metadata' => null, 'metadataAt' => 0, 'chapters' => null])
                    ->withLog(sprintf('video details purged under the API data setting (%d days)', $apiDays), $now));
            }
        }

        $draftDays = Settings::int('draft_transcript_days');
        if ($draftDays > 0) {
            $ids = get_posts([
                'post_type' => 'any',
                'post_status' => 'any',
                'fields' => 'ids',
                'numberposts' => 500,
                'suppress_filters' => true,
                'meta_query' => [[
                    'key' => DraftWriter::META_TRANSCRIPT_SAVED,
                    'value' => $now - $draftDays * 86400,
                    'compare' => '<',
                    'type' => 'NUMERIC',
                ]],
            ]);
            foreach (is_array($ids) ? $ids : [] as $postId) {
                $stats['draft_transcripts_removed']++;
                if (!$dryRun) {
                    delete_post_meta((int) $postId, DraftWriter::META_TRANSCRIPT);
                    delete_post_meta((int) $postId, DraftWriter::META_TRANSCRIPT_SAVED);
                }
            }
        }

        if (!$dryRun) {
            $this->pruneCounters($now);
        }
        return $stats;
    }

    public function retentionDays(): int
    {
        $days = Settings::int('retention_days');
        if (function_exists('apply_filters')) {
            $f = apply_filters('yt_to_post_retention_days', $days);
            if (is_numeric($f)) {
                $days = (int) $f;
            }
        }
        return max(1, min(90, $days));
    }

    /** Counters older than seven days go. */
    public function pruneCounters(int $now): void
    {
        for ($d = self::COUNTER_DAYS_KEPT + 1; $d <= self::COUNTER_DAYS_KEPT + 40; $d++) {
            $t = $now - $d * 86400;
            $site = Days::siteKey($t);
            $pacific = Days::pacificKey($t);
            delete_option(DailyCaps::VIDEOS_PREFIX . $site);
            delete_option(DailyCaps::CALLS_PREFIX . $site);
            delete_option(QuotaMeter::PREFIX . $pacific);
        }
    }
}
