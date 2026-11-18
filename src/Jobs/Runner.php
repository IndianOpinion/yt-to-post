<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

use IndianOpinion\YtToPost\Limits\KillSwitch;
use IndianOpinion\YtToPost\Services;
use IndianOpinion\YtToPost\Settings;

/**
 * Works through runnable jobs (PRD case 81). Stops between units of work,
 * never starts a unit it cannot finish in the time left (using the step's
 * last measured length), holds one site lock, and never lets one job's
 * failure stop the others.
 */
final class Runner
{
    public const LAST_TICK_OPTION = 'yt_to_post_last_tick';
    public const STEP_TIMES_OPTION = 'yt_to_post_step_times';

    private Pipeline $pipeline;

    public function __construct(private readonly Services $s)
    {
        $this->pipeline = new Pipeline($s);
    }

    public function pipeline(): Pipeline
    {
        return $this->pipeline;
    }

    /**
     * @param array{time?:float|int|null,max?:int|null,job?:int|null} $opts
     */
    public function run(array $opts = []): RunSummary
    {
        $clock = $this->s->clock;
        $start = $clock->micro();
        $seconds = isset($opts['time']) ? (float) $opts['time'] : null;
        $max = isset($opts['max']) ? (int) $opts['max'] : null;
        $only = isset($opts['job']) ? (int) $opts['job'] : null;
        $deadline = $seconds === null ? null : $start + $seconds;
        $ttl = (int) max(60, ($seconds ?? 600) + 60);
        if (!SiteLock::acquire($clock->now(), $ttl)) {
            return new RunSummary(0, 0, 0, 0, 0.0, 'locked');
        }
        update_option(self::LAST_TICK_OPTION, $clock->now(), false);
        $claimed = $done = $held = $failed = 0;
        $stop = 'idle';
        $seen = [];
        try {
            while (true) {
                if ($max !== null && $claimed >= $max) {
                    $stop = 'max';
                    break;
                }
                $job = $this->next($seen, $only);
                if ($job === null) {
                    $stop = 'idle';
                    break;
                }
                $seen[$job->id] = true;
                if (!SiteLock::video($job->videoId, $job->id)) {
                    continue;
                }
                $claimed++;
                $outOfTime = false;
                while (true) {
                    $estimate = $this->stepEstimate($this->stepOf($job));
                    if ($deadline !== null && $clock->micro() + $estimate > $deadline) {
                        $outOfTime = true;
                        break;
                    }
                    SiteLock::refresh($clock->now(), $ttl);
                    SiteLock::video($job->videoId, $job->id);
                    $t0 = $clock->micro();
                    $step = $this->stepOf($job);
                    try {
                        $job = $this->pipeline->advance($job, $deadline);
                    } catch (\Throwable $e) {
                        $job = $this->recordCrash($job, $e);
                    }
                    $this->measure($step, $clock->micro() - $t0);
                    if (!JobState::isActive($job->state) || $job->heldUntil > $clock->now()) {
                        break;
                    }
                }
                if ($job->state === JobState::DONE) {
                    $done++;
                } elseif ($job->state === JobState::FAILED) {
                    $failed++;
                } elseif (!JobState::isActive($job->state) || $job->heldUntil > $clock->now()) {
                    $held++;
                }
                if (JobState::isTerminal($job->state)) {
                    SiteLock::releaseVideo($job->videoId);
                }
                if ($outOfTime) {
                    $stop = 'time';
                    break;
                }
            }
        } finally {
            SiteLock::release();
        }
        if ($only === null) {
            $this->reschedule();
        }
        return new RunSummary($claimed, $done, $held, $failed, round($clock->micro() - $start, 3), $stop);
    }

    /**
     * @param array<int,bool> $seen
     */
    private function next(array $seen, ?int $only): ?Job
    {
        $now = $this->s->clock->now();
        $enabled = KillSwitch::isEnabled();
        if ($only !== null) {
            if (isset($seen[$only])) {
                return null;
            }
            $job = $this->s->jobs->find($only);
            return $job !== null && RunnablePolicy::isRunnable($job, $now, $enabled) ? $job : null;
        }
        foreach ($this->s->jobs->runnable($now, $enabled, 50) as $job) {
            if (!isset($seen[$job->id])) {
                return $job;
            }
        }
        return null;
    }

    private function stepOf(Job $job): string
    {
        return JobState::isActive($job->state) && $job->state !== JobState::QUEUED ? $job->state : ($job->step !== '' ? $job->step : JobState::METADATA);
    }

    private function stepEstimate(string $step): float
    {
        $times = get_option(self::STEP_TIMES_OPTION, []);
        return is_array($times) && isset($times[$step]) && is_numeric($times[$step]) ? (float) $times[$step] : 0.0;
    }

    private function measure(string $step, float $seconds): void
    {
        $times = get_option(self::STEP_TIMES_OPTION, []);
        $times = is_array($times) ? $times : [];
        $times[$step] = round($seconds, 3);
        update_option(self::STEP_TIMES_OPTION, $times, false);
    }

    private function recordCrash(Job $job, \Throwable $e): Job
    {
        $attempts = $job->attempts + 1;
        $failed = $attempts >= Settings::int('max_job_attempts');
        $now = $this->s->clock->now();
        $updated = $job->with([
            'attempts' => $attempts,
            'lastError' => get_class($e) . ': ' . $e->getMessage(),
            'state' => $failed ? JobState::FAILED : $job->state,
            'heldUntil' => $failed ? 0 : $now + 60 * $attempts,
            'finishedAt' => $failed ? $now : 0,
            'updatedAt' => $now,
        ])->withLog('runner caught ' . get_class($e), $now);
        try {
            JobEvents::save($this->s->jobs, $updated, $job->state);
        } catch (\Throwable) {
            // The store itself failed; the job keeps its last saved state.
        }
        if ($failed && function_exists('do_action')) {
            do_action('yt_to_post_job_failed', $updated, $e);
        }
        return $updated;
    }

    /** Schedules the plugin's own runner while work remains or a hold ends later. */
    public function reschedule(): void
    {
        if (defined('YT_TO_POST_DISABLE_CRON_RUNNER') && constant('YT_TO_POST_DISABLE_CRON_RUNNER')) {
            return;
        }
        $now = $this->s->clock->now();
        if ($this->s->jobs->runnable($now, KillSwitch::isEnabled(), 1) !== []) {
            Scheduler::kick($now + 5);
            return;
        }
        $wake = $this->s->jobs->nextWake($now);
        if ($wake !== null) {
            Scheduler::kick($wake);
        }
    }
}
