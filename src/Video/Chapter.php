<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Video;

final class Chapter
{
    public function __construct(
        public readonly int $start,
        public readonly string $title
    ) {
    }

    /** @return array{start:int,title:string} */
    public function toArray(): array
    {
        return ['start' => $this->start, 'title' => $this->title];
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self((int) ($a['start'] ?? 0), (string) ($a['title'] ?? ''));
    }
}
