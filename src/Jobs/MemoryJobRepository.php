<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

/**
 * In-memory repository for tests and examples.
 */
final class MemoryJobRepository implements JobRepository
{
    /** @var array<int,Job> */
    private array $jobs = [];
    private int $next = 1;

    public function insert(Job $job): Job
    {
        $job = $job->with(['id' => $this->next++]);
        $this->jobs[$job->id] = $job;
        return $job;
    }

    public function save(Job $job): void
    {
        $this->jobs[$job->id] = $job;
    }

    public function find(int $id): ?Job
    {
        return $this->jobs[$id] ?? null;
    }

    public function latestForVideo(string $videoId): ?Job
    {
        $found = null;
        foreach ($this->jobs as $j) {
            if ($j->videoId === $videoId && ($found === null || $j->id > $found->id)) {
                $found = $j;
            }
        }
        return $found;
    }

    public function runnable(int $now, bool $enabled, int $limit = 20): array
    {
        $out = [];
        foreach ($this->jobs as $j) {
            if (RunnablePolicy::isRunnable($j, $now, $enabled)) {
                $out[] = $j;
            }
        }
        usort($out, static fn (Job $a, Job $b): int => [$a->updatedAt, $a->id] <=> [$b->updatedAt, $b->id]);
        return array_slice($out, 0, $limit);
    }

    public function list(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $out = array_values(array_filter($this->jobs, static function (Job $j) use ($filters): bool {
            return (!isset($filters['state']) || $j->state === $filters['state'])
                && (!isset($filters['user_id']) || $j->userId === $filters['user_id'])
                && (!isset($filters['video_id']) || $j->videoId === $filters['video_id']);
        }));
        usort($out, static fn (Job $a, Job $b): int => $b->id <=> $a->id);
        return array_slice($out, $offset, $limit);
    }

    public function count(array $filters = []): int
    {
        return count($this->list($filters, PHP_INT_MAX));
    }

    public function endedBefore(int $before, int $limit = 200): array
    {
        $out = [];
        foreach ($this->jobs as $j) {
            if (JobState::isTerminal($j->state) && $j->finishedAt > 0 && $j->finishedAt < $before) {
                $out[] = $j;
            }
        }
        return array_slice($out, 0, $limit);
    }

    public function metadataBefore(int $before, int $limit = 200): array
    {
        $out = [];
        foreach ($this->jobs as $j) {
            if ($j->metadata !== null && $j->metadataAt > 0 && $j->metadataAt < $before) {
                $out[] = $j;
            }
        }
        return array_slice($out, 0, $limit);
    }

    public function delete(int $id): void
    {
        unset($this->jobs[$id]);
    }

    public function nextWake(int $now): ?int
    {
        $min = null;
        foreach ($this->jobs as $j) {
            if (in_array($j->state, JobState::HELD, true) && $j->heldUntil > $now) {
                $min = $min === null ? $j->heldUntil : min($min, $j->heldUntil);
            }
        }
        return $min;
    }
}
