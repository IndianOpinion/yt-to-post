<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Exception;

/**
 * YouTube or Google answered with an error status.
 */
class YouTubeApiException extends YtToPostException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly string $reason = '',
        private readonly ?int $retryAfter = null
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** The first `errors[].reason` (or OAuth `error`) from the body, or an empty string. */
    public function reason(): string
    {
        return $this->reason;
    }

    /** Seconds from a Retry-After header, when one was sent. */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function isRetryable(): bool
    {
        if ($this->httpStatus === 429 || $this->httpStatus >= 500) {
            return true;
        }
        return in_array($this->reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'backendError'], true);
    }

    public function isQuotaExhausted(): bool
    {
        return in_array($this->reason, ['quotaExceeded', 'dailyLimitExceeded'], true);
    }
}
