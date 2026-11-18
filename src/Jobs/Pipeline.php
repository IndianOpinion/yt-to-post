<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

use IndianOpinion\YtToPost\Article\Article;
use IndianOpinion\YtToPost\Article\ArticleValidator;
use IndianOpinion\YtToPost\Article\LanguageCheck;
use IndianOpinion\YtToPost\Article\PromptBuilder;
use IndianOpinion\YtToPost\Article\PromptContext;
use IndianOpinion\YtToPost\Article\QuoteVerifier;
use IndianOpinion\YtToPost\Article\Schemas;
use IndianOpinion\YtToPost\Chunking\Chunk;
use IndianOpinion\YtToPost\Chunking\Chunker;
use IndianOpinion\YtToPost\Chunking\TokenEstimator;
use IndianOpinion\YtToPost\Draft\DraftWriter;
use IndianOpinion\YtToPost\Exception\AccountHoldException;
use IndianOpinion\YtToPost\Exception\AuthException;
use IndianOpinion\YtToPost\Exception\CancelledException;
use IndianOpinion\YtToPost\Exception\CapReachedException;
use IndianOpinion\YtToPost\Exception\ConfigurationException;
use IndianOpinion\YtToPost\Exception\DisabledException;
use IndianOpinion\YtToPost\Exception\HoldException;
use IndianOpinion\YtToPost\Exception\InvalidTranscriptException;
use IndianOpinion\YtToPost\Exception\InvalidVideoException;
use IndianOpinion\YtToPost\Exception\ModelOutputException;
use IndianOpinion\YtToPost\Exception\NeedsTranscriptException;
use IndianOpinion\YtToPost\Exception\QuotaException;
use IndianOpinion\YtToPost\Exception\WrongLanguageException;
use IndianOpinion\YtToPost\Exception\YouTubeApiException;
use IndianOpinion\YtToPost\Integrations\SpendMeter;
use IndianOpinion\YtToPost\Language;
use IndianOpinion\YtToPost\Limits\KillSwitch;
use IndianOpinion\YtToPost\Model\ModelRequest;
use IndianOpinion\YtToPost\Model\ModelResponse;
use IndianOpinion\YtToPost\Services;
use IndianOpinion\YtToPost\Settings;
use IndianOpinion\YtToPost\Support\Options;
use IndianOpinion\YtToPost\Transcript\Transcript;
use IndianOpinion\YtToPost\Transcript\TranscriptCleaner;
use IndianOpinion\YtToPost\Transcript\TranscriptReader;
use IndianOpinion\YtToPost\Transcript\WordCounter;
use IndianOpinion\YtToPost\Video\ChapterParser;
use IndianOpinion\YtToPost\Video\MetadataGate;
use IndianOpinion\YtToPost\Video\VideoMeta;
use IndianOpinion\YtToPost\YouTube\Connection;
use IndianOpinion\YtToPost\YouTube\TrackSelector;
use IndianOpinion\YtToPost\YouTube\YouTubeClient;

/**
 * Runs one unit of work on a job and saves it: one step, or one model call
 * inside the map and reduce steps. Every unit is saved before the next
 * external call, so a crash resumes at the last saved point.
 */
final class Pipeline
{
    public const ACCOUNT_HOLD_OPTION = 'yt_to_post_account_hold_until';
    public const ACCOUNT_HOLD_SECONDS = 3600;
    private const RETRY_DELAY = 60;

    private PromptBuilder $prompts;

    public function __construct(private readonly Services $s)
    {
        $this->prompts = new PromptBuilder();
    }

    /**
     * Advances a job by one unit. Never throws for job-level problems: they
     * become a state on the job.
     */
    public function advance(Job $job, ?float $deadline = null): Job
    {
        $from = $job->state;
        $step = $this->currentStep($job);
        $job = $job->with(['state' => $step, 'step' => $step, 'heldUntil' => 0]);
        $yt = $this->s->youtube();
        $yt->resetSpent();
        try {
            $job = match ($step) {
                JobState::METADATA => $this->metadataStep($job),
                JobState::CAPTIONS => $this->captionsStep($job),
                JobState::NORMALISE => $this->normaliseStep($job),
                JobState::MAP => $this->mapStep($job, $deadline),
                JobState::REDUCE => $this->reduceStep($job, $deadline),
                JobState::DRAFT => $this->draftStep($job),
                default => throw new \LogicException('Unknown step ' . $step),
            };
        } catch (CancelledException) {
            $fresh = $this->s->jobs->find($job->id);
            return $fresh ?? $job;
        } catch (DisabledException $e) {
            $job = $this->hold($job, JobState::HELD_DISABLED, 0, $e->getMessage());
        } catch (HoldException $e) {
            $job = $this->hold($job, JobState::HELD_CAP, $e->until(), $e->getMessage());
        } catch (CapReachedException $e) {
            $job = $this->hold($job, JobState::HELD_CAP, $this->s->caps()->heldUntil(), $e->getMessage());
        } catch (QuotaException $e) {
            $job = $this->hold($job, JobState::HELD_QUOTA, $this->s->quota()->heldUntil(), $e->getMessage());
        } catch (AccountHoldException $e) {
            $job = $this->holdAccount($job, $e->getMessage());
        } catch (NeedsTranscriptException $e) {
            $job = $job->with(['state' => JobState::NEEDS_TRANSCRIPT, 'step' => JobState::NORMALISE, 'lastError' => $e->getMessage()])
                ->withLog('needs transcript: ' . $e->getMessage(), $this->now());
            if (function_exists('do_action')) {
                do_action('yt_to_post_needs_transcript', $job, $e->getMessage());
            }
        } catch (AuthException $e) {
            if (Connection::state() === Connection::NEEDS_RECONNECT) {
                $job = $this->toNeedsAuth($job, $e->getMessage());
                $this->moveWaitingToNeedsAuth($job->id);
            } else {
                $job = $this->retryLater($job, $e);
            }
        } catch (InvalidVideoException | InvalidTranscriptException | WrongLanguageException | ConfigurationException $e) {
            $job = $this->fail($job, $e);
        } catch (YouTubeApiException $e) {
            $job = $e->isRetryable() ? $this->retryLater($job, $e) : $this->fail($job, $e);
        } catch (\Throwable $e) {
            $job = $this->retryLater($job, $e);
        }
        $job = $job->with(['quotaUnits' => $job->quotaUnits + $yt->unitsSpent()]);
        return $this->persist($job, $from);
    }

    private function currentStep(Job $job): string
    {
        if ($job->state === JobState::QUEUED) {
            return JobState::METADATA;
        }
        if (JobState::isActive($job->state)) {
            return $job->state;
        }
        return JobState::isActive($job->step) && $job->step !== JobState::QUEUED ? $job->step : JobState::METADATA;
    }

    // Steps -----------------------------------------------------------------

    private function metadataStep(Job $job): Job
    {
        $this->guard($job);
        $yt = $this->s->youtube();
        $supplied = $job->source !== null || $job->transcript !== null;
        if (YouTubeClient::canReadMetadata()) {
            $item = $yt->video($job->videoId);
            if ($item === null) {
                throw new InvalidVideoException('Video not found or not visible to the connected account');
            }
            $meta = VideoMeta::fromApiItem($item);
        } elseif ($supplied) {
            $o = $yt->oEmbed($job->videoId);
            if ($o === null) {
                throw new InvalidVideoException(
                    'YouTube gave no public details for this video (missing, private or not embeddable). Connect a YouTube account or add an API key.'
                );
            }
            $meta = VideoMeta::fromOEmbed($job->videoId, $o);
            $job = $job->withWarning('Title and channel come from YouTube\'s public oEmbed endpoint; no publish date is available.');
        } else {
            throw new ConfigurationException('Connect a YouTube account or add an API key');
        }
        $own = $meta->channelId !== '' && $meta->channelId === Connection::channelId();
        $warnings = MetadataGate::check($meta, [
            'own' => $own,
            'allow_private_own' => Settings::bool('allow_private_own'),
            'allow_unlisted' => (bool) $job->arg('allow_unlisted', false),
            'override_limits' => (bool) $job->arg('override_limits', false),
            'max_duration_minutes' => Settings::int('max_duration_minutes'),
            'site_country' => Settings::string('site_country'),
        ], fn (int $seconds): string => $this->estimateText($seconds));
        foreach ($warnings as $w) {
            $job = $job->withWarning($w);
        }
        [$chapters, $chapterNote] = $this->chapters($job, $meta);
        if ($chapterNote !== null) {
            $job = $job->withWarning($chapterNote);
        }
        $args = $job->args;
        $args['own'] = $own;
        $next = $supplied ? JobState::NORMALISE : JobState::CAPTIONS;
        $job = $job->with([
            'metadata' => $meta->toArray(),
            'metadataAt' => $this->now(),
            'chapters' => $chapters,
            'args' => $args,
            'state' => $next,
            'step' => $next,
        ])->withLog('metadata read (' . $meta->source . ')', $this->now());
        if (!$own && !(bool) $job->arg('rights_confirmed', false)) {
            return $job->with([
                'state' => JobState::NEEDS_RIGHTS,
                'step' => $next,
                'lastError' => 'This video is from another channel. Confirm you have the rights to use it to continue.',
            ])->withLog('waiting for rights confirmation', $this->now());
        }
        return $job;
    }

    /**
     * @return array{0:list<array{start:int,title:string}>,1:?string}
     */
    private function chapters(Job $job, VideoMeta $meta): array
    {
        $note = null;
        $supplied = $job->arg('chapters');
        if (is_string($supplied) && trim($supplied) !== '') {
            $list = ChapterParser::parse($supplied, $meta->duration);
            if ($list->isValid()) {
                return [array_map(static fn ($c): array => $c->toArray(), $list->chapters), null];
            }
            $note = 'supplied chapters ignored: ' . ($list->ignoredReason ?? 'no chapter lines found');
        }
        $list = ChapterParser::parse($meta->description, $meta->duration);
        if ($list->ignoredReason !== null) {
            $note = trim(($note !== null ? $note . '; ' : '') . 'chapters ignored: ' . $list->ignoredReason);
        }
        return [array_map(static fn ($c): array => $c->toArray(), $list->chapters), $note];
    }

    private function captionsStep(Job $job): Job
    {
        $this->guard($job);
        if (!Connection::isConnected()) {
            throw new NeedsTranscriptException('Captions need a connected YouTube account. Supply a transcript to continue.');
        }
        $meta = $this->requireMeta($job);
        $yt = $this->s->youtube();
        $tracks = $yt->captions($job->videoId);
        if ($tracks === []) {
            throw new NeedsTranscriptException(
                $meta->caption ? 'No caption tracks are listed for this video. Supply a transcript to continue.'
                    : 'This video has no captions. Supply a transcript to continue.'
            );
        }
        $picked = (string) $job->arg('transcript_language', '');
        $picked = in_array($picked, ['', 'same'], true) ? '' : $picked;
        $tried = [];
        $relisted = false;
        while (true) {
            $track = TrackSelector::choose($tracks, $picked, $meta->defaultAudioLanguage, $meta->defaultLanguage, $tried);
            if ($track === null) {
                throw new NeedsTranscriptException(
                    $tried === []
                        ? 'No usable caption track (only forced, draft, commentary or unavailable tracks). Supply a transcript to continue.'
                        : 'The caption track could not be found. Supply a transcript to continue.'
                );
            }
            $tried[] = $track->id;
            $this->guard($job);
            try {
                $body = $this->downloadWithFallback($yt, $track->id);
            } catch (YouTubeApiException $e) {
                if ($e->httpStatus() === 403) {
                    throw new NeedsTranscriptException(
                        'Captions for this video can only be downloaded by its owner. Supply a transcript to continue.'
                    );
                }
                if ($e->httpStatus() === 404) {
                    if ($relisted) {
                        throw new NeedsTranscriptException('The caption track could not be found. Supply a transcript to continue.');
                    }
                    $relisted = true;
                    $this->guard($job);
                    $tracks = $yt->captions($job->videoId);
                    continue;
                }
                throw $e;
            }
            $kind = $track->isAsr() ? Transcript::KIND_CAPTIONS_ASR : Transcript::KIND_CAPTIONS;
            $args = $job->args;
            $args['track_kind'] = $track->trackKind;
            $job = $job->with([
                'source' => $body,
                'sourceKind' => $kind,
                'transcriptLanguage' => $track->language,
                'args' => $args,
                'state' => JobState::NORMALISE,
                'step' => JobState::NORMALISE,
            ])->withLog('captions downloaded (' . $track->language . ', ' . $track->trackKind . ')', $this->now());
            if ($track->isAsr()) {
                $job = $job->withWarning('Source is YouTube\'s automatic captions (speech recognition): check names and punctuation.');
            }
            return $job;
        }
    }

    private function downloadWithFallback(YouTubeClient $yt, string $trackId): string
    {
        foreach (['vtt', 'srt', null] as $format) {
            try {
                return $yt->download($trackId, $format);
            } catch (YouTubeApiException $e) {
                if ($e->reason() !== 'couldNotConvert' || $format === null) {
                    throw $e;
                }
            }
        }
        throw new NeedsTranscriptException('The captions could not be converted. Supply a transcript to continue.');
    }

    private function normaliseStep(Job $job): Job
    {
        if ($job->source === null) {
            if ($job->transcript !== null) {
                $next = (int) $job->arg('chunk_count', 1) > 1 ? JobState::MAP : JobState::REDUCE;
                return $job->with(['state' => $next, 'step' => $next]);
            }
            throw new NeedsTranscriptException('No transcript is available. Supply a transcript to continue.');
        }
        if ($job->metadata === null) {
            return $job->with(['state' => JobState::METADATA, 'step' => JobState::METADATA]);
        }
        $meta = $this->requireMeta($job);
        $result = (new TranscriptReader(Settings::int('max_transcript_bytes')))->read($job->source);
        if ($result->malformed > 0) {
            $job = $job->withWarning(sprintf('%d malformed cues skipped (first at line %d).', $result->malformed, (int) $result->firstBadLine));
        }
        $cues = (new TranscriptCleaner(Settings::bool('keep_annotations')))->clean($result->cues);
        $text = implode(' ', array_map(static fn ($c): string => $c->text, $cues));
        if (WordCounter::count($text) < Settings::int('min_words')) {
            throw new InvalidTranscriptException('Transcript too short for an article');
        }
        $kind = $job->sourceKind !== '' ? $job->sourceKind : Transcript::KIND_TRANSCRIPT;
        $lang = $job->transcriptLanguage;
        if ($kind === Transcript::KIND_TRANSCRIPT) {
            $picked = (string) $job->arg('transcript_language', '');
            $lang = Language::isValid($picked) ? $picked : ($meta->defaultAudioLanguage !== '' ? $meta->defaultAudioLanguage : $meta->defaultLanguage);
        }
        $transcript = new Transcript($cues, $lang, $kind);
        if (!$transcript->hasTimes()) {
            $job = $job->withWarning('Transcript has no times: the draft has no timestamp links.');
        }
        $articleLanguage = $this->resolveArticleLanguage((string) $job->arg('article_language', Settings::string('article_language')), $lang);
        $estimator = $this->estimator();
        $chunker = new Chunker($estimator, max(50, (int) apply_filters('yt_to_post_chunk_tokens', Settings::int('chunk_tokens'))));
        $total = $chunker->totalTokens($cues);
        $ranges = [];
        if ($total > Settings::int('single_pass_tokens')) {
            $chunks = $chunker->split($cues, $job->chapterList());
            $max = Settings::int('max_chunks');
            if (count($chunks) > $max && !(bool) $job->arg('override_limits', false)) {
                throw new InvalidTranscriptException(sprintf(
                    'Transcript needs %d chunks, over the limit of %d (about %d model calls). Tick the override to continue.',
                    count($chunks),
                    $max,
                    count($chunks) + 1
                ));
            }
            $ranges = $this->ranges($chunks);
        }
        $args = $job->args;
        $args['chunks'] = $ranges;
        $args['chunk_count'] = max(1, count($ranges));
        $args['format'] = $result->format;
        $next = count($ranges) > 1 ? JobState::MAP : JobState::REDUCE;
        if (count($ranges) === 1) {
            $args['chunks'] = [];
        }
        return $job->with([
            'source' => null,
            'transcript' => $transcript->encode(),
            'sourceKind' => $kind,
            'transcriptLanguage' => $lang,
            'articleLanguage' => $articleLanguage,
            'args' => $args,
            'notes' => [],
            'state' => $next,
            'step' => $next,
        ])->withLog(sprintf('transcript normalised: %d cues, %s, %d chunk(s)', count($cues), $result->format, $args['chunk_count']), $this->now());
    }

    /**
     * Stores chunks as cue index ranges, so retries reuse the same split.
     *
     * @param list<Chunk> $chunks
     * @return list<array{0:int,1:int}>
     */
    private function ranges(array $chunks): array
    {
        $out = [];
        $i = 0;
        foreach ($chunks as $c) {
            $n = count($c->cues);
            $out[] = [$i, $n];
            $i += $n;
        }
        return $out;
    }

    private function mapStep(Job $job, ?float $deadline): Job
    {
        $transcript = $this->requireTranscript($job);
        $chunks = $this->chunksOf($job, $transcript);
        $missing = null;
        foreach ($chunks as $c) {
            if (!isset($job->notes[$c->index])) {
                $missing = $c;
                break;
            }
        }
        if ($missing === null) {
            return $job->with(['state' => JobState::REDUCE, 'step' => JobState::REDUCE]);
        }
        $ctx = $this->context($job, $transcript);
        [$job, $res] = $this->callModel(
            $job,
            'yt-to-post.map',
            'map_notes',
            Schemas::map(),
            $this->prompts->system(PromptBuilder::MAP, $ctx),
            $this->prompts->map($ctx, $missing, count($chunks)),
            $deadline
        );
        $notes = $job->notes;
        $notes[$missing->index] = self::cleanNotes($res->data);
        $job = $job->with(['notes' => $notes])->withLog(sprintf('map %d of %d saved', $missing->index + 1, count($chunks)), $this->now());
        if (count($notes) >= count($chunks)) {
            $job = $job->with(['state' => JobState::REDUCE, 'step' => JobState::REDUCE]);
        }
        return $job;
    }

    private function reduceStep(Job $job, ?float $deadline): Job
    {
        $transcript = $this->requireTranscript($job);
        $ctx = $this->context($job, $transcript);
        $chunked = (int) $job->arg('chunk_count', 1) > 1;
        $purpose = $chunked ? PromptBuilder::REDUCE : PromptBuilder::SINGLE;
        if ($chunked) {
            $notes = [];
            ksort($notes);
            $map = $job->notes;
            ksort($map);
            $notes = array_values($map);
            $levels = is_array($job->arg('reduce_levels')) ? $job->arg('reduce_levels') : [];
            $estimator = $this->estimator();
            $limit = Settings::int('single_pass_tokens');
            for ($level = 0; $level < 2; $level++) {
                if ($estimator->estimate((string) json_encode($notes, JSON_UNESCAPED_UNICODE)) <= $limit) {
                    break;
                }
                $groups = $this->groupNotes($notes, $limit, $estimator);
                $done = is_array($levels[$level] ?? null) ? $levels[$level] : [];
                foreach ($groups as $g => $group) {
                    if (isset($done[$g])) {
                        continue;
                    }
                    [$job, $res] = $this->callModel(
                        $job,
                        'yt-to-post.reduce',
                        'map_notes',
                        Schemas::map(),
                        $this->prompts->system(PromptBuilder::MAP, $ctx),
                        $this->prompts->mergeNotes($ctx, $group),
                        $deadline
                    );
                    $done[$g] = self::cleanNotes($res->data);
                    $levels[$level] = $done;
                    $args = $job->args;
                    $args['reduce_levels'] = $levels;
                    return $job->with(['args' => $args])->withLog(sprintf('reduce level %d group %d saved', $level + 1, $g + 1), $this->now());
                }
                ksort($done);
                $notes = array_values($done);
            }
            $input = $this->prompts->reduce($ctx, $notes);
        } else {
            $input = $this->prompts->single($ctx, $transcript->cues);
        }
        $stronger = (bool) $job->arg('lang_retry', false);
        [$job, $res] = $this->callModel(
            $job,
            $chunked ? 'yt-to-post.reduce' : 'yt-to-post.single',
            'article',
            Schemas::filteredArticle(),
            $this->prompts->system($purpose, $ctx, $stronger),
            $input,
            $deadline
        );
        $meta = $this->requireMeta($job);
        $duration = $meta->duration ?? $this->lastTime($transcript);
        $article = ArticleValidator::validate($res->data, $duration);
        $expected = $job->articleLanguage !== '' ? $job->articleLanguage
            : ($job->transcriptLanguage !== '' ? $job->transcriptLanguage : $article->sourceLanguage);
        if (!LanguageCheck::passes($article, $expected)) {
            if ($stronger) {
                throw new WrongLanguageException('Model answered in the wrong language');
            }
            $args = $job->args;
            $args['lang_retry'] = true;
            return $job->with(['args' => $args])->withLog('wrong language, retrying once', $this->now());
        }
        $article = (new QuoteVerifier($transcript->cues))->verify($article);
        if (!$transcript->hasTimes()) {
            $article = $this->withoutTimes($article);
        }
        if ($job->transcriptLanguage === '' && Language::isValid($article->sourceLanguage)) {
            $job = $job->with(['transcriptLanguage' => $article->sourceLanguage])
                ->withWarning('Transcript language reported by the model: ' . $article->sourceLanguage);
        }
        if (function_exists('apply_filters')) {
            $f = apply_filters('yt_to_post_article', $article, $job);
            if ($f instanceof Article) {
                $article = $f;
            }
        }
        if ($article->droppedQuotes > 0) {
            $job = $job->withWarning(sprintf('%d quote(s) dropped: not found word for word in the transcript.', $article->droppedQuotes));
        }
        return $job->with([
            'article' => $article->toArray(),
            'state' => JobState::DRAFT,
            'step' => JobState::DRAFT,
        ])->withLog('article written', $this->now());
    }

    private function draftStep(Job $job): Job
    {
        $this->checkCancelled($job);
        if ($job->article === null) {
            return $job->with(['state' => JobState::REDUCE, 'step' => JobState::REDUCE]);
        }
        $article = Article::fromArray($job->article);
        $meta = $this->requireMeta($job);
        $transcript = $this->requireTranscript($job);
        $notes = [
            'warnings' => $job->warnings,
            'dropped_quotes' => $article->droppedQuotes,
            'chunks' => (int) $job->arg('chunk_count', 1),
            'model_calls' => $job->modelCalls,
            'quota_units' => $job->quotaUnits,
            'source_kind' => $job->sourceKind,
            'track_kind' => (string) $job->arg('track_kind', ''),
            'transcript_language' => $job->transcriptLanguage,
            'article_language' => $article->language,
            'rights_confirmed' => (bool) $job->arg('rights_confirmed', false),
            'own_channel' => (bool) $job->arg('own', false),
            'requested_by' => $job->userId,
            'suggested_topics' => Settings::bool('topics_as_tags') ? [] : $article->topics,
        ];
        if (!QuoteVerifier::hasNfc()) {
            $notes['warnings'][] = 'Quote matching ran without Unicode NFC normalisation (the intl extension is missing).';
        }
        $offerThumbnail = (bool) $job->arg('own', false) || (bool) $job->arg('rights_confirmed', false);
        $postId = (new DraftWriter())->write($job, $article, $meta, $transcript, $notes, $offerThumbnail, $this->now());
        $job = $job->with([
            'postId' => $postId,
            'state' => JobState::DONE,
            'step' => JobState::DONE,
            'finishedAt' => $this->now(),
            'lastError' => '',
            'article' => null,
        ])->withLog('draft ' . $postId . ' created', $this->now());
        if (function_exists('do_action')) {
            do_action('yt_to_post_draft_created', $postId, $job, $article);
        }
        return $job;
    }

    // Model calls -----------------------------------------------------------

    /**
     * Re-reads the switch, the caps, the veto filter and spend-meter before
     * money is spent, and counts the attempt first.
     *
     * @param array<string,mixed> $schema
     * @return array{0:Job,1:ModelResponse}
     */
    private function callModel(Job $job, string $purpose, string $schemaName, array $schema, string $system, string $input, ?float $deadline): array
    {
        $this->guard($job);
        $holdUntil = Options::freshInt(self::ACCOUNT_HOLD_OPTION);
        if ($holdUntil > $this->now()) {
            throw new AccountHoldException('The model provider account is out of credit; jobs are held.');
        }
        $caller = $this->s->caller();
        if ($caller === null) {
            throw new ConfigurationException('No model is configured. Set a model and key in the settings.');
        }
        $caps = $this->s->caps();
        if (!(bool) $job->arg('video_counted', false) && !$caps->canStartVideo()) {
            throw new CapReachedException('Daily video cap reached; the job resumes tomorrow.');
        }
        if (!$caps->canCall()) {
            throw new CapReachedException('Daily model call cap reached; the job resumes tomorrow.');
        }
        $veto = function_exists('apply_filters') ? apply_filters('yt_to_post_can_run', true, $job) : true;
        if ($veto !== true) {
            throw new HoldException('Held by the yt_to_post_can_run filter.', $this->now() + 3600);
        }
        $estimate = $this->estimator($caller->model())->estimate($system . $input);
        $spend = SpendMeter::preSpend($caller->provider(), $caller->model(), $estimate);
        if (!$spend['allowed']) {
            throw new HoldException('Held by spend-meter: ' . $spend['reason'], $this->now() + 3600);
        }
        try {
            $args = $job->args;
            if (!(bool) ($args['video_counted'] ?? false)) {
                $caps->countVideo();
                $args['video_counted'] = true;
            }
            $caps->countCalls(1);
            $job = $job->with(['args' => $args, 'modelCalls' => $job->modelCalls + 1]);
            $this->s->jobs->save($job);
        } catch (\Throwable $e) {
            // The call will not happen: give the reservation back.
            SpendMeter::release($spend);
            throw $e;
        }
        $remaining = $deadline === null ? null : max(5.0, $deadline - $this->s->clock->micro());
        try {
            $res = $caller->call(new ModelRequest($purpose, $schemaName, $schema, $system, $input, $remaining));
        } catch (\Throwable $e) {
            // The call was attempted: spend-meter wants every attempt recorded.
            [$status, $http] = match (true) {
                $e instanceof ModelOutputException => ['error_billed', 200],
                $e instanceof AccountHoldException => ['error_unbilled', 402],
                default => ['error_unbilled', 0],
            };
            SpendMeter::record($spend, null, $status, $http, (new \ReflectionClass($e))->getShortName());
            throw $e;
        }
        SpendMeter::record($spend, $res);
        $extra = max(0, $res->attempts - 1);
        if ($extra > 0) {
            $caps->countCalls($extra);
        }
        $args = $job->args;
        if ($res->cost !== null) {
            $args['cost'] = (float) ($args['cost'] ?? 0) + $res->cost;
        }
        return [$job->with(['modelCalls' => $job->modelCalls + $extra, 'args' => $args]), $res];
    }

    // Helpers ---------------------------------------------------------------

    /** Before each external call: cancelled? switched off? */
    private function guard(Job $job): void
    {
        $this->checkCancelled($job);
        if (!KillSwitch::isEnabled()) {
            throw new DisabledException('yt-to-post is switched off; the job resumes when it is switched on.');
        }
    }

    private function checkCancelled(Job $job): void
    {
        $fresh = $this->s->jobs->find($job->id);
        if ($fresh !== null && $fresh->state === JobState::CANCELLED) {
            throw new CancelledException('Cancelled.');
        }
    }

    private function persist(Job $job, string $from): Job
    {
        $fresh = $this->s->jobs->find($job->id);
        if ($fresh !== null && $fresh->state === JobState::CANCELLED && $job->state !== JobState::CANCELLED) {
            $job = $job->with(['state' => JobState::CANCELLED, 'finishedAt' => $fresh->finishedAt]);
        }
        $job = $job->with(['updatedAt' => $this->now()]);
        JobEvents::save($this->s->jobs, $job, $from);
        return $job;
    }

    private function hold(Job $job, string $state, int $until, string $why): Job
    {
        return $job->with(['state' => $state, 'heldUntil' => $until, 'lastError' => $why])
            ->withLog('held (' . $state . '): ' . $why, $this->now());
    }

    private function holdAccount(Job $job, string $why): Job
    {
        $until = $this->now() + self::ACCOUNT_HOLD_SECONDS;
        update_option(self::ACCOUNT_HOLD_OPTION, $until, false);
        foreach ([JobState::QUEUED, JobState::METADATA, JobState::CAPTIONS, JobState::NORMALISE, JobState::MAP, JobState::REDUCE, JobState::DRAFT] as $state) {
            foreach ($this->s->jobs->list(['state' => $state], 500) as $other) {
                if ($other->id === $job->id) {
                    continue;
                }
                $step = $state === JobState::QUEUED ? JobState::METADATA : $state;
                JobEvents::save($this->s->jobs, $other->with([
                    'state' => JobState::HELD_ACCOUNT,
                    'step' => $step,
                    'heldUntil' => $until,
                    'lastError' => $why,
                ]), $other->state);
            }
        }
        return $this->hold($job, JobState::HELD_ACCOUNT, $until, $why);
    }

    private function toNeedsAuth(Job $job, string $why): Job
    {
        return $job->with(['state' => JobState::NEEDS_AUTH, 'lastError' => $why])->withLog('needs auth: ' . $why, $this->now());
    }

    private function moveWaitingToNeedsAuth(int $except): void
    {
        foreach ([JobState::QUEUED, JobState::METADATA, JobState::CAPTIONS, JobState::HELD_QUOTA] as $state) {
            foreach ($this->s->jobs->list(['state' => $state], 500) as $other) {
                if ($other->id === $except) {
                    continue;
                }
                $step = in_array($other->state, [JobState::QUEUED, JobState::HELD_QUOTA], true) ? $other->step : $other->state;
                JobEvents::save($this->s->jobs, $other->with([
                    'state' => JobState::NEEDS_AUTH,
                    'step' => $step === JobState::QUEUED ? JobState::METADATA : $step,
                    'lastError' => 'The YouTube account needs reconnecting.',
                ]), $other->state);
            }
        }
    }

    private function retryLater(Job $job, \Throwable $e): Job
    {
        $attempts = $job->attempts + 1;
        $msg = get_class($e) . ': ' . $e->getMessage();
        if ($attempts >= Settings::int('max_job_attempts')) {
            return $this->fail($job->with(['attempts' => $attempts]), $e);
        }
        return $job->with([
            'attempts' => $attempts,
            'lastError' => $msg,
            'heldUntil' => $this->now() + self::RETRY_DELAY * $attempts,
        ])->withLog('error, will retry: ' . $msg, $this->now());
    }

    private function fail(Job $job, \Throwable $e): Job
    {
        $msg = $e instanceof \IndianOpinion\YtToPost\Exception\YtToPostException
            ? $e->getMessage()
            : get_class($e) . ': ' . $e->getMessage();
        $job = $job->with([
            'state' => JobState::FAILED,
            'lastError' => $msg,
            'finishedAt' => $this->now(),
        ])->withLog('failed: ' . $msg, $this->now());
        if (function_exists('do_action')) {
            do_action('yt_to_post_job_failed', $job, $e);
        }
        return $job;
    }

    private function requireMeta(Job $job): VideoMeta
    {
        $meta = $job->meta();
        if ($meta === null) {
            throw new \RuntimeException('Video details are missing (purged under the retention setting?); they are read again on retry.');
        }
        return $meta;
    }

    private function requireTranscript(Job $job): Transcript
    {
        $t = $job->decodedTranscript();
        if ($t === null) {
            throw new NeedsTranscriptException('The stored transcript is missing. Supply a transcript to continue.');
        }
        return $t;
    }

    /**
     * @return list<Chunk>
     */
    private function chunksOf(Job $job, Transcript $t): array
    {
        $ranges = is_array($job->arg('chunks')) ? $job->arg('chunks') : [];
        $chunks = [];
        $chapters = $job->chapterList();
        foreach ($ranges as $i => $r) {
            if (!is_array($r) || count($r) !== 2) {
                continue;
            }
            $cues = array_slice($t->cues, (int) $r[0], (int) $r[1]);
            $start = null;
            $end = null;
            foreach ($cues as $c) {
                $start ??= $c->start;
                $end = $c->end ?? $end;
            }
            $titles = [];
            foreach ($chapters as $ci => $ch) {
                $chEnd = $chapters[$ci + 1]->start ?? PHP_INT_MAX;
                if ($start !== null && $end !== null && $ch->start < $end && $chEnd > $start) {
                    $titles[] = $ch->title;
                }
            }
            $chunks[] = new Chunk((int) $i, $cues, $start, $end, $titles, 0);
        }
        return $chunks;
    }

    /**
     * @param list<array<string,mixed>> $notes
     * @return list<list<array<string,mixed>>>
     */
    private function groupNotes(array $notes, int $limit, TokenEstimator $estimator): array
    {
        $groups = [];
        $current = [];
        foreach ($notes as $n) {
            $try = array_merge($current, [$n]);
            if ($current !== [] && $estimator->estimate((string) json_encode($try, JSON_UNESCAPED_UNICODE)) > $limit) {
                $groups[] = $current;
                $current = [$n];
            } else {
                $current = $try;
            }
        }
        if ($current !== []) {
            $groups[] = $current;
        }
        if (count($groups) === 1 && count($notes) > 1) {
            $half = intdiv(count($notes), 2);
            $groups = [array_slice($notes, 0, $half), array_slice($notes, $half)];
        }
        return $groups;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function cleanNotes(array $data): array
    {
        return [
            'summary' => is_string($data['summary'] ?? null) ? mb_substr($data['summary'], 0, 1200) : '',
            'points' => is_array($data['points'] ?? null) ? array_slice(array_values($data['points']), 0, 12) : [],
            'quotes' => is_array($data['quotes'] ?? null) ? array_slice(array_values($data['quotes']), 0, 8) : [],
            'names' => is_array($data['names'] ?? null) ? array_slice(array_values($data['names']), 0, 30) : [],
        ];
    }

    private function context(Job $job, Transcript $t): PromptContext
    {
        $meta = $this->requireMeta($job);
        return new PromptContext(
            $meta->title,
            $meta->channelTitle,
            $meta->description,
            array_map(static fn ($c): string => $c->title, $job->chapterList()),
            $job->articleLanguage,
            $job->transcriptLanguage,
            $t->isAsr(),
            $t->hasTimes(),
            $meta->duration
        );
    }

    private function withoutTimes(Article $a): Article
    {
        $sections = array_map(static function (array $s): array {
            $s['start_seconds'] = null;
            return $s;
        }, $a->sections);
        $quotes = array_map(static function (array $q): array {
            $q['start_seconds'] = null;
            return $q;
        }, $a->quotes);
        return $a->with(['sections' => $sections, 'quotes' => $quotes]);
    }

    private function lastTime(Transcript $t): ?int
    {
        $max = null;
        foreach ($t->cues as $c) {
            if ($c->end !== null) {
                $max = max($max ?? 0, (int) ceil($c->end));
            }
        }
        return $max;
    }

    public function resolveArticleLanguage(string $setting, string $transcriptLanguage): string
    {
        if ($setting === 'source') {
            return $transcriptLanguage;
        }
        if ($setting === 'site' || !Language::isValid($setting)) {
            return Settings::siteLanguage();
        }
        return $setting;
    }

    private function estimator(?string $model = null): TokenEstimator
    {
        if ($model === null) {
            $caller = $this->s->caller();
            $model = $caller !== null ? $caller->model() : '';
        }
        return TokenEstimator::forModel($model);
    }

    public function estimateText(int $durationSeconds): string
    {
        $e = Estimator::fromDuration($durationSeconds, Connection::isConnected());
        return sprintf('Estimate: %d chunk(s), about %d model call(s), %d quota units.', $e['chunks'], $e['model_calls'], $e['quota_units']);
    }

    private function now(): int
    {
        return $this->s->clock->now();
    }
}
