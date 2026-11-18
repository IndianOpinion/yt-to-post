<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

use IndianOpinion\YtToPost\Draft\DraftWriter;
use IndianOpinion\YtToPost\Exception\CapReachedException;
use IndianOpinion\YtToPost\Exception\ConfigurationException;
use IndianOpinion\YtToPost\Exception\DisabledException;
use IndianOpinion\YtToPost\Exception\InvalidTranscriptException;
use IndianOpinion\YtToPost\Exception\InvalidVideoException;
use IndianOpinion\YtToPost\Exception\YtToPostException;
use IndianOpinion\YtToPost\Integrations\GenQueue;
use IndianOpinion\YtToPost\Language;
use IndianOpinion\YtToPost\Limits\KillSwitch;
use IndianOpinion\YtToPost\Services;
use IndianOpinion\YtToPost\Settings;
use IndianOpinion\YtToPost\Transcript\Transcript;
use IndianOpinion\YtToPost\Video\VideoIdParser;
use IndianOpinion\YtToPost\Video\VideoMeta;
use IndianOpinion\YtToPost\Video\VideoRef;
use IndianOpinion\YtToPost\YouTube\Connection;
use IndianOpinion\YtToPost\YouTube\YouTubeClient;

/**
 * Creating, resuming, cancelling and retrying jobs. Entry point for the
 * admin screen, REST, WP-CLI and the global functions.
 */
final class JobService
{
    public const ARG_KEYS = [
        'transcript', 'transcript_language', 'article_language', 'status', 'user_id', 'category', 'chapters',
        'force', 'allow_unlisted', 'override_limits', 'rights_confirmed',
    ];

    /** @var \Closure(int,string,int):void */
    private \Closure $scheduler;

    /**
     * @param (callable(int,string,int):void)|null $scheduler called with job id, video id, force counter
     */
    public function __construct(private readonly Services $s, ?callable $scheduler = null)
    {
        $this->scheduler = $scheduler === null
            ? static function (int $id, string $video, int $force): void {
                if (!GenQueue::enqueue($id, $video, $force)) {
                    Scheduler::kick();
                }
            }
            : \Closure::fromCallable($scheduler);
    }

    /**
     * @param array<string,mixed> $args
     * @throws InvalidVideoException|DisabledException|CapReachedException|InvalidTranscriptException|ConfigurationException
     */
    public function create(string $video, array $args, int $callerId, bool $callerCanManage): CreateOutcome
    {
        if (!KillSwitch::isEnabled()) {
            throw new DisabledException('yt-to-post is switched off.');
        }
        $ref = VideoIdParser::parse($video);
        if ($ref === null || $ref->videoId === null) {
            throw new InvalidVideoException('Not a YouTube video link');
        }
        if ($this->s->caps()->limits()['videos'] === 0) {
            throw new CapReachedException('The daily video cap is 0: no new work.');
        }
        $userId = isset($args['user_id']) ? (int) $args['user_id'] : $callerId;
        if ($userId !== $callerId && !$callerCanManage) {
            throw new YtToPostException('Creating a job for another user needs the settings capability.');
        }
        $transcript = isset($args['transcript']) && is_string($args['transcript']) && trim($args['transcript']) !== ''
            ? $args['transcript'] : null;
        if ($transcript !== null && strlen($transcript) > Settings::int('max_transcript_bytes')) {
            throw new InvalidTranscriptException(sprintf('The transcript is larger than the limit of %d bytes.', Settings::int('max_transcript_bytes')));
        }
        if ($transcript === null && !YouTubeClient::canReadMetadata()) {
            throw new ConfigurationException('Connect a YouTube account or add an API key');
        }
        foreach (['transcript_language', 'article_language'] as $k) {
            if (isset($args[$k]) && is_string($args[$k]) && $args[$k] !== '' && !in_array($args[$k], ['same', 'source', 'site'], true) && !Language::isValid($args[$k])) {
                throw new InvalidVideoException(sprintf('%s must be a BCP-47 tag.', $k));
            }
        }
        $id = $ref->videoId;
        $force = (bool) ($args['force'] ?? false);
        $latest = $this->s->jobs->latestForVideo($id);
        if ($latest !== null && !JobState::isTerminal($latest->state)) {
            return new CreateOutcome($latest, true, 'A job for this video is already running or waiting.');
        }
        if (!$force && $latest !== null && $latest->state === JobState::DONE && $this->postExists($latest->postId)) {
            return new CreateOutcome($latest, true, 'A draft for this video already exists.', $latest->postId);
        }
        if (!$force && $latest === null) {
            $postId = $this->existingPost($id);
            if ($postId > 0) {
                return new CreateOutcome(null, true, 'A draft for this video already exists.', $postId);
            }
        }
        $seq = $latest === null ? 0 : ((int) $latest->arg('force_seq', 0)) + 1;
        $jobArgs = [
            'transcript_language' => is_string($args['transcript_language'] ?? null) ? $args['transcript_language'] : '',
            'article_language' => is_string($args['article_language'] ?? null) && $args['article_language'] !== ''
                ? $args['article_language'] : Settings::string('article_language'),
            'status' => DraftWriter::status(is_string($args['status'] ?? null) ? $args['status'] : Settings::string('default_status')),
            'category' => (int) ($args['category'] ?? 0),
            'chapters' => is_string($args['chapters'] ?? null) ? $args['chapters'] : '',
            'force' => $force,
            'allow_unlisted' => (bool) ($args['allow_unlisted'] ?? false),
            'override_limits' => (bool) ($args['override_limits'] ?? false),
            'rights_confirmed' => (bool) ($args['rights_confirmed'] ?? false),
            'rights_user' => (bool) ($args['rights_confirmed'] ?? false) ? $callerId : 0,
            'start_seconds' => $ref->startSeconds,
            'force_seq' => $seq,
        ];
        $now = $this->s->clock->now();
        $job = new Job([
            'videoId' => $id,
            'state' => JobState::QUEUED,
            'step' => JobState::METADATA,
            'userId' => $userId,
            'sourceKind' => $transcript !== null ? Transcript::KIND_TRANSCRIPT : '',
            'source' => $transcript,
            'args' => $jobArgs,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);
        if ($ref->playlistId !== null) {
            $job = $job->withWarning('Playlist parameter ignored: only the single video in the link is used.');
        }
        if ($ref->startSeconds !== null) {
            $job = $job->withLog('start time in link: ' . $ref->startSeconds . 's (recorded only)', $now);
        }
        $job = $this->s->jobs->insert($job->withLog('created by user ' . $callerId, $now));
        if (function_exists('do_action')) {
            do_action('yt_to_post_job_created', $job);
        }
        ($this->scheduler)($job->id, $id, $seq);
        return new CreateOutcome($job, false, 'Job created.');
    }

    /**
     * Opt-in: one job per video in a playlist, up to the configured number.
     * Daily caps apply when each job starts its first model call.
     *
     * @param array<string,mixed> $args
     * @return list<CreateOutcome>
     */
    public function createFromPlaylist(string $url, array $args, int $callerId, bool $callerCanManage): array
    {
        if (!Settings::bool('allow_playlists')) {
            throw new InvalidVideoException('Not a YouTube video link (playlists are not enabled in the settings).');
        }
        $ref = VideoIdParser::parse($url);
        if ($ref === null || $ref->playlistId === null) {
            throw new InvalidVideoException('Not a YouTube playlist link');
        }
        if (!KillSwitch::isEnabled()) {
            throw new DisabledException('yt-to-post is switched off.');
        }
        unset($args['transcript'], $args['chapters']);
        $ids = $this->s->youtube()->playlistVideoIds($ref->playlistId, Settings::int('max_playlist_items'));
        $out = [];
        foreach ($ids as $id) {
            $out[] = $this->create($id, $args, $callerId, $callerCanManage);
        }
        return $out;
    }

    /**
     * Supplies a transcript to a job waiting for one and resumes it at the
     * normalising step, without repeating the metadata call.
     *
     * @param array<string,mixed> $args transcript_language, chapters
     */
    public function supplyTranscript(int $jobId, string $transcript, array $args = []): bool
    {
        $job = $this->s->jobs->find($jobId);
        if ($job === null || $job->state !== JobState::NEEDS_TRANSCRIPT) {
            return false;
        }
        if (trim($transcript) === '') {
            throw new InvalidTranscriptException('The transcript is empty.');
        }
        if (strlen($transcript) > Settings::int('max_transcript_bytes')) {
            throw new InvalidTranscriptException(sprintf('The transcript is larger than the limit of %d bytes.', Settings::int('max_transcript_bytes')));
        }
        $jobArgs = $job->args;
        if (isset($args['transcript_language']) && is_string($args['transcript_language'])) {
            $lang = $args['transcript_language'];
            if ($lang !== '' && $lang !== 'same' && !Language::isValid($lang)) {
                throw new InvalidTranscriptException('transcript_language must be a BCP-47 tag.');
            }
            $jobArgs['transcript_language'] = $lang;
        }
        $chapters = $job->chapters;
        if (isset($args['chapters']) && is_string($args['chapters']) && trim($args['chapters']) !== '' && $job->metadata !== null) {
            $list = \IndianOpinion\YtToPost\Video\ChapterParser::parse($args['chapters'], VideoMeta::fromArray($job->metadata)->duration);
            if ($list->isValid()) {
                $chapters = array_map(static fn ($c): array => $c->toArray(), $list->chapters);
            } else {
                $job = $job->withWarning('supplied chapters ignored: ' . ($list->ignoredReason ?? 'no chapter lines found'));
            }
        }
        $next = $job->metadata !== null ? JobState::NORMALISE : JobState::METADATA;
        $from = $job->state;
        $job = $job->with([
            'source' => $transcript,
            'sourceKind' => Transcript::KIND_TRANSCRIPT,
            'transcriptLanguage' => '',
            'args' => $jobArgs,
            'chapters' => $chapters,
            'state' => $next,
            'step' => $next,
            'lastError' => '',
            'heldUntil' => 0,
            'attempts' => 0,
            'updatedAt' => $this->s->clock->now(),
        ])->withLog('transcript supplied', $this->s->clock->now());
        JobEvents::save($this->s->jobs, $job, $from);
        ($this->scheduler)($job->id, $job->videoId, (int) $job->arg('force_seq', 0));
        return true;
    }

    /** Confirms the rights for a video from another channel and resumes. */
    public function confirmRights(int $jobId, int $userId): bool
    {
        $job = $this->s->jobs->find($jobId);
        if ($job === null || $job->state !== JobState::NEEDS_RIGHTS) {
            return false;
        }
        $args = $job->args;
        $args['rights_confirmed'] = true;
        $args['rights_user'] = $userId;
        $from = $job->state;
        $next = JobState::isActive($job->step) ? $job->step : JobState::METADATA;
        $job = $job->with(['args' => $args, 'state' => $next, 'lastError' => '', 'updatedAt' => $this->s->clock->now()])
            ->withLog('rights confirmed by user ' . $userId, $this->s->clock->now());
        JobEvents::save($this->s->jobs, $job, $from);
        ($this->scheduler)($job->id, $job->videoId, (int) $job->arg('force_seq', 0));
        return true;
    }

    public function cancel(int $id): bool
    {
        $job = $this->s->jobs->find($id);
        if ($job === null || JobState::isTerminal($job->state)) {
            return false;
        }
        $from = $job->state;
        $job = $job->with([
            'state' => JobState::CANCELLED,
            'step' => JobState::isActive($from) ? $from : $job->step,
            'finishedAt' => $this->s->clock->now(),
            'updatedAt' => $this->s->clock->now(),
        ])->withLog('cancelled', $this->s->clock->now());
        JobEvents::save($this->s->jobs, $job, $from);
        return true;
    }

    public function retry(int $id): bool
    {
        $job = $this->s->jobs->find($id);
        if ($job === null || $job->state === JobState::DONE || $job->state === JobState::NEEDS_TRANSCRIPT || $job->state === JobState::NEEDS_RIGHTS) {
            return false;
        }
        if ($job->state === JobState::NEEDS_AUTH && !Connection::isConnected()) {
            return false;
        }
        $from = $job->state;
        $step = JobState::isActive($job->step) && $job->step !== JobState::QUEUED ? $job->step : JobState::METADATA;
        if ($job->metadata === null) {
            $step = JobState::METADATA;
        }
        $job = $job->with([
            'state' => $step,
            'step' => $step,
            'attempts' => 0,
            'heldUntil' => 0,
            'lastError' => '',
            'finishedAt' => 0,
            'updatedAt' => $this->s->clock->now(),
        ])->withLog('retry requested', $this->s->clock->now());
        JobEvents::save($this->s->jobs, $job, $from);
        ($this->scheduler)($job->id, $job->videoId, (int) $job->arg('force_seq', 0));
        return true;
    }

    /** After a reconnect, jobs waiting for auth go back to their step. */
    public function resumeAuthWaiting(): int
    {
        $n = 0;
        foreach ($this->s->jobs->list(['state' => JobState::NEEDS_AUTH], 500) as $job) {
            $step = JobState::isActive($job->step) && $job->step !== JobState::QUEUED ? $job->step : JobState::METADATA;
            JobEvents::save($this->s->jobs, $job->with(['state' => $step, 'step' => $step, 'lastError' => '', 'heldUntil' => 0]), $job->state);
            $n++;
        }
        if ($n > 0) {
            Scheduler::kick();
        }
        return $n;
    }

    /**
     * Metadata call only (1 unit): duration, captions flag, estimates.
     *
     * @return array<string,mixed>
     */
    public function estimate(string $video): array
    {
        $ref = VideoIdParser::parse($video);
        if ($ref === null || $ref->videoId === null) {
            throw new InvalidVideoException('Not a YouTube video link');
        }
        if (!YouTubeClient::canReadMetadata()) {
            throw new ConfigurationException('Connect a YouTube account or add an API key');
        }
        $item = $this->s->youtube()->video($ref->videoId);
        if ($item === null) {
            throw new InvalidVideoException('Video not found or not visible to the connected account');
        }
        $meta = VideoMeta::fromApiItem($item);
        $own = $meta->channelId !== '' && $meta->channelId === Connection::channelId();
        $captions = $meta->caption && Connection::isConnected();
        $e = Estimator::fromDuration($meta->duration, $captions);
        return [
            'video_id' => $meta->id,
            'title' => $meta->title,
            'channel' => $meta->channelTitle,
            'own_channel' => $own,
            'duration' => $meta->duration,
            'captions' => $meta->caption,
            'privacy' => $meta->privacyStatus,
            'estimated_chunks' => $e['chunks'],
            'estimated_model_calls' => $e['model_calls'],
            'estimated_quota_units' => $e['quota_units'],
            'over_duration_limit' => $meta->duration !== null && $meta->duration > Settings::int('max_duration_minutes') * 60,
        ];
    }

    private function postExists(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }
        $post = get_post($postId);
        return $post !== null && $post->post_status !== 'trash';
    }

    private function existingPost(string $videoId): int
    {
        $ids = get_posts([
            'post_type' => 'any',
            'post_status' => ['draft', 'pending', 'publish', 'future', 'private'],
            'meta_key' => DraftWriter::META_VIDEO_ID,
            'meta_value' => $videoId,
            'fields' => 'ids',
            'numberposts' => 1,
            'suppress_filters' => true,
        ]);
        return is_array($ids) && isset($ids[0]) ? (int) $ids[0] : 0;
    }

    public static function parse(string $video): ?VideoRef
    {
        return VideoIdParser::parse($video);
    }
}
