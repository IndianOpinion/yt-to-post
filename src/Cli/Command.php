<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Cli;

use IndianOpinion\YtToPost\Exception\YtToPostException;
use IndianOpinion\YtToPost\Jobs\Housekeeping;
use IndianOpinion\YtToPost\Jobs\JobService;
use IndianOpinion\YtToPost\Jobs\JobState;
use IndianOpinion\YtToPost\Jobs\Runner;
use IndianOpinion\YtToPost\Limits\KillSwitch;
use IndianOpinion\YtToPost\Services;
use IndianOpinion\YtToPost\Settings;
use IndianOpinion\YtToPost\Transcript\Transcript;
use IndianOpinion\YtToPost\Transcript\TranscriptCleaner;
use IndianOpinion\YtToPost\Transcript\TranscriptReader;
use IndianOpinion\YtToPost\Transcript\WordCounter;
use IndianOpinion\YtToPost\Video\VideoIdParser;
use IndianOpinion\YtToPost\YouTube\Connection;
use WP_CLI;

/**
 * Turns YouTube videos into drafts.
 *
 * Exit codes: 0 success, 1 error, 3 disabled, 4 cap or quota reached,
 * 5 needs transcript.
 */
final class Command
{
    public function __construct(private readonly Services $s, private readonly JobService $jobs, private readonly Runner $runner)
    {
    }

    /**
     * Creates a job for one video (or each video of a playlist, when enabled).
     *
     * ## OPTIONS
     *
     * <video>
     * : A YouTube link or an 11-character id.
     *
     * [--transcript=<file>]
     * : A transcript file (vtt, srt, sbv, ttml, txt).
     *
     * [--chapters=<file>]
     * : A chapter list file, one "m:ss Title" per line.
     *
     * [--lang=<tag>]
     * : Transcript language.
     *
     * [--article-lang=<lang>]
     * : source, site or a BCP-47 tag.
     *
     * [--status=<status>]
     * : draft or pending.
     *
     * [--user=<id>]
     * : Author. Defaults to the configured default author.
     *
     * [--category=<id>]
     * : Category id.
     *
     * [--force]
     * : Make a new draft even if one exists.
     *
     * [--allow-unlisted]
     * : Accept an unlisted video.
     *
     * [--rights-confirmed]
     * : You have the rights to use a video from another channel.
     *
     * [--override-limits]
     * : Override the duration and chunk limits.
     *
     * [--wait]
     * : Run the job now and print the post id.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public function create(array $args, array $assoc): void
    {
        $user = isset($assoc['user']) ? (int) $assoc['user'] : Settings::int('default_author');
        if ($user <= 0) {
            WP_CLI::error('Pass --user or set a default author in the settings.');
        }
        try {
            $jobArgs = [
                'transcript' => isset($assoc['transcript']) ? $this->readFile((string) $assoc['transcript']) : null,
                'chapters' => isset($assoc['chapters']) ? $this->readFile((string) $assoc['chapters']) : '',
                'transcript_language' => (string) ($assoc['lang'] ?? ''),
                'article_language' => (string) ($assoc['article-lang'] ?? ''),
                'status' => (string) ($assoc['status'] ?? ''),
                'user_id' => $user,
                'category' => (int) ($assoc['category'] ?? 0),
                'force' => isset($assoc['force']),
                'allow_unlisted' => isset($assoc['allow-unlisted']),
                'rights_confirmed' => isset($assoc['rights-confirmed']),
                'override_limits' => isset($assoc['override-limits']),
            ];
            $ref = VideoIdParser::parse($args[0]);
            if ($ref !== null && $ref->isPlaylistOnly()) {
                foreach ($this->jobs->createFromPlaylist($args[0], $jobArgs, $user, true) as $o) {
                    WP_CLI::line(sprintf('%s job %d', $o->existing ? 'existing' : 'created', $o->jobId()));
                }
                return;
            }
            $o = $this->jobs->create($args[0], $jobArgs, $user, true);
        } catch (YtToPostException $e) {
            WP_CLI::warning($e->getMessage());
            WP_CLI::halt(ExitCodes::forError($e));
        }
        WP_CLI::line(sprintf('%s: job %d%s', $o->message, $o->jobId(), $o->postId > 0 ? ', post ' . $o->postId : ''));
        if (!isset($assoc['wait']) || $o->job === null) {
            return;
        }
        $this->runner->run(['job' => $o->job->id]);
        $job = $this->s->jobs->find($o->job->id);
        if ($job === null) {
            WP_CLI::halt(ExitCodes::ERROR);
        }
        if ($job->state === JobState::DONE) {
            WP_CLI::line((string) $job->postId);
            return;
        }
        WP_CLI::warning(sprintf('Job %d is %s: %s', $job->id, $job->state, $job->lastError));
        WP_CLI::halt(ExitCodes::forJob($job) === ExitCodes::OK ? ExitCodes::ERROR : ExitCodes::forJob($job));
    }

    /**
     * Runs jobs.
     *
     * [--time=<seconds>]
     * : Stop after this many seconds (between steps).
     *
     * [--max=<n>]
     * : Stop after this many jobs.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        $sum = $this->runner->run([
            'time' => isset($assoc['time']) ? (float) $assoc['time'] : null,
            'max' => isset($assoc['max']) ? (int) $assoc['max'] : null,
        ]);
        WP_CLI::line(sprintf(
            'claimed %d, done %d, held %d, failed %d in %.1fs (%s)',
            $sum->claimed,
            $sum->done,
            $sum->held,
            $sum->failed,
            $sum->seconds,
            $sum->stopReason
        ));
        if (!KillSwitch::isEnabled()) {
            WP_CLI::halt(ExitCodes::DISABLED);
        }
    }

    /**
     * Lists jobs.
     *
     * [--state=<state>]
     * : Only this state.
     *
     * [--limit=<n>]
     * : How many. Default 50.
     *
     * [--format=<format>]
     * : table or json.
     *
     * @subcommand list
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public function list_(array $args, array $assoc): void
    {
        $filters = isset($assoc['state']) ? ['state' => (string) $assoc['state']] : [];
        $rows = array_map(static function ($j): array {
            $s = $j->summary();
            unset($s['warnings']);
            return $s;
        }, $this->s->jobs->list($filters, (int) ($assoc['limit'] ?? 50)));
        $fields = ['id', 'video_id', 'title', 'state', 'step', 'user_id', 'post_id', 'model_calls', 'quota_units', 'last_error'];
        WP_CLI\Utils\format_items((string) ($assoc['format'] ?? 'table'), $rows, $fields);
    }

    /**
     * Shows one job with its log lines.
     *
     * <id>
     * : Job id.
     *
     * @param list<string> $args
     */
    public function show(array $args): void
    {
        $job = $this->s->jobs->find((int) $args[0]);
        if ($job === null) {
            WP_CLI::error('No such job.');
        }
        foreach ($job->summary() as $k => $v) {
            WP_CLI::line(sprintf('%-20s %s', $k, is_array($v) ? implode(' | ', array_map('strval', $v)) : (string) $v));
        }
        WP_CLI::line('log:');
        foreach ($job->log as $l) {
            WP_CLI::line('  ' . $l);
        }
    }

    /**
     * Supplies a transcript to a job waiting for one.
     *
     * <id>
     * : Job id.
     *
     * <file>
     * : Transcript file.
     *
     * [--lang=<tag>]
     * : Transcript language.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public function transcript(array $args, array $assoc): void
    {
        try {
            $ok = $this->jobs->supplyTranscript((int) $args[0], $this->readFile($args[1]), ['transcript_language' => (string) ($assoc['lang'] ?? '')]);
        } catch (YtToPostException $e) {
            WP_CLI::warning($e->getMessage());
            WP_CLI::halt(ExitCodes::forError($e));
        }
        $ok ? WP_CLI::success('Transcript received; the job resumes.') : WP_CLI::error('That job is not waiting for a transcript.');
    }

    /**
     * Retries a job.
     *
     * <id>
     * : Job id.
     *
     * @param list<string> $args
     */
    public function retry(array $args): void
    {
        $this->jobs->retry((int) $args[0]) ? WP_CLI::success('Queued again.') : WP_CLI::error('Nothing to retry.');
    }

    /**
     * Cancels a job.
     *
     * <id>
     * : Job id.
     *
     * @param list<string> $args
     */
    public function cancel(array $args): void
    {
        $this->jobs->cancel((int) $args[0]) ? WP_CLI::success('Cancelled.') : WP_CLI::warning('Nothing to cancel.');
    }

    /**
     * Shows the switch, caps and quota used today, the connection and the last runner tick.
     */
    public function status(): void
    {
        $caps = $this->s->caps();
        $limits = $caps->limits();
        $q = $this->s->quota();
        $tick = (int) get_option(Runner::LAST_TICK_OPTION, 0);
        WP_CLI::line('switch:      ' . (KillSwitch::isEnabled() ? 'on' : 'off') . (KillSwitch::lockedByConstant() ? ' (YT_TO_POST_DISABLED)' : ''));
        WP_CLI::line(sprintf('videos:      %d of %d today', $caps->videosUsed(), $limits['videos']));
        WP_CLI::line(sprintf('model calls: %d of %d today', $caps->callsUsed(), $limits['calls']));
        WP_CLI::line(sprintf('quota:       %d of %d units (Pacific day)', $q->used(), $q->budget()));
        WP_CLI::line('connection:  ' . Connection::state() . (Connection::get()['channel_title'] !== '' ? ' (' . Connection::get()['channel_title'] . ')' : ''));
        WP_CLI::line('last tick:   ' . ($tick > 0 ? gmdate('Y-m-d H:i:s', $tick) . ' UTC' : 'never'));
    }

    /**
     * Switches the plugin on.
     */
    public function enable(): void
    {
        KillSwitch::set(true) ? WP_CLI::success('Switched on.') : WP_CLI::error('YT_TO_POST_DISABLED is set: cannot switch on.');
    }

    /**
     * Switches the plugin off. Running jobs stop before their next external call.
     */
    public function disable(): void
    {
        KillSwitch::set(false);
        WP_CLI::success('Switched off.');
    }

    /**
     * Estimates chunks, model calls and quota units (one videos.list call).
     *
     * <video>
     * : A YouTube link or id.
     *
     * @param list<string> $args
     */
    public function estimate(array $args): void
    {
        try {
            foreach ($this->jobs->estimate($args[0]) as $k => $v) {
                WP_CLI::line(sprintf('%-22s %s', $k, is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v));
            }
        } catch (YtToPostException $e) {
            WP_CLI::warning($e->getMessage());
            WP_CLI::halt(ExitCodes::forError($e));
        }
    }

    /**
     * Parses and cleans a transcript locally and prints cues and stats. No network.
     *
     * <file>
     * : Transcript file.
     *
     * @param list<string> $args
     */
    public function parse(array $args): void
    {
        try {
            $r = (new TranscriptReader(Settings::int('max_transcript_bytes')))->read($this->readFile($args[0]));
        } catch (YtToPostException $e) {
            WP_CLI::warning($e->getMessage());
            WP_CLI::halt(ExitCodes::ERROR);
        }
        $cues = (new TranscriptCleaner(Settings::bool('keep_annotations')))->clean($r->cues);
        $t = new Transcript($cues, '', Transcript::KIND_TRANSCRIPT);
        WP_CLI::line($t->toReadableText());
        WP_CLI::line(sprintf('format %s, %d cues (%d malformed skipped), %d words', $r->format, count($cues), $r->malformed, WordCounter::count($t->text())));
    }

    /**
     * Runs the retention purge now.
     *
     * [--dry-run]
     * : Count only.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public function purge(array $args, array $assoc): void
    {
        $stats = (new Housekeeping($this->s))->run(isset($assoc['dry-run']));
        foreach ($stats as $k => $v) {
            WP_CLI::line(sprintf('%-28s %d', $k, $v));
        }
    }

    private function readFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new YtToPostException('Cannot read ' . $path);
        }
        $data = file_get_contents($path);
        if ($data === false) {
            throw new YtToPostException('Cannot read ' . $path);
        }
        return $data;
    }
}
