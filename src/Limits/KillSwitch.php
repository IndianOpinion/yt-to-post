<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Limits;

use IndianOpinion\YtToPost\Settings;

/**
 * PRD case 69. The constant YT_TO_POST_DISABLED always wins. Read fresh.
 */
final class KillSwitch
{
    public static function isEnabled(): bool
    {
        if (defined('YT_TO_POST_DISABLED') && constant('YT_TO_POST_DISABLED')) {
            return false;
        }
        return (bool) (Settings::all(true)['enabled'] ?? true);
    }

    public static function lockedByConstant(): bool
    {
        return defined('YT_TO_POST_DISABLED') && (bool) constant('YT_TO_POST_DISABLED');
    }

    /** Returns false when the constant prevents turning it on. */
    public static function set(bool $on): bool
    {
        if ($on && self::lockedByConstant()) {
            return false;
        }
        $all = Settings::all(true);
        $all['enabled'] = $on;
        Settings::save($all);
        return true;
    }
}
