<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Http;

use IndianOpinion\YtToPost\Exception\NetworkException;
use IndianOpinion\YtToPost\Exception\YouTubeApiException;
use IndianOpinion\YtToPost\Support\Sleeper;

/**
 * PRD case 77: network errors, timeouts, 429, 5xx, rateLimitExceeded,
 * userRateLimitExceeded and backendError are retried with exponential
 * backoff (2, 4, 8 seconds base, plus up to 25 per cent jitter), honouring
 * Retry-After up to 60 seconds. Three retries after the first request.
 */
final class Retrier
{
    public const BASES = [2, 4, 8];
    public const MAX_RETRY_AFTER = 60;

    /** @var \Closure():float */
    private \Closure $jitter;

    /**
     * @param (callable():float)|null $jitter returns 0..1
     */
    public function __construct(private readonly Sleeper $sleeper, ?callable $jitter = null)
    {
        $this->jitter = $jitter === null
            ? static fn (): float => random_int(0, 1000) / 1000
            : \Closure::fromCallable($jitter);
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function run(callable $fn): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $fn();
            } catch (NetworkException | YouTubeApiException $e) {
                $retryable = $e instanceof NetworkException || $e->isRetryable();
                if (!$retryable || $attempt >= count(self::BASES)) {
                    throw $e;
                }
                $this->sleeper->sleep($this->delay($attempt, $e instanceof YouTubeApiException ? $e->retryAfter() : null));
                $attempt++;
            }
        }
    }

    public function delay(int $attempt, ?int $retryAfter): float
    {
        if ($retryAfter !== null && $retryAfter >= 0) {
            return (float) min($retryAfter, self::MAX_RETRY_AFTER);
        }
        $base = self::BASES[min($attempt, count(self::BASES) - 1)];
        return $base * (1 + 0.25 * ($this->jitter)());
    }
}
