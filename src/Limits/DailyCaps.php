<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Limits;

use IndianOpinion\YtToPost\Settings;
use IndianOpinion\YtToPost\Support\Clock;
use IndianOpinion\YtToPost\Support\Days;
use IndianOpinion\YtToPost\Support\Options;

/**
 * Daily caps per site day (PRD case 70): videos started and model attempts.
 * Read fresh before every paid call. The filter can lower a cap, never
 * raise it.
 */
final class DailyCaps
{
    public const VIDEOS_PREFIX = 'yt_to_post_videos_';
    public const CALLS_PREFIX = 'yt_to_post_calls_';

    public function __construct(private readonly Clock $clock)
    {
    }

    /** @return array{videos:int,calls:int} */
    public function limits(): array
    {
        $stored = [
            'videos' => Settings::int('max_videos_per_day', true),
            'calls' => Settings::int('max_model_calls_per_day', true),
        ];
        $filtered = function_exists('apply_filters') ? apply_filters('yt_to_post_daily_caps', $stored) : $stored;
        $out = $stored;
        if (is_array($filtered)) {
            foreach (['videos', 'calls'] as $k) {
                if (isset($filtered[$k]) && is_numeric($filtered[$k])) {
                    $out[$k] = max(0, min($stored[$k], (int) $filtered[$k]));
                }
            }
        }
        return $out;
    }

    public function videosUsed(): int
    {
        return Options::freshInt(self::VIDEOS_PREFIX . $this->day());
    }

    public function callsUsed(): int
    {
        return Options::freshInt(self::CALLS_PREFIX . $this->day());
    }

    public function canStartVideo(): bool
    {
        return $this->videosUsed() < $this->limits()['videos'];
    }

    public function canCall(): bool
    {
        return $this->callsUsed() < $this->limits()['calls'];
    }

    public function countVideo(): void
    {
        update_option(self::VIDEOS_PREFIX . $this->day(), $this->videosUsed() + 1, false);
    }

    public function countCalls(int $n): void
    {
        if ($n > 0) {
            update_option(self::CALLS_PREFIX . $this->day(), $this->callsUsed() + $n, false);
        }
    }

    public function heldUntil(): int
    {
        return Days::nextSiteMidnight($this->clock->now());
    }

    private function day(): string
    {
        return Days::siteKey($this->clock->now());
    }
}
