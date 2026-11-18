<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Unit;

use IndianOpinion\YtToPost\Article\QuoteVerifier;
use IndianOpinion\YtToPost\Chunking\Chunker;
use IndianOpinion\YtToPost\Chunking\TokenEstimator;
use IndianOpinion\YtToPost\Jobs\JobState;
use IndianOpinion\YtToPost\Model\ModelRequest;
use IndianOpinion\YtToPost\Tests\TestCase;
use IndianOpinion\YtToPost\Transcript\TranscriptCleaner;
use IndianOpinion\YtToPost\Transcript\TranscriptReader;

/** T-PERF: a 60-minute talk end to end, and the 5 MiB memory budget. */
final class PerfTest extends TestCase
{
    public function testSixtyMinuteTalkEndToEnd(): void
    {
        $this->connect();
        $this->routeVideo($this->item(['description' => "0:00 Opening\n20:00 The river\n40:00 Questions"], [], ['duration' => 'PT60M']));
        $this->routeCaptions([self::track('t1', 'en')], self::vtt(720));
        $clock = $this->clock;
        // Every model call takes four seconds of simulated time.
        $this->caller->on('yt-to-post.map', static function (ModelRequest $r) use ($clock): array {
            $clock->advance(4);
            preg_match_all('/\[\d+s\] (Point number \d+ [^\n]+)/', $r->input, $m);
            $quotes = array_map(static fn (string $q): array => ['text' => $q, 'speaker' => null, 'start_seconds' => null], array_slice($m[1], 0, 2));
            return ['summary' => 'notes', 'points' => [], 'quotes' => $quotes, 'names' => []];
        });
        $this->caller->on('yt-to-post.reduce', static function (ModelRequest $r) use ($clock): array {
            $clock->advance(4);
            preg_match_all('/"text":"(Point number \d+[^"]+)"/', $r->input, $m);
            $quotes = array_map(static fn (string $q): array => ['text' => $q, 'translation' => null, 'speaker' => null, 'start_seconds' => 1], array_slice($m[1], 0, 6));
            $quotes[] = ['text' => 'A sentence the speaker never said at all', 'translation' => null, 'speaker' => null, 'start_seconds' => 5];
            return self::article(['quotes' => $quotes]);
        });
        $start = $this->clock->now();
        $job = $this->createJob();
        $ticks = 0;
        do {
            $before = $this->clock->micro();
            $sum = $this->runner()->run(['time' => 25]);
            $this->assertLessThanOrEqual(25.0, $this->clock->micro() - $before, 'a tick stays within tick_seconds');
            $ticks++;
            $job = $this->job($job->id);
        } while (!in_array($job->state, [JobState::DONE, JobState::FAILED], true) && $ticks < 50);
        $this->assertSame(JobState::DONE, $job->state, $job->lastError);
        $this->assertGreaterThan(2, (int) $job->arg('chunk_count'));
        $this->assertLessThan(300, $this->clock->now() - $start, 'under five minutes of wall time');
        if (getenv('YT_TO_POST_PERF_REPORT')) {
            fwrite(STDERR, sprintf("perf: %d chunks, %d model calls, %d s simulated, %d ticks\n", (int) $job->arg('chunk_count'), $job->modelCalls, $this->clock->now() - $start, $ticks));
        }

        $content = \WpStub::$posts[$job->postId]->post_content;
        $this->assertStringContainsString('wp:embed', $content);
        $this->assertStringContainsString('Source: <a href=', $content);
        $transcript = $job->decodedTranscript();
        $this->assertNotNull($transcript);
        $verifier = new QuoteVerifier($transcript->cues);
        preg_match_all('#<!-- wp:quote -->\s*<blockquote class="wp-block-quote">\s*<!-- wp:paragraph -->\s*<p>([^<]+)</p>#', $content, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $q) {
            $this->assertNotFalse($verifier->locate(html_entity_decode($q, ENT_QUOTES)), "quote not in transcript: $q");
        }
        $this->assertStringNotContainsString('never said', $content);
        $this->assertMatchesRegularExpression('/&#038;t=\d+s/', $content);
    }

    public function testFiveMebibyteTranscriptFitsInSixtyFourMebibytes(): void
    {
        $vtt = "WEBVTT\n\n";
        $i = 0;
        while (strlen($vtt) < 5 * 1024 * 1024 - 200) {
            $vtt .= sprintf("%s --> %s\n%s\n\n", self::stamp($i * 2), self::stamp($i * 2 + 1), self::sentence($i));
            $i++;
        }
        $base = memory_get_usage();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $parsed = (new TranscriptReader())->read($vtt);
        unset($vtt);
        $cues = (new TranscriptCleaner())->clean($parsed->cues);
        unset($parsed);
        $chunks = (new Chunker(new TokenEstimator(), 8000))->split($cues);
        $this->assertGreaterThan(100, count($chunks));
        $used = memory_get_peak_usage() - $base;
        $this->assertLessThan(64 * 1024 * 1024, $used, sprintf('peak %.1f MiB', $used / 1048576));
        if (getenv('YT_TO_POST_PERF_REPORT')) {
            fwrite(STDERR, sprintf("perf: 5 MiB transcript peak %.1f MiB, %d chunks\n", $used / 1048576, count($chunks)));
        }
    }
}
