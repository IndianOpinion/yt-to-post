<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Video;

/**
 * Reads chapter lines from a description or a sidecar text and applies
 * YouTube's published chapter rule. An invalid list is ignored whole.
 */
final class ChapterParser
{
    public const MAX_TITLE = 120;
    public const MIN_LENGTH = 10;
    public const MIN_COUNT = 3;

    private const LINE = '/^[\s\x{2022}\x{00B7}\x{2013}*\-\[\(>]*'
        . '((?:\d{1,2}:)?\d{1,2}:\d{2})(?![\d:])[\]\)]?(.*)$/u';

    public static function parse(string $text, ?int $durationSeconds = null): ChapterList
    {
        $found = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match(self::LINE, $line, $m) !== 1) {
                continue;
            }
            $found[] = [self::toSeconds($m[1]), self::cleanTitle($m[2])];
        }
        if ($found === []) {
            return new ChapterList([], null);
        }
        if (count($found) < self::MIN_COUNT) {
            return new ChapterList([], 'fewer than three chapters');
        }
        if ($found[0][0] !== 0) {
            return new ChapterList([], 'first chapter does not start at 0:00');
        }
        $chapters = [];
        $prev = null;
        foreach ($found as [$start, $title]) {
            if ($title === '') {
                return new ChapterList([], 'a chapter has no title');
            }
            if ($prev !== null && $start <= $prev) {
                return new ChapterList([], 'chapter times do not increase');
            }
            if ($prev !== null && $start - $prev < self::MIN_LENGTH) {
                return new ChapterList([], 'a chapter is shorter than 10 seconds');
            }
            if ($durationSeconds !== null && $start >= $durationSeconds) {
                return new ChapterList([], 'a chapter starts after the end of the video');
            }
            $chapters[] = new Chapter($start, $title);
            $prev = $start;
        }
        if ($durationSeconds !== null && $durationSeconds - $prev < self::MIN_LENGTH) {
            return new ChapterList([], 'a chapter is shorter than 10 seconds');
        }
        return new ChapterList($chapters, null);
    }

    public static function toSeconds(string $stamp): int
    {
        $parts = array_map('intval', explode(':', $stamp));
        $s = 0;
        foreach ($parts as $p) {
            $s = $s * 60 + $p;
        }
        return $s;
    }

    private static function cleanTitle(string $raw): string
    {
        $t = trim($raw);
        $t = (string) preg_replace('/^[\s\-|:.\x{2013}]+/u', '', $t);
        $t = trim($t);
        if (mb_strlen($t) > self::MAX_TITLE) {
            $t = rtrim(mb_substr($t, 0, self::MAX_TITLE));
        }
        return $t;
    }
}
