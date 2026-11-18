<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

interface JobRepository
{
    /** Inserts and returns the job with its id. */
    public function insert(Job $job): Job;

    public function save(Job $job): void;

    public function find(int $id): ?Job;

    /** Newest job for a video id. */
    public function latestForVideo(string $videoId): ?Job;

    /**
     * Jobs the runner may work on now, oldest first.
     *
     * @return list<Job>
     */
    public function runnable(int $now, bool $enabled, int $limit = 20): array;

    /**
     * @param array{state?:string,user_id?:int,video_id?:string} $filters
     * @return list<Job>
     */
    public function list(array $filters = [], int $limit = 20, int $offset = 0): array;

    /**
     * @param array{state?:string,user_id?:int} $filters
     */
    public function count(array $filters = []): int;

    /**
     * Ended jobs (done, failed, cancelled) whose finish time is before $before.
     *
     * @return list<Job>
     */
    public function endedBefore(int $before, int $limit = 200): array;

    /**
     * Jobs holding metadata fetched before $before.
     *
     * @return list<Job>
     */
    public function metadataBefore(int $before, int $limit = 200): array;

    public function delete(int $id): void;

    /** The earliest held_until in the future among held jobs, or null. */
    public function nextWake(int $now): ?int;
}
