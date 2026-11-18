<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Chunking;

/**
 * Estimated tokens: UTF-8 bytes divided by a ratio (default 3), rounded up.
 * It over-counts Latin text and is close for Indic, Arabic and CJK, so it
 * errs safe. The ratio can be set per model with the
 * `yt_to_post_bytes_per_token` filter, and the whole estimate replaced with
 * `yt_to_post_estimate_tokens`.
 */
final class TokenEstimator
{
    public const DEFAULT_BYTES_PER_TOKEN = 3.0;

    /** @var \Closure(string):int|null */
    private ?\Closure $override;

    /**
     * @param (callable(string):int)|null $override
     */
    public function __construct(
        private readonly float $bytesPerToken = self::DEFAULT_BYTES_PER_TOKEN,
        ?callable $override = null
    ) {
        $this->override = $override === null ? null : \Closure::fromCallable($override);
    }

    /**
     * Builds the estimator a site has configured for a model.
     */
    public static function forModel(string $model): self
    {
        $ratio = self::DEFAULT_BYTES_PER_TOKEN;
        if (function_exists('apply_filters')) {
            $r = apply_filters('yt_to_post_bytes_per_token', $ratio, $model);
            if (is_numeric($r) && (float) $r >= 0.5 && (float) $r <= 16.0) {
                $ratio = (float) $r;
            }
            return new self($ratio, static function (string $text) use ($ratio, $model): int {
                $default = (int) ceil(strlen($text) / $ratio);
                $v = apply_filters('yt_to_post_estimate_tokens', $default, $text, $model);
                return is_numeric($v) && (int) $v >= 0 ? (int) $v : $default;
            });
        }
        return new self($ratio);
    }

    public function estimate(string $text): int
    {
        if ($this->override !== null) {
            return ($this->override)($text);
        }
        return (int) ceil(strlen($text) / $this->bytesPerToken);
    }
}
