<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript;

/**
 * Small time helpers shared by parsers and the block builder.
 */
final class Time
{
    /** `m:ss` under an hour, `h:mm:ss` from an hour. */
    public static function clock(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /**
     * Parses `hh:mm:ss.mmm`, `mm:ss.mmm`, `h:mm:ss,mmm` and similar.
     */
    public static function parseClock(string $value): ?float
    {
        if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})(?:[.,](\d{1,3}))?$/', trim($value), $m) !== 1) {
            return null;
        }
        if ((int) $m[3] > 59 || (substr_count($value, ":") === 2 && (int) $m[2] > 59)) {
            return null;
        }
        $frac = isset($m[4]) ? (float) ('0.' . $m[4]) : 0.0;
        return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3] + $frac;
    }
}
