<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Article;

/**
 * A validated article. Text fields are plain text, not yet escaped.
 */
final class Article
{
    /**
     * @param list<array{heading:string,paragraphs:list<string>,start_seconds:?int}> $sections
     * @param list<string> $keyPoints
     * @param list<array{text:string,translation:?string,speaker:?string,start_seconds:?int}> $quotes
     * @param list<string> $topics
     * @param array<string,mixed> $extra fields added through the schema filter
     */
    public function __construct(
        public readonly string $language,
        public readonly string $sourceLanguage,
        public readonly string $title,
        public readonly string $standfirst,
        public readonly array $sections,
        public readonly array $keyPoints,
        public readonly array $quotes,
        public readonly array $topics,
        public readonly int $droppedQuotes = 0,
        public readonly array $extra = []
    ) {
    }

    /**
     * @param array<string,mixed> $changes
     */
    public function with(array $changes): self
    {
        $v = array_merge($this->toArray(), $changes);
        return self::fromArray($v);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'language' => $this->language,
            'source_language' => $this->sourceLanguage,
            'title' => $this->title,
            'standfirst' => $this->standfirst,
            'sections' => $this->sections,
            'key_points' => $this->keyPoints,
            'quotes' => $this->quotes,
            'topics' => $this->topics,
            'dropped_quotes' => $this->droppedQuotes,
            'extra' => $this->extra,
        ];
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        /** @var list<array{heading:string,paragraphs:list<string>,start_seconds:?int}> $sections */
        $sections = is_array($a['sections'] ?? null) ? array_values($a['sections']) : [];
        /** @var list<string> $keyPoints */
        $keyPoints = is_array($a['key_points'] ?? null) ? array_values($a['key_points']) : [];
        /** @var list<array{text:string,translation:?string,speaker:?string,start_seconds:?int}> $quotes */
        $quotes = is_array($a['quotes'] ?? null) ? array_values($a['quotes']) : [];
        /** @var list<string> $topics */
        $topics = is_array($a['topics'] ?? null) ? array_values($a['topics']) : [];
        /** @var array<string,mixed> $extra */
        $extra = is_array($a['extra'] ?? null) ? $a['extra'] : [];
        return new self(
            (string) ($a['language'] ?? ''),
            (string) ($a['source_language'] ?? ''),
            (string) ($a['title'] ?? ''),
            (string) ($a['standfirst'] ?? ''),
            $sections,
            $keyPoints,
            $quotes,
            $topics,
            (int) ($a['dropped_quotes'] ?? 0),
            $extra
        );
    }
}
