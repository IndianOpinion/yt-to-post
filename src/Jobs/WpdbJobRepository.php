<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

use IndianOpinion\YtToPost\Exception\YtToPostException;

/**
 * Jobs in the {prefix}yt_to_post_jobs table.
 */
final class WpdbJobRepository implements JobRepository
{
    private const JSON = ['args', 'metadata', 'chapters', 'notes', 'article', 'warnings', 'log'];
    private const MAP = [
        'id' => 'id', 'videoId' => 'video_id', 'state' => 'state', 'step' => 'step', 'userId' => 'user_id',
        'postId' => 'post_id', 'sourceKind' => 'source_kind', 'transcriptLanguage' => 'transcript_language',
        'articleLanguage' => 'article_language', 'attempts' => 'attempts', 'modelCalls' => 'model_calls',
        'quotaUnits' => 'quota_units', 'args' => 'args', 'metadata' => 'metadata', 'metadataAt' => 'metadata_at',
        'chapters' => 'chapters', 'source' => 'source', 'transcript' => 'transcript', 'notes' => 'notes',
        'article' => 'article', 'warnings' => 'warnings', 'log' => 'log', 'lastError' => 'last_error',
        'heldUntil' => 'held_until', 'createdAt' => 'created_at', 'updatedAt' => 'updated_at',
        'finishedAt' => 'finished_at',
    ];

    public function __construct(private readonly \wpdb $db)
    {
    }

    private function table(): string
    {
        return SchemaInstaller::table($this->db);
    }

    public function insert(Job $job): Job
    {
        $row = $this->toRow($job);
        unset($row['id']);
        if ($this->db->insert($this->table(), $row) === false) {
            throw new YtToPostException('Could not save the job: ' . $this->db->last_error);
        }
        return $job->with(['id' => (int) $this->db->insert_id]);
    }

    public function save(Job $job): void
    {
        $row = $this->toRow($job);
        unset($row['id']);
        if ($this->db->update($this->table(), $row, ['id' => $job->id]) === false) {
            throw new YtToPostException('Could not save the job: ' . $this->db->last_error);
        }
    }

    public function find(int $id): ?Job
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id), ARRAY_A);
        return is_array($row) ? $this->fromRow($row) : null;
    }

    public function latestForVideo(string $videoId): ?Job
    {
        $row = $this->db->get_row(
            $this->db->prepare("SELECT * FROM {$this->table()} WHERE video_id = %s ORDER BY id DESC LIMIT 1", $videoId),
            ARRAY_A
        );
        return is_array($row) ? $this->fromRow($row) : null;
    }

    public function runnable(int $now, bool $enabled, int $limit = 20): array
    {
        $active = "'" . implode("','", JobState::STEPS) . "'";
        $timed = "'" . implode("','", [JobState::HELD_CAP, JobState::HELD_QUOTA, JobState::HELD_ACCOUNT]) . "'";
        $sql = "SELECT * FROM {$this->table()} WHERE ((state IN ($active) OR state IN ($timed)) AND held_until <= %d)";
        if ($enabled) {
            $sql .= " OR state = '" . JobState::HELD_DISABLED . "'";
        }
        $sql .= ' ORDER BY updated_at ASC, id ASC LIMIT %d';
        return $this->rows($this->db->prepare($sql, $now, $limit));
    }

    public function list(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        [$where, $args] = $this->where($filters);
        $args[] = $limit;
        $args[] = $offset;
        return $this->rows($this->db->prepare(
            "SELECT * FROM {$this->table()} WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d",
            ...$args
        ));
    }

    public function count(array $filters = []): int
    {
        [$where, $args] = $this->where($filters);
        $sql = "SELECT COUNT(*) FROM {$this->table()} WHERE $where";
        return (int) $this->db->get_var($args === [] ? $sql : $this->db->prepare($sql, ...$args));
    }

    public function endedBefore(int $before, int $limit = 200): array
    {
        $ended = "'" . implode("','", JobState::TERMINAL) . "'";
        return $this->rows($this->db->prepare(
            "SELECT * FROM {$this->table()} WHERE state IN ($ended) AND finished_at > 0 AND finished_at < %d ORDER BY id ASC LIMIT %d",
            $before,
            $limit
        ));
    }

    public function metadataBefore(int $before, int $limit = 200): array
    {
        return $this->rows($this->db->prepare(
            "SELECT * FROM {$this->table()} WHERE metadata IS NOT NULL AND metadata_at > 0 AND metadata_at < %d ORDER BY id ASC LIMIT %d",
            $before,
            $limit
        ));
    }

    public function delete(int $id): void
    {
        $this->db->delete($this->table(), ['id' => $id]);
    }

    public function nextWake(int $now): ?int
    {
        $held = "'" . implode("','", [JobState::HELD_CAP, JobState::HELD_QUOTA, JobState::HELD_ACCOUNT]) . "'";
        $v = $this->db->get_var($this->db->prepare(
            "SELECT MIN(held_until) FROM {$this->table()} WHERE state IN ($held) AND held_until > %d",
            $now
        ));
        return $v === null ? null : (int) $v;
    }

    /**
     * @param array{state?:string,user_id?:int,video_id?:string} $filters
     * @return array{0:string,1:list<string|int>}
     */
    private function where(array $filters): array
    {
        $parts = ['1=1'];
        $args = [];
        if (isset($filters['state'])) {
            $parts[] = 'state = %s';
            $args[] = $filters['state'];
        }
        if (isset($filters['user_id'])) {
            $parts[] = 'user_id = %d';
            $args[] = $filters['user_id'];
        }
        if (isset($filters['video_id'])) {
            $parts[] = 'video_id = %s';
            $args[] = $filters['video_id'];
        }
        return [implode(' AND ', $parts), $args];
    }

    /**
     * @return list<Job>
     */
    private function rows(?string $sql): array
    {
        if ($sql === null) {
            return [];
        }
        $rows = $this->db->get_results($sql, ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (is_array($r)) {
                $out[] = $this->fromRow($r);
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function toRow(Job $job): array
    {
        $a = $job->toArray();
        $row = [];
        foreach (self::MAP as $prop => $col) {
            $v = $a[$prop] ?? null;
            if (in_array($col, self::JSON, true)) {
                $v = $v === null ? null : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $row[$col] = $v;
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private function fromRow(array $row): Job
    {
        $d = [];
        foreach (self::MAP as $prop => $col) {
            $v = $row[$col] ?? null;
            if (in_array($col, self::JSON, true) && is_string($v)) {
                $v = json_decode($v, true);
            }
            $d[$prop] = $v;
        }
        return new Job($d);
    }
}
