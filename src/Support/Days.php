<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Support;

/**
 * Day keys. Caps count on the site's day; YouTube quota counts on the
 * Pacific day, because that is when YouTube resets it.
 */
final class Days
{
    public const PACIFIC = 'America/Los_Angeles';

    public static function siteTimezone(): \DateTimeZone
    {
        return function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
    }

    public static function key(int $now, \DateTimeZone $tz): string
    {
        return (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->format('Ymd');
    }

    public static function nextMidnight(int $now, \DateTimeZone $tz): int
    {
        return (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0)->modify('+1 day')->getTimestamp();
    }

    public static function siteKey(int $now): string
    {
        return self::key($now, self::siteTimezone());
    }

    public static function pacificKey(int $now): string
    {
        return self::key($now, new \DateTimeZone(self::PACIFIC));
    }

    public static function nextSiteMidnight(int $now): int
    {
        return self::nextMidnight($now, self::siteTimezone());
    }

    public static function nextPacificMidnight(int $now): int
    {
        return self::nextMidnight($now, new \DateTimeZone(self::PACIFIC));
    }
}
