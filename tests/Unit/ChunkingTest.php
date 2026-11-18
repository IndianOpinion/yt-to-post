<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Unit;

use IndianOpinion\YtToPost\Chunking\Chunker;
use IndianOpinion\YtToPost\Chunking\TokenEstimator;
use IndianOpinion\YtToPost\Exception\ModelCallException;
use IndianOpinion\YtToPost\Jobs\JobState;
use IndianOpinion\YtToPost\Model\ModelRequest;
use IndianOpinion\YtToPost\Tests\TestCase;
use IndianOpinion\YtToPost\Transcript\Cue;

/** PRD 4.7: chunking long transcripts (T47 to T54). */
final class ChunkingTest extends TestCase
{
    private function chunked(int $single = 600, int $chunk = 500): void
    {
        $this->settings(['single_pass_tokens' => $single, 'chunk_tokens' => $chunk]);
    }

    public function testT47EstimatorDefaultRatioFilterAndOverride(): void
    {
        $this->assertSame(4, (new TokenEstimator())->estimate('abcdefghij'));
        $this->assertSame(2, (new TokenEstimator())->estimate('नम'), 'six bytes of Devanagari');
        $this->assertSame(4, TokenEstimator::forModel('any')->estimate('abcdefghij'));
        add_filter('yt_to_post_bytes_per_token', static fn (float $r, string $model) => $model === 'big/model' ? 5.0 : $r, 10, 2);
        $this->assertSame(2, TokenEstimator::forModel('big/model')->estimate('abcdefghij'));
        $this->assertSame(4, TokenEstimator::forModel('other')->estimate('abcdefghij'));
        add_filter('yt_to_post_estimate_tokens', static fn (int $d, string $text) => 7, 10, 2);
        $this->assertSame(7, TokenEstimator::forModel('other')->estimate('abcdefghij'));
    }

    public function testT48SmallTranscriptOneCall(): void
    {
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $this->createJob();
        $job = $this->runAll();
        $this->assertSame(JobState::DONE, $job->state);
        $this->assertSame(['yt-to-post.single'], $this->caller->purposes());
        $this->assertSame(1, $job->modelCalls);
    }

    public function testT49SizesCuesAndGiantCueSplit(): void
    {
        $cues = [];
        for ($i = 0; $i < 60; $i++) {
            $cues[] = new Cue($i * 5.0, $i * 5.0 + 4.0, null, self::sentence($i));
        }
        $est = new TokenEstimator();
        $chunks = (new Chunker($est, 300))->split($cues);
        $all = [];
        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(300, $c->tokens);
            foreach ($c->cues as $cue) {
                $all[] = $cue->text;
            }
        }
        $this->assertSame(array_map(static fn (Cue $c): string => $c->text, $cues), $all, 'cues are never split or lost');

        $giant = implode(' ', array_map([self::class, 'sentence'], range(0, 40))) . ' ' . str_repeat('नमस्ते', 200);
        $chunks = (new Chunker($est, 200))->split([new Cue(null, null, null, $giant)]);
        $this->assertGreaterThan(5, count($chunks));
        $joined = [];
        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(200, $c->tokens);
            foreach ($c->cues as $cue) {
                $this->assertSame(1, preg_match('//u', $cue->text), 'no broken UTF-8');
                $joined[] = $cue->text;
            }
        }
        $this->assertMatchesRegularExpression('/town \d+\.$/', $chunks[0]->cues[0]->text, 'cut at a sentence end');
        $this->assertSame((string) preg_replace('/\s+/', '', $giant), (string) preg_replace('/\s+/', '', implode('', $joined)));
    }

    public function testT49GapRulePrefersTheLargestGapNearTheEnd(): void
    {
        $cues = [];
        $t = 0.0;
        for ($i = 0; $i < 20; $i++) {
            $gap = $i === 17 ? 10.0 : 0.1;
            $cues[] = new Cue($t, $t + 1.0, null, str_repeat('x', 27));
            $t += 1.0 + $gap;
        }
        // Each cue costs 10 tokens; the chunk holds 19. The last 10 per cent
        // starts at 171 tokens, so the cut can go after cue 17 or 18; the
        // gap after cue 17 (index) is the largest.
        $chunks = (new Chunker(new TokenEstimator(), 190))->split($cues);
        $this->assertCount(18, $chunks[0]->cues);
    }

    public function testT50ChunksCarryTimesAndChapterTitles(): void
    {
        $cues = [];
        for ($i = 0; $i < 40; $i++) {
            $cues[] = new Cue($i * 10.0, $i * 10.0 + 9.0, null, self::sentence($i));
        }
        $chapters = [new \IndianOpinion\YtToPost\Video\Chapter(0, 'Alpha'), new \IndianOpinion\YtToPost\Video\Chapter(100, 'Beta'), new \IndianOpinion\YtToPost\Video\Chapter(200, 'Gamma')];
        $chunks = (new Chunker(new TokenEstimator(), 400))->split($cues, $chapters);
        $this->assertSame(0.0, $chunks[0]->start);
        $this->assertNotNull($chunks[0]->end);
        $this->assertContains('Alpha', $chunks[0]->chapterTitles);
        $last = end($chunks);
        $this->assertContains('Gamma', $last->chapterTitles);
        $this->assertSame(399.0, $last->end);
    }

    public function testT51NotesSavedAfterEachCall(): void
    {
        $this->chunked();
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $p = $this->runner()->pipeline();
        $job = $this->createJob();
        while ($job->state !== JobState::MAP) {
            $job = $p->advance($job);
        }
        $count = (int) $job->arg('chunk_count');
        $this->assertGreaterThan(1, $count);
        $job = $p->advance($job);
        $this->assertCount(1, $this->repo->find($job->id)?->notes ?? []);
        $job = $p->advance($job);
        $this->assertCount(2, $this->repo->find($job->id)?->notes ?? []);
    }

    public function testT52ReduceUsesNotesAndGroupsLargeNotes(): void
    {
        $this->chunked();
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $this->createJob();
        $this->assertSame(JobState::DONE, $this->runAll()->state);
        $reduce = array_values(array_filter($this->caller->requests, static fn (ModelRequest $r): bool => $r->purpose === 'yt-to-post.reduce'));
        $this->assertCount(1, $reduce);
        $this->assertStringNotContainsString(self::sentence(5), $reduce[0]->input, 'reduce sees notes, not the transcript');
        $this->assertStringContainsString('Part 0', $reduce[0]->input);

        $this->setUp();
        $this->chunked(600, 300);
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')], self::vtt(60));
        $this->answerArticle();
        $big = ['summary' => str_repeat('long note ', 100), 'points' => [], 'quotes' => [], 'names' => []];
        $this->caller->on('yt-to-post.map', static fn (): array => $big);
        $data = self::article();
        $merges = 0;
        $this->caller->on('yt-to-post.reduce', static function (ModelRequest $r) use ($data, $big, &$merges): array {
            if ($r->schemaName === 'map_notes') {
                $merges++;
                return $big;
            }
            return $data;
        });
        $this->createJob();
        $job = $this->runAll();
        $this->assertSame(JobState::DONE, $job->state);
        $this->assertGreaterThan(0, $merges);
        $levels = 0;
        foreach ($this->caller->requests as $r) {
            if ($r->purpose === 'yt-to-post.reduce' && $r->schemaName === 'article') {
                $levels++;
            }
        }
        $this->assertSame(1, $levels, 'one final article call');
        $this->assertLessThanOrEqual(2, count((array) $job->arg('reduce_levels')), 'at most two grouping levels plus the final one');
    }

    public function testT53TooManyChunksRefusedUnlessOverridden(): void
    {
        $this->chunked(600, 500);
        $this->settings(['max_chunks' => 2]);
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $this->createJob();
        $job = $this->runAll();
        $this->assertSame(JobState::FAILED, $job->state);
        $this->assertMatchesRegularExpression('/needs \d+ chunks, over the limit of 2 \(about \d+ model calls\)/', $job->lastError);
        $this->assertSame([], $this->caller->requests);

        $this->createJob(self::VID, ['override_limits' => true]);
        $this->assertSame(JobState::DONE, $this->runAll()->state);
    }

    public function testT54RetryCallsOnlyMissingChunks(): void
    {
        $this->chunked();
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $calls = 0;
        $this->caller->on('yt-to-post.map', static function () use (&$calls) {
            $calls++;
            return $calls === 2 ? new ModelCallException('timeout') : self::notes($calls);
        });
        $job = $this->createJob();
        $this->runAll();
        $mid = $this->job($job->id);
        $this->assertSame(JobState::MAP, $mid->state);
        $this->assertSame(1, $mid->attempts);
        $this->assertCount(1, $mid->notes);
        $chunks = (int) $mid->arg('chunk_count');

        $this->clock->advance(120);
        $done = $this->runAll();
        $this->assertSame(JobState::DONE, $done->state);
        $maps = count(array_filter($this->caller->purposes(), static fn (string $p): bool => $p === 'yt-to-post.map'));
        $this->assertSame($chunks + 1, $maps, 'each chunk paid once, plus the one failed attempt');
    }
}
