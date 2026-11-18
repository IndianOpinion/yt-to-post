<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Unit;

use IndianOpinion\YtToPost\Exception\AccountHoldException;
use IndianOpinion\YtToPost\Exception\CapReachedException;
use IndianOpinion\YtToPost\Exception\DisabledException;
use IndianOpinion\YtToPost\Exception\QuotaException;
use IndianOpinion\YtToPost\Http\HttpResponse;
use IndianOpinion\YtToPost\Jobs\JobState;
use IndianOpinion\YtToPost\Jobs\Pipeline;
use IndianOpinion\YtToPost\Limits\KillSwitch;
use IndianOpinion\YtToPost\Limits\QuotaMeter;
use IndianOpinion\YtToPost\Support\Days;
use IndianOpinion\YtToPost\Tests\Support\FakeHttp;
use IndianOpinion\YtToPost\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** PRD 4.10: caps, switch and quota (T69 to T73). */
final class LimitsTest extends TestCase
{
    private function ready(): void
    {
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
    }

    public function testT69SwitchStopsRunningJobAndResumes(): void
    {
        $this->ready();
        $p = $this->runner()->pipeline();
        $job = $p->advance($this->createJob());
        $this->assertSame(JobState::CAPTIONS, $job->state);
        KillSwitch::set(false);
        $this->assertFalse(yt_to_post_is_enabled());
        $before = count($this->http->requests);
        $this->runAll();
        $held = $this->job($job->id);
        $this->assertSame(JobState::HELD_DISABLED, $held->state);
        $this->assertSame(JobState::CAPTIONS, $held->step);
        $this->assertCount($before, $this->http->requests, 'stopped before its next external call');
        try {
            $this->jobs->create('https://youtu.be/aaaaaaaaaaa', [], 1, true);
            $this->fail('new requests refused');
        } catch (DisabledException) {
        }
        KillSwitch::set(true);
        $this->assertSame(JobState::DONE, $this->runAll()->state);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testT69ConstantBeatsOption(): void
    {
        define('YT_TO_POST_DISABLED', true);
        $this->settings(['enabled' => true]);
        $this->assertFalse(KillSwitch::isEnabled());
        $this->assertFalse(KillSwitch::set(true));
        $this->assertTrue(KillSwitch::lockedByConstant());
    }

    public function testT70CapsPerSiteDayFilterLowersOnlyAndZero(): void
    {
        $this->ready();
        $this->settings(['max_videos_per_day' => 1]);
        $this->createJob();
        $this->assertSame(JobState::DONE, $this->runAll()->state);
        $this->routeVideo($this->item([], [], [], 'bbbbbbbbbbb'));
        $second = $this->createJob('bbbbbbbbbbb');
        $this->runAll();
        $held = $this->job($second->id);
        $this->assertSame(JobState::HELD_CAP, $held->state);
        $this->assertSame(Days::nextSiteMidnight($this->clock->now()), $held->heldUntil);
        $this->clock->set($held->heldUntil + 1);
        $this->assertSame(JobState::DONE, $this->runAll()->state, 'resumes the next day');

        add_filter('yt_to_post_daily_caps', static fn (array $c): array => ['videos' => 500, 'calls' => 3]);
        $this->assertSame(['videos' => 1, 'calls' => 3], $this->services->caps()->limits());

        $this->settings(['max_videos_per_day' => 0]);
        $this->expectException(CapReachedException::class);
        $this->jobs->create('https://youtu.be/ccccccccccc', [], 1, true);
    }

    public function testT70SiteTimezoneDay(): void
    {
        \WpStub::$timezone = 'Asia/Kolkata';
        $this->clock->set(strtotime('2026-11-15 18:00:00 UTC') ?: 0);
        $this->assertSame('20261115', Days::siteKey($this->clock->now()));
        $this->clock->set(strtotime('2026-11-15 18:31:00 UTC') ?: 0);
        $this->assertSame('20261116', Days::siteKey($this->clock->now()), 'midnight in Kolkata is 18:30 UTC');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testT70ConstantOverridesVideoCap(): void
    {
        define('YT_TO_POST_DAILY_VIDEOS', 2);
        $this->settings(['max_videos_per_day' => 50]);
        $this->assertSame(2, $this->services->caps()->limits()['videos']);
    }

    public function testT70ModelAttemptsCountedIncludingRetries(): void
    {
        $this->ready();
        $this->settings(['max_model_calls_per_day' => 2, 'article_language' => 'hi']);
        $this->answerArticle(['language' => 'hi']);
        $this->createJob();
        $job = $this->runAll();
        $this->assertSame(2, $this->services->caps()->callsUsed());
        $this->assertSame(JobState::FAILED, $job->state);
    }

    public function testT71QuotaPerPacificDayAcrossDst(): void
    {
        // US daylight saving ended on 1 November 2026 at 09:00 UTC.
        $this->clock->set(strtotime('2026-11-01 06:59:00 UTC') ?: 0);
        $this->assertSame('20261031', Days::pacificKey($this->clock->now()));
        $this->assertSame(strtotime('2026-11-01 07:00:00 UTC'), Days::nextPacificMidnight($this->clock->now()));
        $this->clock->set(strtotime('2026-11-02 07:59:00 UTC') ?: 0);
        $this->assertSame('20261101', Days::pacificKey($this->clock->now()));
        $this->assertSame(strtotime('2026-11-02 08:00:00 UTC'), Days::nextPacificMidnight($this->clock->now()), 'after the change, midnight is 08:00 UTC');

        $this->settings(['youtube_quota_budget' => 60]);
        $q = new QuotaMeter($this->clock);
        $q->reserve('captions.list');
        $q->reserve('videos.list');
        $this->assertSame(51, $q->used());
        $this->expectException(QuotaException::class);
        $q->reserve('captions.list');
    }

    public function testT71BudgetHoldsTheJob(): void
    {
        $this->ready();
        $this->settings(['youtube_quota_budget' => 100]);
        $job = $this->createJob();
        $this->runAll();
        $held = $this->job($job->id);
        $this->assertSame(JobState::HELD_QUOTA, $held->state);
        $this->assertSame(JobState::CAPTIONS, $held->step);
        $this->assertSame(Days::nextPacificMidnight($this->clock->now()), $held->heldUntil);
        $this->assertSame([], $this->http->urls('captions/'), 'the 200-unit download was never started');
    }

    public function testT72QuotaExceededHoldsAndRateLimitsRetry(): void
    {
        $this->connect();
        $this->http->on('#/youtube/v3/videos\?#', FakeHttp::apiError(403, 'quotaExceeded'));
        $job = $this->createJob();
        $this->runAll();
        $held = $this->job($job->id);
        $this->assertSame(JobState::HELD_QUOTA, $held->state);
        $this->assertSame(Days::nextPacificMidnight($this->clock->now()), $held->heldUntil);
        $this->assertSame(9000, $this->services->quota()->used());
        $this->assertTrue($this->services->quota()->exhaustedToday());

        $this->setUp();
        $this->connect();
        $this->http->on('#/youtube/v3/videos\?#', FakeHttp::apiError(403, 'rateLimitExceeded'), FakeHttp::apiError(429, 'userRateLimitExceeded'), FakeHttp::json(['items' => [$this->item()]]));
        $job = $this->runner()->pipeline()->advance($this->createJob());
        $this->assertSame(JobState::CAPTIONS, $job->state);
        $this->assertCount(2, $this->sleeper->sleeps);
    }

    public function testT73CreditExhaustionHoldsAllJobsForAnHour(): void
    {
        $this->ready();
        $this->routeVideo($this->item());
        $this->caller->on('yt-to-post.single', static fn () => new AccountHoldException('out of credit'));
        $a = $this->createJob();
        $p = $this->runner()->pipeline();
        $other = $this->createJob('bbbbbbbbbbb');
        $this->repo->save($other->with(['state' => JobState::CAPTIONS]));
        $job = $a;
        while (!in_array($job->state, [JobState::HELD_ACCOUNT, JobState::FAILED], true)) {
            $job = $p->advance($job);
        }
        $this->assertSame(JobState::HELD_ACCOUNT, $job->state);
        $this->assertSame(0, $job->attempts, 'not counted as a failure');
        $this->assertSame($this->clock->now() + 3600, $job->heldUntil);
        $this->assertSame(JobState::HELD_ACCOUNT, $this->job($other->id)->state);
        $this->assertSame(JobState::CAPTIONS, $this->job($other->id)->step);
        $this->assertGreaterThan($this->clock->now(), (int) get_option(Pipeline::ACCOUNT_HOLD_OPTION));
    }

    public function testT77BackoffAndRetryAfter(): void
    {
        $r = new \IndianOpinion\YtToPost\Http\Retrier($this->sleeper, static fn (): float => 1.0);
        $this->assertSame(2.5, $r->delay(0, null));
        $this->assertSame(5.0, $r->delay(1, null));
        $this->assertSame(10.0, $r->delay(2, null));
        $this->assertSame(60.0, $r->delay(0, 300));
        $this->assertSame(7.0, $r->delay(0, 7));

        $this->connect();
        $this->http->on('#/youtube/v3/videos\?#', new HttpResponse(503, '{}', ['retry-after' => '7']), FakeHttp::apiError(500, 'backendError'), new \IndianOpinion\YtToPost\Exception\NetworkException('timeout'), FakeHttp::apiError(500, 'backendError'));
        $this->settings(['max_job_attempts' => 2]);
        $job = $this->createJob();
        $job = $this->runner()->pipeline()->advance($job);
        $this->assertCount(4, $this->http->urls('/videos?'), 'first try plus three retries');
        $this->assertSame(7.0, $this->sleeper->sleeps[0]);
        $this->assertSame(1, $job->attempts);
        $this->assertSame(JobState::METADATA, $job->state);
        $this->clock->advance(61);
        $job = $this->runner()->pipeline()->advance($job);
        $this->assertSame(JobState::FAILED, $job->state);
        $this->assertStringContainsString('500', $job->lastError);
    }
}
