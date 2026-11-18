<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Article;

/**
 * What a prompt needs to know about the job.
 */
final class PromptContext
{
    /**
     * @param list<string> $chapterTitles
     */
    public function __construct(
        public readonly string $title,
        public readonly string $channel,
        public readonly string $description,
        public readonly array $chapterTitles,
        public readonly string $articleLanguage,
        public readonly string $transcriptLanguage,
        public readonly bool $asr,
        public readonly bool $hasTimes,
        public readonly ?int $duration
    ) {
    }

    public function translating(): bool
    {
        return $this->transcriptLanguage !== ''
            && $this->articleLanguage !== ''
            && \IndianOpinion\YtToPost\Language::primary($this->transcriptLanguage)
                !== \IndianOpinion\YtToPost\Language::primary($this->articleLanguage);
    }
}
