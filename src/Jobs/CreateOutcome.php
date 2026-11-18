<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

/**
 * What a create request did: a new job, or an existing job or post.
 */
final class CreateOutcome
{
    public function __construct(
        public readonly ?Job $job,
        public readonly bool $existing,
        public readonly string $message,
        public readonly int $postId = 0
    ) {
    }

    public function jobId(): int
    {
        return $this->job === null ? 0 : $this->job->id;
    }
}
