<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Video;

/**
 * ISO 8601 durations from contentDetails.duration.
 */
final class DurationParser
{
    /**
     * Seconds, or null when the value is unknown, zero or unparsable.
     */
    public static function seconds(string $iso): ?int
    {
        $re = '/^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/';
        if ($iso === 'P' || $iso === 'PT' || preg_match($re, $iso, $m) !== 1) {
            return null;
        }
        $total = ((int) ($m[1] ?? 0)) * 604800
            + ((int) ($m[2] ?? 0)) * 86400
            + ((int) ($m[3] ?? 0)) * 3600
            + ((int) ($m[4] ?? 0)) * 60
            + (int) floor((float) ($m[5] ?? 0));
        return $total > 0 ? $total : null;
    }
}
