<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Unit;

use IndianOpinion\YtToPost\Integrations\GenQueue;
use IndianOpinion\YtToPost\Integrations\Hooks;
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
        add_filter(Hooks::name('gen_queue_enqueue'), static function ($accepted, string $type, array $payload, array $opts) use (&$seen) {
            $seen[] = [$type, $payload, $opts];
            return 'gq-1';
        }, 10, 4);
        $jobs = new JobService($this->services);
        $first = $jobs->create(self::VID, [], 1, true);
        $this->assertSame(GenQueue::JOB_TYPE, $seen[0][0]);
        $this->assertSame(['job_id' => $first->jobId(), 'video_id' => self::VID], $seen[0][1]);
        $this->assertSame('yt-to-post:' . self::VID . ':0', $seen[0][2]['idempotency_key']);
        $this->assertFalse(wp_next_scheduled('yt_to_post_run'), 'gen-queue took it: no own cron event');
        $jobs->cancel($first->jobId());
        $jobs->create(self::VID, ['force' => true], 1, true);
        $this->assertSame('yt-to-post:' . self::VID . ':1', $seen[1][2]['idempotency_key']);

        $handler = null;
        add_filter(Hooks::name('gen_queue_register'), static fn ($t) => $t);
        GenQueue::register(static function (int $id, float $s) use (&$handler): array {
            $handler = [$id, $s];
            return [];
        });
        $types = apply_filters(Hooks::name('gen_queue_register'), []);
        $types[GenQueue::JOB_TYPE]['handler'](['job_id' => 9], ['seconds' => 12]);
        $this->assertSame([9, 12.0], $handler);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testT56SpendMeterDenyHoldsAndAllowRecords(): void
    {
        define('SPEND_METER_API', 1);
        $this->ready();
        add_filter(Hooks::name('spend_pre_spend'), static fn ($v, array $ctx) => ['allowed' => 'yes', 'reason' => 'odd'], 10, 2);
        $job = $this->createJob();
        $this->runAll();
        $held = $this->job($job->id);
        $this->assertSame(JobState::HELD_CAP, $held->state);
        $this->assertStringContainsString('spend-meter', $held->lastError);
        $this->assertSame([], $this->caller->requests);

        remove_all_filters(Hooks::name('spend_pre_spend'));
        $ctxs = [];
        add_filter(Hooks::name('spend_pre_spend'), static function ($v, array $ctx) use (&$ctxs) {
            $ctxs[] = $ctx;
            return ['allowed' => true, 'reservation' => 'r1'];
        }, 10, 2);
        $recorded = [];
        add_action(Hooks::name('spend_record'), static function ($res, array $usage) use (&$recorded): void {
            $recorded[] = [$res, $usage];
        }, 10, 2);
        $this->clock->advance(3601);
        $this->assertSame(JobState::DONE, $this->runAll()->state);
        $this->assertSame('yt_to_post', $ctxs[0]['purpose']);
        $this->assertSame('yt-to-post', $ctxs[0]['caller']);
        $this->assertSame('test/model', $ctxs[0]['model']);
        $this->assertGreaterThan(0, $ctxs[0]['estimated_input_tokens']);
        $this->assertSame('r1', $recorded[0][0]);
        $this->assertSame(100, $recorded[0][1]['input_tokens']);
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
