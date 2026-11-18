<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests;

use IndianOpinion\YtToPost\Jobs\Job;
use IndianOpinion\YtToPost\Jobs\JobService;
use IndianOpinion\YtToPost\Jobs\MemoryJobRepository;
use IndianOpinion\YtToPost\Jobs\Runner;
use IndianOpinion\YtToPost\Model\ModelRequest;
use IndianOpinion\YtToPost\Plugin;
use IndianOpinion\YtToPost\Services;
use IndianOpinion\YtToPost\Settings;
use IndianOpinion\YtToPost\Tests\Support\FakeCaller;
use IndianOpinion\YtToPost\Tests\Support\FakeClock;
use IndianOpinion\YtToPost\Tests\Support\FakeHttp;
use IndianOpinion\YtToPost\Tests\Support\RecordingSleeper;
use IndianOpinion\YtToPost\YouTube\AccessTokens;
use IndianOpinion\YtToPost\YouTube\Connection;
use IndianOpinion\YtToPost\YouTube\TokenVault;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public const VID = 'dQw4w9WgXcQ';
    public const OWN = 'UCownChannel000000000000';
    public const OTHER = 'UCotherChannel0000000000';

    protected FakeClock $clock;
    protected FakeHttp $http;
    protected RecordingSleeper $sleeper;
    protected FakeCaller $caller;
    protected MemoryJobRepository $repo;
    protected Services $services;
    protected JobService $jobs;
    protected TokenVault $vault;
    /** @var list<array{0:int,1:string,2:int}> */
    protected array $scheduled = [];

    protected function setUp(): void
    {
        \WpStub::reset();
        $this->clock = new FakeClock();
        $this->http = new FakeHttp();
        $this->sleeper = new RecordingSleeper($this->clock);
        $this->caller = new FakeCaller();
        $this->repo = new MemoryJobRepository();
        $this->vault = new TokenVault(static fn (): string => (string) get_option('_test_salt', 'test-salt-one'));
        $this->services = new Services($this->repo, $this->clock, $this->http, $this->sleeper, $this->vault, $this->caller);
        Plugin::setServices($this->services);
        $this->scheduled = [];
        $this->jobs = new JobService($this->services, function (int $id, string $v, int $f): void {
            $this->scheduled[] = [$id, $v, $f];
        });
        $this->settings(['model_ids' => 'test/model']);
        $this->http->on('#^https://www\.youtube\.com/oembed#', FakeHttp::json(['title' => 'oEmbed title', 'author_name' => 'Some Channel', 'author_url' => 'https://www.youtube.com/@somechannel', 'thumbnail_url' => 'https://i.ytimg.com/vi/' . self::VID . '/hqdefault.jpg']));
    }

    protected function tearDown(): void
    {
        Plugin::setServices(null);
    }

    /** @param array<string,mixed> $values */
    protected function settings(array $values): void
    {
        $current = get_option(Settings::OPTION, []);
        update_option(Settings::OPTION, array_merge(is_array($current) ? $current : [], $values));
    }

    protected function connect(string $channel = self::OWN): void
    {
        $this->settings(['client_id' => 'cid', 'client_secret' => 'csecret']);
        Connection::save([
            'state' => Connection::CONNECTED,
            'token' => $this->vault->encrypt('refresh-token-1'),
            'channel_id' => $channel,
            'channel_title' => 'Own Channel',
            'connected_at' => $this->clock->now(),
        ]);
        set_transient(AccessTokens::TRANSIENT, 'access-1', 3000);
    }

    /**
     * @param array<string,mixed> $snippet
     * @param array<string,mixed> $status
     * @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    protected function item(array $snippet = [], array $status = [], array $content = [], string $id = self::VID): array
    {
        return [
            'id' => $id,
            'snippet' => array_merge([
                'title' => 'A talk about rivers',
                'channelId' => self::OWN,
                'channelTitle' => 'Own Channel',
                'publishedAt' => '2026-11-01T10:00:00Z',
                'description' => 'A talk.',
                'defaultAudioLanguage' => 'en',
                'liveBroadcastContent' => 'none',
                'thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg']],
                'tags' => ['not', 'kept'],
            ], $snippet),
            'contentDetails' => array_merge(['duration' => 'PT30M', 'caption' => 'true'], $content),
            'status' => array_merge(['uploadStatus' => 'processed', 'privacyStatus' => 'public', 'embeddable' => true], $status),
            'statistics' => ['viewCount' => '123'],
        ];
    }

    /** @param array<string,mixed>|null $item */
    protected function routeVideo(?array $item = null): void
    {
        $this->http->on('#/youtube/v3/videos\?#', FakeHttp::json(['items' => $item === null ? [] : [$item]]));
    }

    /**
     * @param list<array<string,mixed>> $tracks
     */
    protected function routeCaptions(array $tracks, string $body = ''): void
    {
        $this->http->on('#/youtube/v3/captions\?#', FakeHttp::json(['items' => $tracks]));
        $this->http->on('#/youtube/v3/captions/#', new \IndianOpinion\YtToPost\Http\HttpResponse(200, $body !== '' ? $body : self::vtt()));
    }

    /** @return array<string,mixed> */
    protected static function track(string $id, string $lang, string $kind = 'standard', array $extra = []): array
    {
        return ['id' => $id, 'snippet' => array_merge([
            'language' => $lang, 'trackKind' => $kind, 'status' => 'serving', 'isDraft' => false,
            'audioTrackType' => 'primary', 'lastUpdated' => '2026-11-01T00:00:00Z',
        ], $extra)];
    }

    public static function sentence(int $i): string
    {
        return sprintf('Point number %d explains how the river carries water to the farms near town %d.', $i, $i);
    }

    /** A WebVTT transcript with one cue every five seconds. */
    public static function vtt(int $cues = 40, int $step = 5): string
    {
        $out = "WEBVTT\n\n";
        for ($i = 0; $i < $cues; $i++) {
            $out .= sprintf("%s --> %s\n%s\n\n", self::stamp($i * $step), self::stamp($i * $step + $step - 1), self::sentence($i));
        }
        return $out;
    }

    public static function stamp(int $s): string
    {
        return sprintf('%02d:%02d:%02d.000', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    }

    /**
     * A valid article answer. Quotes default to real transcript sentences.
     *
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    public static function article(array $over = []): array
    {
        return array_merge([
            'language' => 'en-GB',
            'source_language' => 'en',
            'title' => 'How the river feeds the farms',
            'standfirst' => 'A talk on water and farming.',
            'sections' => [
                ['heading' => 'The river', 'paragraphs' => ['The speaker describes the river.'], 'start_seconds' => 0],
                ['heading' => 'The farms', 'paragraphs' => ['Farms depend on it.'], 'start_seconds' => 60],
            ],
            'key_points' => ['Water matters'],
            'quotes' => [
                ['text' => self::sentence(3), 'translation' => null, 'speaker' => 'Speaker', 'start_seconds' => 999],
            ],
            'topics' => ['rivers', 'farming'],
        ], $over);
    }

    protected function answerArticle(array $over = []): void
    {
        $data = self::article($over);
        $this->caller->on('yt-to-post.single', static fn (ModelRequest $r): array => $data);
        $this->caller->on('yt-to-post.reduce', static fn (ModelRequest $r): array => $r->schemaName === 'article' ? $data : self::notes(0));
        $this->caller->on('yt-to-post.map', static fn (ModelRequest $r): array => self::notes(0));
    }

    /** @return array<string,mixed> */
    public static function notes(int $i): array
    {
        return ['summary' => 'Part ' . $i, 'points' => [['text' => 'A point', 'start_seconds' => null]], 'quotes' => [], 'names' => []];
    }

    protected function runner(): Runner
    {
        return new Runner($this->services);
    }

    protected function runAll(int $rounds = 30): Job
    {
        $last = null;
        for ($i = 0; $i < $rounds; $i++) {
            $sum = $this->runner()->run();
            if ($sum->claimed === 0) {
                break;
            }
        }
        $all = $this->repo->list([], 1);
        $last = $all[0] ?? null;
        $this->assertNotNull($last);
        return $last;
    }

    /** @param array<string,mixed> $args */
    protected function createJob(string $video = self::VID, array $args = [], int $user = 1): Job
    {
        $o = $this->jobs->create($video, $args + ['rights_confirmed' => true], $user, true);
        $this->assertNotNull($o->job);
        return $o->job;
    }

    protected function job(int $id): Job
    {
        $j = $this->repo->find($id);
        $this->assertNotNull($j);
        return $j;
    }
}
