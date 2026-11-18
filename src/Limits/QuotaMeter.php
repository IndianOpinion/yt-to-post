<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Limits;

use IndianOpinion\YtToPost\Exception\QuotaException;
use IndianOpinion\YtToPost\Settings;
use IndianOpinion\YtToPost\Support\Clock;
use IndianOpinion\YtToPost\Support\Days;
use IndianOpinion\YtToPost\Support\Options;

/**
 * Counts YouTube quota units per Pacific day (PRD case 71) and refuses a
 * call that would go over the budget.
 */
final class QuotaMeter
{
    public const PREFIX = 'yt_to_post_quota_';
    public const EXHAUSTED_OPTION = 'yt_to_post_quota_exhausted';
    public const COSTS = [
        'videos.list' => 1,
        'channels.list' => 1,
        'playlistItems.list' => 1,
        'captions.list' => 50,
        'captions.download' => 200,
    ];

    public function __construct(private readonly Clock $clock)
    {
    }

    public function budget(): int
    {
        return Settings::int('youtube_quota_budget', true);
    }

    public function used(): int
    {
        return Options::freshInt($this->key());
    }

    public function key(): string
    {
        return self::PREFIX . Days::pacificKey($this->clock->now());
    }

    public function heldUntil(): int
    {
        return Days::nextPacificMidnight($this->clock->now());
    }

    /**
     * @throws QuotaException
     */
    public function reserve(string $method): int
    {
        $units = self::COSTS[$method] ?? 1;
        $used = $this->used();
        if ($used + $units > $this->budget()) {
            throw new QuotaException(sprintf(
                'YouTube quota budget reached for today (%d of %d units used); jobs wait until midnight Pacific Time.',
                $used,
                $this->budget()
            ));
        }
        update_option($this->key(), $used + $units, false);
        return $units;
    }

    /** YouTube said the quota is gone: treat the day as spent. */
    public function exhaust(): void
    {
        update_option($this->key(), max($this->used(), $this->budget()), false);
        update_option(self::EXHAUSTED_OPTION, Days::pacificKey($this->clock->now()), false);
    }

    public function exhaustedToday(): bool
    {
        return Options::fresh(self::EXHAUSTED_OPTION, '') === Days::pacificKey($this->clock->now());
    }
}
