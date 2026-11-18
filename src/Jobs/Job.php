<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

use IndianOpinion\YtToPost\Transcript\Transcript;
use IndianOpinion\YtToPost\Video\Chapter;
use IndianOpinion\YtToPost\Video\VideoMeta;

/**
 * A job record. Read-only: changes go through with() and the repository.
 */
final class Job
{
    public const LOG_LINES = 50;

    public readonly int $id;
    public readonly string $videoId;
    public readonly string $state;
    public readonly string $step;
    public readonly int $userId;
    public readonly string $sourceKind;
    public readonly string $transcriptLanguage;
    public readonly string $articleLanguage;
    public readonly int $postId;
    public readonly int $attempts;
    public readonly int $modelCalls;
    public readonly int $quotaUnits;
    /** @var list<string> */
    public readonly array $warnings;
    public readonly string $lastError;
    public readonly int $createdAt;
    public readonly int $finishedAt;
    /** @var array<string,mixed> request arguments and flags */
    public readonly array $args;
    /** @var array<string,mixed>|null */
    public readonly ?array $metadata;
    public readonly int $metadataAt;
    /** @var list<array{start:int,title:string}>|null */
    public readonly ?array $chapters;
    /** Raw transcript or caption text waiting to be normalised. */
    public readonly ?string $source;
    /** Encoded normalised transcript. */
    public readonly ?string $transcript;
    /** @var array<int,array<string,mixed>> map notes by chunk index, plus reduce levels */
    public readonly array $notes;
    /** @var array<string,mixed>|null validated article waiting for the draft step */
    public readonly ?array $article;
    /** @var list<string> */
    public readonly array $log;
    public readonly int $heldUntil;
    public readonly int $updatedAt;

    /**
     * @param array<string,mixed> $d
     */
    public function __construct(array $d)
    {
        $this->id = (int) ($d['id'] ?? 0);
        $this->videoId = (string) ($d['videoId'] ?? '');
        $this->state = (string) ($d['state'] ?? JobState::QUEUED);
        $this->step = (string) ($d['step'] ?? JobState::QUEUED);
        $this->userId = (int) ($d['userId'] ?? 0);
        $this->sourceKind = (string) ($d['sourceKind'] ?? '');
        $this->transcriptLanguage = (string) ($d['transcriptLanguage'] ?? '');
        $this->articleLanguage = (string) ($d['articleLanguage'] ?? '');
        $this->postId = (int) ($d['postId'] ?? 0);
        $this->attempts = (int) ($d['attempts'] ?? 0);
        $this->modelCalls = (int) ($d['modelCalls'] ?? 0);
        $this->quotaUnits = (int) ($d['quotaUnits'] ?? 0);
        $this->warnings = self::strList($d['warnings'] ?? []);
        $this->lastError = (string) ($d['lastError'] ?? '');
        $this->createdAt = (int) ($d['createdAt'] ?? 0);
        $this->finishedAt = (int) ($d['finishedAt'] ?? 0);
        $this->args = is_array($d['args'] ?? null) ? $d['args'] : [];
        $this->metadata = is_array($d['metadata'] ?? null) ? $d['metadata'] : null;
        $this->metadataAt = (int) ($d['metadataAt'] ?? 0);
        /** @var list<array{start:int,title:string}>|null $chapters */
        $chapters = is_array($d['chapters'] ?? null) ? array_values($d['chapters']) : null;
        $this->chapters = $chapters;
        $this->source = isset($d['source']) && is_string($d['source']) ? $d['source'] : null;
        $this->transcript = isset($d['transcript']) && is_string($d['transcript']) ? $d['transcript'] : null;
        /** @var array<int,array<string,mixed>> $notes */
        $notes = is_array($d['notes'] ?? null) ? $d['notes'] : [];
        $this->notes = $notes;
        $this->article = is_array($d['article'] ?? null) ? $d['article'] : null;
        $this->log = array_slice(self::strList($d['log'] ?? []), -self::LOG_LINES);
        $this->heldUntil = (int) ($d['heldUntil'] ?? 0);
        $this->updatedAt = (int) ($d['updatedAt'] ?? 0);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param array<string,mixed> $changes
     */
    public function with(array $changes): self
    {
        return new self(array_merge($this->toArray(), $changes));
    }

    public function withLog(string $line, int $now): self
    {
        $log = $this->log;
        $log[] = gmdate('Y-m-d H:i:s', $now) . ' ' . $line;
        return $this->with(['log' => array_slice($log, -self::LOG_LINES)]);
    }

    public function withWarning(string $w): self
    {
        if (in_array($w, $this->warnings, true)) {
            return $this;
        }
        return $this->with(['warnings' => array_merge($this->warnings, [$w])]);
    }

    public function arg(string $key, mixed $default = null): mixed
    {
        return $this->args[$key] ?? $default;
    }

    public function meta(): ?VideoMeta
    {
        return $this->metadata === null ? null : VideoMeta::fromArray($this->metadata);
    }

    public function decodedTranscript(): ?Transcript
    {
        return $this->transcript === null ? null : Transcript::decode($this->transcript);
    }

    /** @return list<Chapter> */
    public function chapterList(): array
    {
        return array_map(static fn (array $c): Chapter => Chapter::fromArray($c), $this->chapters ?? []);
    }

    /**
     * Public view for REST and CLI. No transcript text, no secrets.
     *
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $meta = $this->metadata ?? [];
        return [
            'id' => $this->id,
            'video_id' => $this->videoId,
            'title' => (string) ($meta['title'] ?? ''),
            'state' => $this->state,
            'step' => $this->step,
            'user_id' => $this->userId,
            'post_id' => $this->postId,
            'source_kind' => $this->sourceKind,
            'transcript_language' => $this->transcriptLanguage,
            'article_language' => $this->articleLanguage,
            'attempts' => $this->attempts,
            'model_calls' => $this->modelCalls,
            'quota_units' => $this->quotaUnits,
            'warnings' => $this->warnings,
            'last_error' => $this->lastError,
            'created_at' => $this->createdAt,
            'finished_at' => $this->finishedAt,
            'held_until' => $this->heldUntil,
        ];
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
