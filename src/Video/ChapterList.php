<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Video;

/**
 * The result of reading chapters: a valid list, or none with a reason.
 */
final class ChapterList
{
    /**
     * @param list<Chapter> $chapters
     */
    public function __construct(
        public readonly array $chapters,
        public readonly ?string $ignoredReason = null
    ) {
    }

    public function isValid(): bool
    {
        return $this->chapters !== [];
    }
}
