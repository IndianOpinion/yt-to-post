<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Video;

/**
 * A parsed video or playlist reference. The id is the canonical identity.
 */
final class VideoRef
{
    public function __construct(
        public readonly ?string $videoId,
        public readonly ?int $startSeconds = null,
        public readonly ?string $playlistId = null
    ) {
    }

    public function isPlaylistOnly(): bool
    {
        return $this->videoId === null && $this->playlistId !== null;
    }

    public function watchUrl(): string
    {
        return 'https://www.youtube.com/watch?v=' . (string) $this->videoId;
    }
}
