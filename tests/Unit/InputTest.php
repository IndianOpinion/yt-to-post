<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Unit;

use IndianOpinion\YtToPost\Exception\InvalidVideoException;
use IndianOpinion\YtToPost\Jobs\JobState;
use IndianOpinion\YtToPost\Tests\TestCase;
use IndianOpinion\YtToPost\Video\VideoIdParser;
use PHPUnit\Framework\Attributes\DataProvider;

/** PRD 4.1: input and video identity (T1 to T6). */
final class InputTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function forms(): array
    {
        $id = self::VID;
        return [
            'watch' => ["https://www.youtube.com/watch?v=$id"],
            'no www' => ["https://youtube.com/watch?v=$id"],
            'mobile' => ["https://m.youtube.com/watch?v=$id"],
            'music' => ["https://music.youtube.com/watch?v=$id"],
            'other params first' => ["https://www.youtube.com/watch?feature=share&ab_channel=x&v=$id"],
            'short link' => ["https://youtu.be/$id"],
            'short link with si' => ["https://youtu.be/$id?si=abc"],
            'shorts' => ["https://www.youtube.com/shorts/$id"],
            'live' => ["https://www.youtube.com/live/$id?feature=share"],
            'embed' => ["https://www.youtube.com/embed/$id"],
            'v path' => ["https://www.youtube.com/v/$id"],
            'nocookie' => ["https://www.youtube-nocookie.com/embed/$id"],
            'http' => ["http://www.youtube.com/watch?v=$id"],
            'bare id' => [$id],
            'whitespace' => ["  https://youtu.be/$id \n"],
        ];
    }

    #[DataProvider('forms')]
    public function testT1EveryAcceptedFormParsesToTheSameId(string $input): void
    {
        $this->assertSame(self::VID, VideoIdParser::videoId($input));
        $this->assertSame(self::VID, yt_to_post_parse_video_id($input));
    }

    public function testT2TimeIsRecordedAndListIgnored(): void
    {
        $this->assertSame(90, VideoIdParser::parse('https://www.youtube.com/watch?v=' . self::VID . '&t=1m30s')?->startSeconds);
        $this->assertSame(42, VideoIdParser::parse('https://youtu.be/' . self::VID . '?start=42')?->startSeconds);
        $this->assertSame(7, VideoIdParser::parse('https://www.youtube.com/watch?v=' . self::VID . '#t=7')?->startSeconds);
        $ref = VideoIdParser::parse('https://www.youtube.com/watch?v=' . self::VID . '&list=PLabcdefgh');
        $this->assertSame(self::VID, $ref?->videoId);

        $this->connect();
        $this->routeVideo($this->item());
        $job = $this->createJob('https://www.youtube.com/watch?v=' . self::VID . '&list=PLabcdefgh&t=90');
        $this->assertSame(90, $job->arg('start_seconds'));
        $this->assertStringContainsString('Playlist parameter ignored', implode(' ', $job->warnings));
        $this->assertSame([], $this->http->requests, 'creating a job makes no YouTube call');
    }

    /** @return array<string,array{0:string}> */
    public static function bad(): array
    {
        return [
            'look-alike host' => ['https://youtube.com.example.org/watch?v=' . self::VID],
            'suffix host' => ['https://notyoutube.com/watch?v=' . self::VID],
            'channel' => ['https://www.youtube.com/@somechannel'],
            'playlist only' => ['https://www.youtube.com/playlist?list=PLabcdefgh'],
            'short id' => ['abc123'],
            'bad alphabet' => ['dQw4w9WgXc!'],
            'long id' => ['https://youtu.be/dQw4w9WgXcQQ'],
            'javascript' => ['javascript:alert(1)//' . self::VID],
            'data' => ['data:text/html,' . self::VID],
            'over long' => ['https://www.youtube.com/watch?v=' . self::VID . '&x=' . str_repeat('a', 2100)],
            'credentials' => ['https://user:pass@www.youtube.com/watch?v=' . self::VID],
        ];
    }

    #[DataProvider('bad')]
    public function testT3AnythingElseIsRefusedWithoutNetwork(string $input): void
    {
        $this->connect();
        $this->assertNull(VideoIdParser::videoId($input));
        try {
            $this->jobs->create($input, [], 1, true);
            $this->fail('expected refusal');
        } catch (InvalidVideoException $e) {
            $this->assertSame('Not a YouTube video link', $e->getMessage());
        }
        $this->assertSame([], $this->http->requests);
    }

    public function testT4TwoFormsShareTheDedupKey(): void
    {
        $this->connect();
        $a = $this->jobs->create('https://youtu.be/' . self::VID, [], 1, true);
        $b = $this->jobs->create('https://www.youtube.com/shorts/' . self::VID, [], 1, true);
        $this->assertFalse($a->existing);
        $this->assertTrue($b->existing);
        $this->assertSame($a->jobId(), $b->jobId());
        $this->assertSame(self::VID, $a->job?->videoId);
    }

    public function testT5SecondCreateReturnsExistingAndForceMakesASecondDraft(): void
    {
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $first = $this->createJob();
        $done = $this->runAll();
        $this->assertSame(JobState::DONE, $done->state);
        $firstPost = $done->postId;
        \WpStub::$posts[$firstPost]->post_content = 'edited by the editor';

        $again = $this->jobs->create(self::VID, [], 1, true);
        $this->assertTrue($again->existing);
        $this->assertSame($first->id, $again->jobId());
        $this->assertSame($firstPost, $again->postId);

        $forced = $this->jobs->create(self::VID, ['force' => true, 'rights_confirmed' => true], 1, true);
        $this->assertFalse($forced->existing);
        $this->runAll();
        $second = $this->job($forced->jobId());
        $this->assertSame(JobState::DONE, $second->state);
        $this->assertNotSame($firstPost, $second->postId);
        $this->assertSame('edited by the editor', \WpStub::$posts[$firstPost]->post_content);
        $this->assertSame(1, $second->arg('force_seq'));
    }

    public function testT6ConcurrentCreateReturnsTheRunningJob(): void
    {
        $this->connect();
        $job = $this->createJob();
        $this->repo->save($job->with(['state' => JobState::MAP]));
        $o = $this->jobs->create(self::VID, ['force' => true], 1, true);
        $this->assertTrue($o->existing);
        $this->assertSame($job->id, $o->jobId());
        $this->assertTrue(\IndianOpinion\YtToPost\Jobs\SiteLock::video(self::VID, $job->id));
        $this->assertFalse(\IndianOpinion\YtToPost\Jobs\SiteLock::video(self::VID, $job->id + 1), 'a second job for the same id cannot take the lock');
    }
}
