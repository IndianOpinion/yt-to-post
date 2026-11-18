<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Admin;

use IndianOpinion\YtToPost\Capabilities;
use IndianOpinion\YtToPost\Jobs\Pipeline;
use IndianOpinion\YtToPost\Jobs\Runner;
use IndianOpinion\YtToPost\Limits\KillSwitch;
use IndianOpinion\YtToPost\Services;
use IndianOpinion\YtToPost\Support\Options;
use IndianOpinion\YtToPost\YouTube\Connection;

/**
 * Admin notices for the settings capability only.
 */
final class Notices
{
    public function __construct(private readonly Services $s)
    {
    }

    /** @return list<string> */
    public function collect(): array
    {
        if (!Capabilities::canManage()) {
            return [];
        }
        $now = $this->s->clock->now();
        $out = [];
        if (Connection::state() === Connection::NEEDS_RECONNECT) {
            $out[] = 'yt-to-post: the YouTube account needs reconnecting. Jobs that need YouTube wait until then.';
        }
        if ($this->s->quota()->exhaustedToday()) {
            $out[] = 'yt-to-post: the YouTube quota is used up for today. Jobs resume after midnight Pacific Time.';
        }
        if (Options::freshInt(Pipeline::ACCOUNT_HOLD_OPTION) > $now) {
            $out[] = 'yt-to-post: the model provider account is out of credit. Jobs are held for an hour at a time.';
        }
        $lastTick = Options::freshInt(Runner::LAST_TICK_OPTION);
        if ($lastTick < $now - 3600 && $this->s->jobs->runnable($now, KillSwitch::isEnabled(), 1) !== []) {
            $out[] = 'yt-to-post: jobs are waiting but the runner has not ticked for over an hour. Check WP-Cron or the real cron line for wp yt-to-post run.';
        }
        return $out;
    }

    public function render(): void
    {
        foreach ($this->collect() as $m) {
            echo '<div class="notice notice-warning"><p>' . esc_html($m) . '</p></div>';
        }
    }
}
