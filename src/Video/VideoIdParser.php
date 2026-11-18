<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Video;

/**
 * Turns a YouTube link or bare id into a VideoRef. Pure: no network.
 *
 * Only exact hosts are accepted, so look-alike hosts such as
 * youtube.com.example.org fail.
 */
final class VideoIdParser
{
    public const MAX_BYTES = 2048;
    private const ID = '/^[A-Za-z0-9_-]{11}$/';
    private const PLAYLIST_ID = '/^[A-Za-z0-9_-]{2,64}$/';
    private const WATCH_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'];
    private const NOCOOKIE_HOSTS = ['youtube-nocookie.com', 'www.youtube-nocookie.com'];
    private const SHORT_HOSTS = ['youtu.be', 'www.youtu.be'];

    /**
     * Parses a reference. Returns null for anything that is not a video or a
     * playlist link.
     */
    public static function parse(string $input): ?VideoRef
    {
        if (strlen($input) > self::MAX_BYTES) {
            return null;
        }
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        if (preg_match(self::ID, $input) === 1) {
            return new VideoRef($input);
        }
        if (preg_match('#^https?://#i', $input) !== 1) {
            return null;
        }
        $parts = parse_url($input);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';
        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $start = self::timeFrom($query, $parts['fragment'] ?? '');
        $list = isset($query['list']) && is_string($query['list']) && preg_match(self::PLAYLIST_ID, $query['list']) === 1
            ? $query['list'] : null;

        if (in_array($host, self::SHORT_HOSTS, true)) {
            $id = self::firstSegment($path);
            return self::isId($id) ? new VideoRef($id, $start, $list) : null;
        }
        if (in_array($host, self::NOCOOKIE_HOSTS, true)) {
            if (preg_match('#^/embed/([^/]+)/?$#', $path, $m) === 1 && self::isId($m[1])) {
                return new VideoRef($m[1], $start, $list);
            }
            return null;
        }
        if (!in_array($host, self::WATCH_HOSTS, true)) {
            return null;
        }
        if (rtrim($path, '/') === '/watch') {
            $v = $query['v'] ?? null;
            return is_string($v) && self::isId($v) ? new VideoRef($v, $start, $list) : null;
        }
        if (preg_match('#^/(shorts|live|embed|v)/([^/]+)/?$#', $path, $m) === 1) {
            return self::isId($m[2]) ? new VideoRef($m[2], $start, $list) : null;
        }
        if (rtrim($path, '/') === '/playlist' && $list !== null) {
            return new VideoRef(null, null, $list);
        }
        return null;
    }

    /** The video id, or null. Playlist-only links give null. */
    public static function videoId(string $input): ?string
    {
        $ref = self::parse($input);
        return $ref === null ? null : $ref->videoId;
    }

    private static function isId(?string $id): bool
    {
        return $id !== null && preg_match(self::ID, $id) === 1;
    }

    private static function firstSegment(string $path): ?string
    {
        $seg = explode('/', ltrim($path, '/'))[0];
        return $seg === '' ? null : $seg;
    }

    /**
     * @param array<mixed> $query
     */
    private static function timeFrom(array $query, string $fragment): ?int
    {
        foreach (['t', 'start'] as $key) {
            if (isset($query[$key]) && is_string($query[$key])) {
                $s = self::seconds($query[$key]);
                if ($s !== null) {
                    return $s;
                }
            }
        }
        if (preg_match('/(?:^|&)t=([^&]+)/', $fragment, $m) === 1) {
            return self::seconds($m[1]);
        }
        return null;
    }

    /** Accepts `90`, `90s`, `1m30s`, `1h2m3s`. */
    public static function seconds(string $value): ?int
    {
        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $value, $m) === 1 && $value !== '') {
            return ((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + (int) ($m[3] ?? 0);
        }
        return null;
    }
}
