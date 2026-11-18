<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Unit;

use IndianOpinion\YtToPost\Integrations\GenQueue;
use IndianOpinion\YtToPost\Jobs\JobService;
use IndianOpinion\YtToPost\Jobs\JobState;
use IndianOpinion\YtToPost\Jobs\Runner;
use IndianOpinion\YtToPost\Jobs\SiteLock;
use IndianOpinion\YtToPost\Model\ModelRequest;
use IndianOpinion\YtToPost\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** PRD 4.12: jobs and running (T80 to T85), plus the soft integrations. */
final class JobsTest extends TestCase
{
    private function ready(): void
    {
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
    }

    public function testT80CrashBetweenStepsResumesFromSavedStep(): void
    {
        $this->ready();
        $p = $this->runner()->pipeline();
        $job = $p->advance($p->advance($this->createJob()));
        $this->assertSame(JobState::NORMALISE, $job->state);
        // Simulate a crash: the in-memory job is lost; only the store remains.
        $stored = $this->job($job->id);
        $this->assertNotNull($stored->source);
        $videos = count($this->http->urls('/videos?'));
        $captions = count($this->http->urls('captions/'));
        $done = $this->runAll();
        $this->assertSame(JobState::DONE, $done->state);
        $this->assertCount($videos, $this->http->urls('/videos?'));
        $this->assertCount($captions, $this->http->urls('captions/'));
        $states = [];
        add_action('yt_to_post_job_state', static function ($j, string $from, string $to) use (&$states): void {
            $states[] = "$from>$to";
        }, 10, 3);
        $this->routeVideo($this->item([], [], [], 'bbbbbbbbbbb'));
        $this->createJob('bbbbbbbbbbb');
        $this->runAll();
        $this->assertSame(['queued>captions', 'captions>normalise', 'normalise>reduce', 'reduce>draft', 'draft>done'], $states);
    }

    public function testT81TickTimeAndSiteLock(): void
    {
        $this->ready();
        $this->createJob();
        update_option(Runner::STEP_TIMES_OPTION, ['metadata' => 0.0, 'captions' => 30.0]);
        $sum = $this->runner()->run(['time' => 25]);
        $this->assertSame('time', $sum->stopReason);
        $this->assertSame(JobState::CAPTIONS, $this->repo->list()[0]->state, 'did not start a step it could not finish');

        $this->assertTrue(SiteLock::acquire($this->clock->now(), 60));
        $this->assertSame('locked', $this->runner()->run()->stopReason);
        $this->clock->advance(61);
        $this->assertSame(JobState::DONE, $this->runAll()->state, 'an expired lock is taken over');
        $this->assertFalse(get_option(SiteLock::OPTION), 'lock released after the run');
        $this->assertGreaterThan(0, (int) get_option(Runner::LAST_TICK_OPTION));
    }

    public function testT81RunnerWorksWithoutTimeLimitAndHonoursMax(): void
    {
        $this->ready();
        $this->createJob();
        $this->routeVideo($this->item([], [], [], 'bbbbbbbbbbb'));
        $this->createJob('bbbbbbbbbbb');
        $sum = yt_to_post_run(['max' => 1]);
        $this->assertSame(1, $sum->claimed);
        $this->assertSame('max', $sum->stopReason);
        $this->assertSame(1, $sum->done);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testT82GenQueueSoftEnqueue(): void
    {
        define('GEN_QUEUE_VERSION', '0.1.0');
        $this->connect();
        $seen = [];
        $answer = 41;
        add_filter('gen_queue_enqueue_job', static function ($accepted, string $type, array $payload, array $opts) use (&$seen, &$answer) {
            $seen[] = [$type, $payload, $opts];
            return $answer;
        }, 10, 4);
        $jobs = new JobService($this->services);
        $first = $jobs->create(self::VID, [], 1, true);
        $this->assertSame(GenQueue::JOB_TYPE, $seen[0][0]);
        $this->assertSame(['job_id' => $first->jobId(), 'video_id' => self::VID], $seen[0][1]);
        $this->assertSame('yt-to-post:' . self::VID . ':0', $seen[0][2]['idempotency_key']);
        $this->assertFalse(wp_next_scheduled('yt_to_post_run'), 'gen-queue took it: no own cron event');
        $jobs->cancel($first->jobId());
        $jobs->retry($first->jobId());
        $this->assertSame('yt-to-post:' . self::VID . ':0:r1', $seen[1][2]['idempotency_key'], 'a resume gets a new key: gen-queue never requeues a finished job');
        $jobs->cancel($first->jobId());
        $answer = new \WP_Error('gen_queue_failed', 'storage');
        $jobs->create(self::VID, ['force' => true], 1, true);
        $this->assertSame('yt-to-post:' . self::VID . ':1', $seen[2][2]['idempotency_key']);
        $this->assertNotFalse(wp_next_scheduled('yt_to_post_run'), 'a WP_Error falls back to the own runner');

        $registered = [];
        require_once dirname(__DIR__) . '/Support/gen-queue-functions.php';
        GenQueue::register(static function (int $id, float $s) use (&$registered): array {
            $registered = [$id, $s];
            return ['summary' => ['done' => 1], 'cost' => 0.0123];
        });
        do_action('gen_queue_register_types');
        $handler = $GLOBALS['gq_types'][GenQueue::JOB_TYPE];
        $job = new class {
            /** @var array<string,mixed> */
            public array $payload = ['job_id' => 9];
        };
        $ctx = new class {
            /** @var list<array{0:string,1:array<string,mixed>}> */
            public array $costs = [];
            public function deadline(): int
            {
                return time() + 30;
            }
            /** @param array<string,mixed> $usage */
            public function addCost(string $amount, array $usage = []): void
            {
                $this->costs[] = [$amount, $usage];
            }
        };
        $this->assertSame(['done' => 1], $handler($job, $ctx));
        $this->assertSame(9, $registered[0]);
        $this->assertGreaterThanOrEqual(20.0, $registered[1]);
        $this->assertSame([['0.012300000', ['metered' => false]]], $ctx->costs);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testT56SpendMeterDenyHoldsAndAllowRecords(): void
    {
        define('SPEND_METER_API', 1);
        $this->ready();
        $released = [];
        add_action('spend_meter_release', static function (string $id) use (&$released): void {
            $released[] = $id;
        });
        add_filter('spend_meter_pre_spend', static fn ($v, array $intent) => ['allowed' => 'yes', 'reason' => 'odd', 'reservation_id' => 'r0'], 10, 2);
        $job = $this->createJob();
        $this->runAll();
        $held = $this->job($job->id);
        $this->assertSame(JobState::HELD_CAP, $held->state);
        $this->assertStringContainsString('spend-meter', $held->lastError);
        $this->assertSame([], $this->caller->requests);
        $this->assertSame(['r0'], $released, 'a reservation on a deny is given back');

        remove_all_filters('spend_meter_pre_spend');
        $intents = [];
        add_filter('spend_meter_pre_spend', static function ($v, array $intent) use (&$intents) {
            $intents[] = $intent;
            return ['allowed' => true, 'reason' => 'ok', 'message' => '', 'reservation_id' => 'r1', 'estimate' => 10, 'remaining' => null, 'api' => 1];
        }, 10, 2);
        $recorded = [];
        add_action('spend_meter_record', static function (array $call) use (&$recorded): void {
            $recorded[] = $call;
        });
        $this->clock->advance(3601);
        $this->assertSame(JobState::DONE, $this->runAll()->state);
        $this->assertSame('yt_to_post', $intents[0]['purpose']);
        $this->assertSame('yt-to-post', $intents[0]['caller']);
        $this->assertSame('test/model', $intents[0]['model']);
        $this->assertGreaterThan(0, $intents[0]['est_input_tokens']);
        $this->assertSame('r1', $recorded[0]['reservation_id']);
        $this->assertSame('ok', $recorded[0]['status']);
        $this->assertSame(100, $recorded[0]['input_tokens']);
        $this->assertSame(['r0'], $released);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testT56SpendMeterNullMeansAbsentAndFailedCallsAreRecorded(): void
    {
        define('SPEND_METER_API', 1);
        $this->ready();
        $recorded = [];
        add_action('spend_meter_record', static function (array $call) use (&$recorded): void {
            $recorded[] = $call;
        });
        $job = $this->createJob();
        $this->caller->on('yt-to-post.single', static function (): array {
            throw new \IndianOpinion\YtToPost\Exception\ModelOutputException('broken body');
        });
        for ($i = 0; $i < 6 && $this->caller->requests === []; $i++) {
            $this->runner()->pipeline()->advance($this->job($job->id));
        }
        $this->assertCount(1, $this->caller->requests, 'no listener (null) means no meter: own caps decide');
        $this->assertSame('error_billed', $recorded[0]['status']);
        $this->assertArrayNotHasKey('reservation_id', $recorded[0]);
    }

    public function testT83CancelStopsBeforeNextCallAndIsNoOpOnDone(): void
    {
        $this->ready();
        $this->caller->on('yt-to-post.single', function (ModelRequest $r): array {
            return self::article();
        });
        $p = $this->runner()->pipeline();
        $job = $p->advance($this->createJob());
        $this->assertTrue(yt_to_post_cancel($job->id));
        $before = count($this->http->requests);
        $after = $p->advance($job);
        $this->assertSame(JobState::CANCELLED, $after->state);
        $this->assertCount($before, $this->http->requests);
        $this->assertSame(JobState::CANCELLED, $this->job($job->id)->state);

        $this->routeVideo($this->item([], [], [], 'bbbbbbbbbbb'));
        $done = $this->createJob('bbbbbbbbbbb');
        $this->runAll();
        $this->assertSame(JobState::DONE, $this->job($done->id)->state);
        $this->assertFalse(yt_to_post_cancel($done->id));
        $this->assertSame(JobState::DONE, $this->job($done->id)->state);
    }

    public function testT84DeletedDraftAllowsFreshCreate(): void
    {
        $this->ready();
        $this->createJob();
        $first = $this->runAll();
        wp_delete_post($first->postId, true);
        $o = $this->jobs->create(self::VID, ['rights_confirmed' => true], 1, true);
        $this->assertFalse($o->existing);
        $this->assertNotSame($first->id, $o->jobId());
        $this->assertSame(JobState::DONE, $this->job($first->id)->state, 'the old job record is untouched');
    }

    public function testT85OneJobFailureDoesNotStopTheNext(): void
    {
        $this->ready();
        $this->routeVideo($this->item([], [], [], 'bbbbbbbbbbb'));
        $bad = $this->createJob('bbbbbbbbbbb');
        $good = $this->createJob();
        add_filter('yt_to_post_can_run', static function ($ok, $job) {
            if ($job->videoId === 'bbbbbbbbbbb') {
                throw new \TypeError('a broken extension');
            }
            return $ok;
        }, 10, 2);
        $this->settings(['max_job_attempts' => 1]);
        $sum = $this->runner()->run();
        $this->assertSame(JobState::FAILED, $this->job($bad->id)->state);
        $this->assertStringContainsString('TypeError: a broken extension', $this->job($bad->id)->lastError);
        $this->assertSame(JobState::DONE, $this->job($good->id)->state);
        $this->assertSame(1, $sum->failed);
        $this->assertSame(1, $sum->done);
    }

    public function testRetryAndScheduling(): void
    {
        $this->ready();
        $this->http->on('#/youtube/v3/videos#', \IndianOpinion\YtToPost\Tests\Support\FakeHttp::json(['items' => []]));
        $job = $this->createJob();
        $this->runAll();
        $this->assertSame(JobState::FAILED, $this->job($job->id)->state);
        $this->routeVideo($this->item());
        $this->assertTrue(yt_to_post_retry($job->id));
        $this->assertSame(JobState::DONE, $this->runAll()->state);
        $this->assertFalse(yt_to_post_retry($job->id));
        $this->assertSame([$job->id, self::VID, 0], $this->scheduled[0]);
        $this->assertSame($job->id, yt_to_post_find_by_video(self::VID)?->id);
        $this->assertSame($job->id, yt_to_post_get_job($job->id)?->id);
    }
}
