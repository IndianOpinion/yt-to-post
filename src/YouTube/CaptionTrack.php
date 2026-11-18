<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\YouTube;

final class CaptionTrack
{
    public function __construct(
        public readonly string $id,
        public readonly string $language,
        public readonly string $trackKind,
        public readonly string $status,
        public readonly bool $isDraft,
        public readonly string $audioTrackType,
        public readonly string $lastUpdated,
        public readonly string $name = ''
    ) {
    }

    /** @param array<string,mixed> $item */
    public static function fromApiItem(array $item): self
    {
        $s = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
        $str = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
        return new self(
            $str($item['id'] ?? ''),
            $str($s['language'] ?? ''),
            $str($s['trackKind'] ?? 'standard'),
            $str($s['status'] ?? ''),
            ($s['isDraft'] ?? false) === true,
            $str($s['audioTrackType'] ?? 'unknown'),
            $str($s['lastUpdated'] ?? ''),
            $str($s['name'] ?? '')
        );
    }

    public function isAsr(): bool
    {
        return strcasecmp($this->trackKind, 'asr') === 0;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
