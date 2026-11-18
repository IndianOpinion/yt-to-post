<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Video;

/**
 * The only video fields the plugin keeps (PRD case 16, plus the thumbnail
 * URL for the suggested featured image). Everything else in an API response
 * is dropped on the floor.
 */
final class VideoMeta
{
    public const SOURCE_API = 'api';
    public const SOURCE_OEMBED = 'oembed';
    /** oEmbed text is unverified input: keep it short. */
    public const OEMBED_TEXT_MAX = 200;

    /**
     * @param list<string>|null $regionAllowed
     * @param list<string>|null $regionBlocked
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $channelId,
        public readonly string $channelTitle,
        public readonly string $publishedAt,
        public readonly string $description,
        public readonly string $defaultLanguage,
        public readonly string $defaultAudioLanguage,
        public readonly ?int $duration,
        public readonly string $privacyStatus,
        public readonly string $uploadStatus,
        public readonly bool $embeddable,
        public readonly bool $caption,
        public readonly string $liveBroadcastContent,
        public readonly bool $ageRestricted,
        public readonly bool $madeForKids,
        public readonly ?array $regionAllowed,
        public readonly ?array $regionBlocked,
        public readonly string $thumbnail,
        public readonly string $source = self::SOURCE_API,
        public readonly string $channelUrl = ''
    ) {
    }

    /**
     * Builds from one `items[]` entry of videos.list.
     *
     * @param array<string,mixed> $item
     */
    public static function fromApiItem(array $item): self
    {
        $sn = self::arr($item['snippet'] ?? null);
        $cd = self::arr($item['contentDetails'] ?? null);
        $st = self::arr($item['status'] ?? null);
        $rating = self::arr($cd['contentRating'] ?? null);
        $region = self::arr($cd['regionRestriction'] ?? null);
        return new self(
            (string) ($item['id'] ?? ''),
            self::str($sn['title'] ?? ''),
            self::str($sn['channelId'] ?? ''),
            self::str($sn['channelTitle'] ?? ''),
            self::str($sn['publishedAt'] ?? ''),
            self::str($sn['description'] ?? ''),
            self::str($sn['defaultLanguage'] ?? ''),
            self::str($sn['defaultAudioLanguage'] ?? ''),
            DurationParser::seconds(self::str($cd['duration'] ?? '')),
            self::str($st['privacyStatus'] ?? ''),
            self::str($st['uploadStatus'] ?? ''),
            ($st['embeddable'] ?? true) !== false,
            in_array($cd['caption'] ?? 'false', ['true', true], true),
            self::str($sn['liveBroadcastContent'] ?? 'none'),
            ($rating['ytRating'] ?? '') === 'ytAgeRestricted',
            ($st['madeForKids'] ?? false) === true,
            isset($region['allowed']) ? self::strList($region['allowed']) : null,
            isset($region['blocked']) ? self::strList($region['blocked']) : null,
            self::bestThumbnail(self::arr($sn['thumbnails'] ?? null))
        );
    }

    /**
     * Builds from YouTube's public oEmbed answer (title and channel only).
     *
     * @param array<string,mixed> $o
     */
    public static function fromOEmbed(string $id, array $o): self
    {
        $author = self::str($o['author_url'] ?? '');
        $thumb = self::str($o['thumbnail_url'] ?? '');
        return new self(
            $id,
            mb_substr(self::str($o['title'] ?? ''), 0, self::OEMBED_TEXT_MAX),
            '',
            mb_substr(self::str($o['author_name'] ?? ''), 0, self::OEMBED_TEXT_MAX),
            '',
            '',
            '',
            '',
            null,
            'public',
            'processed',
            true,
            false,
            'none',
            false,
            false,
            null,
            null,
            self::isThumbnailUrl($thumb) ? $thumb : '',
            self::SOURCE_OEMBED,
            self::isChannelUrl($author) ? $author : ''
        );
    }

    public function channelLink(): string
    {
        if ($this->channelId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $this->channelId) === 1) {
            return 'https://www.youtube.com/channel/' . $this->channelId;
        }
        return $this->channelUrl;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['id'] ?? ''),
            (string) ($a['title'] ?? ''),
            (string) ($a['channelId'] ?? ''),
            (string) ($a['channelTitle'] ?? ''),
            (string) ($a['publishedAt'] ?? ''),
            (string) ($a['description'] ?? ''),
            (string) ($a['defaultLanguage'] ?? ''),
            (string) ($a['defaultAudioLanguage'] ?? ''),
            isset($a['duration']) ? (int) $a['duration'] : null,
            (string) ($a['privacyStatus'] ?? ''),
            (string) ($a['uploadStatus'] ?? ''),
            (bool) ($a['embeddable'] ?? true),
            (bool) ($a['caption'] ?? false),
            (string) ($a['liveBroadcastContent'] ?? 'none'),
            (bool) ($a['ageRestricted'] ?? false),
            (bool) ($a['madeForKids'] ?? false),
            isset($a['regionAllowed']) ? self::strList($a['regionAllowed']) : null,
            isset($a['regionBlocked']) ? self::strList($a['regionBlocked']) : null,
            (string) ($a['thumbnail'] ?? ''),
            (string) ($a['source'] ?? self::SOURCE_API),
            (string) ($a['channelUrl'] ?? '')
        );
    }

    public static function isThumbnailUrl(string $url): bool
    {
        return preg_match('#^https://i\.ytimg\.com/[A-Za-z0-9_/.\-]+$#', $url) === 1;
    }

    public static function isChannelUrl(string $url): bool
    {
        return preg_match('#^https://www\.youtube\.com/(?:@[\w.\-]+|channel/[A-Za-z0-9_-]+|c/[\w.\-]+|user/[\w.\-]+)$#u', $url) === 1;
    }

    /** @param array<string,mixed> $thumbs */
    private static function bestThumbnail(array $thumbs): string
    {
        foreach (['maxres', 'standard', 'high', 'medium', 'default'] as $k) {
            $t = self::arr($thumbs[$k] ?? null);
            $url = self::str($t['url'] ?? '');
            if (self::isThumbnailUrl($url)) {
                return $url;
            }
        }
        return '';
    }

    /** @return array<string,mixed> */
    private static function arr(mixed $v): array
    {
        return is_array($v) ? $v : [];
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    /** @return list<string> */
    private static function strList(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_map('strval', array_filter($v, 'is_scalar')));
    }
}
