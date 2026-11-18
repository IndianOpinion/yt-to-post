<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Unit;

use IndianOpinion\YtToPost\Cli\ExitCodes;
use IndianOpinion\YtToPost\Exception\CapReachedException;
use IndianOpinion\YtToPost\Exception\DisabledException;
use IndianOpinion\YtToPost\Exception\NeedsTranscriptException;
use IndianOpinion\YtToPost\Exception\QuotaException;
use IndianOpinion\YtToPost\Jobs\Job;
use IndianOpinion\YtToPost\Jobs\JobState;
use IndianOpinion\YtToPost\Jobs\Pipeline;
use IndianOpinion\YtToPost\Jobs\Runner;
use IndianOpinion\YtToPost\Settings;
use IndianOpinion\YtToPost\Tests\TestCase;
use IndianOpinion\YtToPost\Transcript\Transcript;
use IndianOpinion\YtToPost\YouTube\TokenVault;

/** Settings validation, exit codes, notices, storage round trips. */
final class MiscTest extends TestCase
{
    public function testSettingsValidationKeepsOldValuesOnError(): void
    {
        [$v, $errors] = Settings::validate([
            'max_videos_per_day' => '501',
            'retention_days' => '0',
            'default_status' => 'publish',
            'article_language' => 'not a tag!',
            'model_base_url' => 'http://insecure.example',
            'max_chunks' => '12',
            'llm_api_key' => '',
            'keep_annotations' => '1',
        ], ['max_videos_per_day' => 7, 'llm_api_key' => 'kept-key']);
        $this->assertCount(5, $errors);
        $this->assertSame(7, $v['max_videos_per_day']);
        $this->assertSame(30, $v['retention_days']);
        $this->assertSame('draft', $v['default_status']);
        $this->assertSame(12, $v['max_chunks']);
        $this->assertSame('kept-key', $v['llm_api_key'], 'empty secret keeps the stored one');
        $this->assertTrue($v['keep_annotations']);
        [$v] = Settings::validate(['llm_api_key' => '-'], ['llm_api_key' => 'kept-key']);
        $this->assertSame('', $v['llm_api_key']);
        $this->assertSame('********5678', Settings::mask('abcd12345678'));
        $this->assertSame('GB', Settings::all()['site_country']);
    }

    public function testCliExitCodes(): void
    {
        $this->assertSame(3, ExitCodes::forError(new DisabledException()));
        $this->assertSame(4, ExitCodes::forError(new CapReachedException()));
        $this->assertSame(4, ExitCodes::forError(new QuotaException()));
        $this->assertSame(5, ExitCodes::forError(new NeedsTranscriptException()));
        $this->assertSame(1, ExitCodes::forError(new \RuntimeException()));
        $this->assertSame(0, ExitCodes::forJob(new Job(['state' => JobState::DONE])));
        $this->assertSame(5, ExitCodes::forJob(new Job(['state' => JobState::NEEDS_TRANSCRIPT])));
        $this->assertSame(4, ExitCodes::forJob(new Job(['state' => JobState::HELD_QUOTA])));
        $this->assertSame(3, ExitCodes::forJob(new Job(['state' => JobState::HELD_DISABLED])));
    }

    public function testNoticesForSettingsCapabilityOnly(): void
    {
        $this->connect();
        $this->createJob();
        update_option(Runner::LAST_TICK_OPTION, $this->clock->now() - 7200);
        update_option(Pipeline::ACCOUNT_HOLD_OPTION, $this->clock->now() + 100);
        $this->services->quota()->exhaust();
        $n = (new \IndianOpinion\YtToPost\Admin\Notices($this->services))->collect();
        $this->assertCount(3, $n);
        $this->assertStringContainsString('has not ticked', implode(' ', $n));
        \WpStub::$userId = 2;
        $this->assertSame([], (new \IndianOpinion\YtToPost\Admin\Notices($this->services))->collect());
    }

    public function testTranscriptStorageRoundTripAndVault(): void
    {
        $t = new Transcript([new \IndianOpinion\YtToPost\Transcript\Cue(1.5, 2.0, 'A', 'नमस्ते', true)], 'hi', Transcript::KIND_CAPTIONS);
        $back = Transcript::decode($t->encode());
        $this->assertNotNull($back);
        $this->assertSame('नमस्ते', $back->cues[0]->text);
        $this->assertTrue($back->cues[0]->turn);
        $this->assertSame('hi', $back->language);
        $this->assertNull(Transcript::decode('garbage'));

        $v = new TokenVault(static fn (): string => 'salt-a');
        $enc = $v->encrypt('secret');
        $this->assertSame('secret', $v->decrypt($enc));
        $this->assertNull((new TokenVault(static fn (): string => 'salt-b'))->decrypt($enc));
        $this->assertNull($v->decrypt('v1:' . base64_encode('short')));
    }

    public function testDebugLogNeverHoldsTranscriptText(): void
    {
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $this->createJob();
        $job = $this->runAll();
        foreach ($job->log as $line) {
            $this->assertStringNotContainsString('river carries', $line);
        }
        $this->assertArrayNotHasKey('log', array_diff_key($job->summary(), ['log' => 1]));
        $this->assertStringNotContainsString('river carries', (string) json_encode($job->summary()));
    }
}
