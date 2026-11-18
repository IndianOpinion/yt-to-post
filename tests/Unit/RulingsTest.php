<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Unit;

use IndianOpinion\YtToPost\Draft\DraftWriter;
use IndianOpinion\YtToPost\Exception\InvalidVideoException;
use IndianOpinion\YtToPost\Jobs\Housekeeping;
use IndianOpinion\YtToPost\Jobs\JobState;
use IndianOpinion\YtToPost\Tests\Support\BlockGrammar;
use IndianOpinion\YtToPost\Tests\Support\FakeHttp;
use IndianOpinion\YtToPost\Tests\TestCase;

/** The owner's rulings on the PRD's open questions (ARCHITECTURE.md, D1 to D9). */
final class RulingsTest extends TestCase
{
    public function testD1WithoutGoogleTitleAndChannelComeFromOEmbed(): void
    {
        $this->answerArticle();
        $this->createJob(self::VID, ['transcript' => self::vtt()]);
        $job = $this->runAll();
        $this->assertSame(JobState::DONE, $job->state);
        $this->assertCount(1, $this->http->urls('youtube.com/oembed'));
        $this->assertSame([], $this->http->urls('googleapis.com'));
        $c = \WpStub::$posts[$job->postId]->post_content;
        $this->assertStringContainsString('>oEmbed title</a> by <a href="https://www.youtube.com/@somechannel">Some Channel</a>, on YouTube.', $c);

        $this->setUp();
        $this->http->on('#youtube\.com/oembed#', new \IndianOpinion\YtToPost\Http\HttpResponse(401, 'Unauthorized'));
        $this->createJob(self::VID, ['transcript' => self::vtt()]);
        $job = $this->runAll();
        $this->assertSame(JobState::FAILED, $job->state);
        $this->assertStringContainsString('Connect a YouTube account or add an API key', $job->lastError);
    }

    public function testD2OtherChannelsNeedTheRightsTick(): void
    {
        $this->connect();
        $this->routeVideo($this->item(['channelId' => self::OTHER]));
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $o = $this->jobs->create(self::VID, [], 2, false);
        $this->runAll();
        $job = $this->job($o->jobId());
        $this->assertSame(JobState::NEEDS_RIGHTS, $job->state);
        $this->assertSame([], $this->http->urls('/captions'), 'nothing else is fetched first');
        $this->assertTrue(yt_to_post_confirm_rights($job->id));
        $done = $this->runAll();
        $this->assertSame(JobState::DONE, $done->state);
        $this->assertSame(1, $done->arg('rights_user'));
        $notes = json_decode((string) get_post_meta($done->postId, DraftWriter::META_NOTES, true), true);
        $this->assertTrue($notes['rights_confirmed']);

        $this->setUp();
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $this->jobs->create(self::VID, [], 1, true);
        $this->assertSame(JobState::DONE, $this->runAll()->state, 'own channel needs no tick');
    }

    public function testD3PrivacyEnhancedPlayerIsOptIn(): void
    {
        $this->connect();
        $this->routeVideo($this->item(['title' => 'A "quoted" title']));
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $this->settings(['privacy_embed' => true]);
        $this->createJob();
        $job = $this->runAll();
        $c = \WpStub::$posts[$job->postId]->post_content;
        $this->assertStringContainsString('src="https://www.youtube-nocookie.com/embed/' . self::VID . '"', $c);
        $this->assertStringContainsString('title="A &quot;quoted&quot; title"', $c);
        $this->assertSame('html', BlockGrammar::parse($c)[0]);
        $this->assertArrayHasKey('iframe', \WpStub::$posts[$job->postId]->ksesTags, 'the iframe survives kses when the runner has no user');
        $this->assertSame([], apply_filters('wp_kses_allowed_html', [], 'post'), 'only for that one insert');
        $this->assertFalse((bool) \IndianOpinion\YtToPost\Settings::DEFAULTS['privacy_embed']);
    }

    public function testD4ApiDataPurgedOrRefreshedOnlyBySetting(): void
    {
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $this->createJob();
        $job = $this->runAll();
        $hk = new Housekeeping($this->services);

        $this->clock->advance(29 * 86400);
        $hk->run();
        $this->assertNotNull($this->job($job->id)->metadata, 'nothing purged before the setting says so');

        $this->clock->advance(2 * 86400);
        $this->assertSame(1, $hk->run(true)['metadata_purged'], 'dry run counts');
        $this->assertNotNull($this->job($job->id)->metadata);
        $stats = $hk->run();
        $this->assertSame(1, $stats['metadata_purged']);
        $after = $this->job($job->id);
        $this->assertNull($after->metadata);
        $this->assertStringContainsString('purged under the API data setting (30 days)', implode("\n", $after->log));
        $this->assertNull($after->transcript, 'working data cleared after retention_days');

        $this->setUp();
        $this->connect();
        $this->settings(['api_data_on_expiry' => 'refresh', 'api_data_days' => 10]);
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $this->createJob();
        $job = $this->runAll();
        $this->clock->advance(11 * 86400);
        $this->routeVideo($this->item(['title' => 'Renamed']));
        $this->http->on('#oauth2\.googleapis\.com/token#', FakeHttp::json(['access_token' => 'a2', 'expires_in' => 3600]));
        $stats = (new Housekeeping($this->services))->run();
        $this->assertSame(1, $stats['metadata_refreshed']);
        $this->assertSame('Renamed', $this->job($job->id)->metadata['title'] ?? null);

        $this->http->on('#/youtube/v3/videos#', FakeHttp::apiError(500, 'backendError'));
        $this->clock->advance(11 * 86400);
        $stats = (new Housekeeping($this->services))->run();
        $this->assertSame(0, $stats['metadata_purged'], 'a failed refresh never purges silently');
        $this->assertNotNull($this->job($job->id)->metadata);
    }

    public function testD5TranscriptStaysOnDraftUntilTheSettingRemovesIt(): void
    {
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $this->createJob();
        $job = $this->runAll();
        $text = (string) get_post_meta($job->postId, DraftWriter::META_TRANSCRIPT, true);
        $this->assertStringContainsString('[0:15] ' . self::sentence(3), $text);

        $this->clock->advance(400 * 86400);
        (new Housekeeping($this->services))->run();
        $this->assertNotSame('', get_post_meta($job->postId, DraftWriter::META_TRANSCRIPT, true), 'default 0 keeps it');

        $this->settings(['draft_transcript_days' => 30]);
        $this->assertSame(1, (new Housekeeping($this->services))->run()['draft_transcripts_removed']);
        $this->assertSame('', get_post_meta($job->postId, DraftWriter::META_TRANSCRIPT, true));
        $this->assertSame(self::VID, get_post_meta($job->postId, DraftWriter::META_VIDEO_ID, true), 'only the transcript goes');
    }

    public function testD6PlaylistsAreOptInOneJobPerVideo(): void
    {
        $this->connect();
        $url = 'https://www.youtube.com/playlist?list=PLabcdefgh';
        try {
            $this->jobs->createFromPlaylist($url, [], 1, true);
            $this->fail('off by default');
        } catch (InvalidVideoException $e) {
            $this->assertStringContainsString('playlists are not enabled', $e->getMessage());
        }
        $this->settings(['allow_playlists' => true, 'max_playlist_items' => 2]);
        $this->http->on('#/youtube/v3/playlistItems#', FakeHttp::json(['items' => [
            ['contentDetails' => ['videoId' => 'aaaaaaaaaaa']],
            ['contentDetails' => ['videoId' => 'bbbbbbbbbbb']],
            ['contentDetails' => ['videoId' => 'ccccccccccc']],
        ]]));
        $out = $this->jobs->createFromPlaylist($url, ['rights_confirmed' => true], 1, true);
        $this->assertCount(2, $out);
        $this->assertStringContainsString('maxResults=2', $this->http->urls('playlistItems')[0]);
        $this->assertSame(1, $this->services->quota()->used());
        $this->assertSame(['bbbbbbbbbbb', 'aaaaaaaaaaa'], array_map(static fn ($j) => $j->videoId, $this->repo->list()));

        $this->settings(['max_videos_per_day' => 1]);
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $this->runner()->run();
        $states = array_map(static fn ($j) => $j->state, $this->repo->list());
        sort($states);
        $this->assertSame([JobState::DONE, JobState::HELD_CAP], $states, 'the daily cap applies per video');
    }

    public function testD7PerModelRatioFilter(): void
    {
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $this->settings(['single_pass_tokens' => 2000, 'chunk_tokens' => 1500]);
        $this->createJob();
        $this->assertSame(1, (int) $this->runAll()->arg('chunk_count'), 'the default ratio fits in one pass');
        add_filter('yt_to_post_bytes_per_token', static fn (float $r, string $model) => $model === 'test/model' ? 0.5 : $r, 10, 2);
        $this->routeVideo($this->item([], [], [], 'bbbbbbbbbbb'));
        $this->createJob('bbbbbbbbbbb');
        $job = $this->runAll();
        $this->assertGreaterThan(1, (int) $job->arg('chunk_count'), 'a denser ratio for this model makes the same text chunked');
    }

    public function testD9ThumbnailNeverOfferedWithoutRights(): void
    {
        $this->connect();
        $this->routeVideo($this->item(['channelId' => self::OTHER]));
        $o = $this->jobs->create(self::VID, [], 1, true);
        $this->runAll();
        $this->assertSame(JobState::NEEDS_RIGHTS, $this->job($o->jobId())->state);
        $this->assertSame([], \WpStub::$posts);
    }
}
