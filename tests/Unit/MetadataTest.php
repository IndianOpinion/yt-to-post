<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Unit;

use IndianOpinion\YtToPost\Exception\ConfigurationException;
use IndianOpinion\YtToPost\Jobs\JobState;
use IndianOpinion\YtToPost\Tests\TestCase;
use IndianOpinion\YtToPost\Video\DurationParser;
use IndianOpinion\YtToPost\YouTube\Connection;

/** PRD 4.2: metadata (T7 to T17). */
final class MetadataTest extends TestCase
{
    private function runMetadata(array $args = []): \IndianOpinion\YtToPost\Jobs\Job
    {
        $job = $this->jobs->create(self::VID, $args + ['rights_confirmed' => true], 1, true)->job;
        $this->assertNotNull($job);
        return $this->runner()->pipeline()->advance($job);
    }

    public function testT7TokenThenApiKeyThenRefusedBeforeAnyCall(): void
    {
        $this->connect();
        $this->routeVideo($this->item());
        $this->runMetadata();
        $req = $this->http->requests[0];
        $this->assertSame('Bearer access-1', $req['headers']['Authorization']);
        $this->assertStringNotContainsString('key=', $req['url']);
        $this->assertStringContainsString('part=snippet%2CcontentDetails%2Cstatus%2CliveStreamingDetails', $req['url']);

        $this->setUp();
        $this->settings(['api_key' => 'AIzaTestKey']);
        $this->routeVideo($this->item());
        $this->runMetadata();
        $this->assertStringContainsString('key=AIzaTestKey', $this->http->requests[0]['url']);
        $this->assertArrayNotHasKey('Authorization', $this->http->requests[0]['headers']);

        $this->setUp();
        try {
            $this->jobs->create(self::VID, [], 1, true);
            $this->fail('expected refusal');
        } catch (ConfigurationException $e) {
            $this->assertSame('Connect a YouTube account or add an API key', $e->getMessage());
        }
        $this->assertSame([], $this->http->requests);
    }

    public function testT8EmptyItemsFailsWithoutRetry(): void
    {
        $this->connect();
        $this->routeVideo(null);
        $job = $this->runMetadata();
        $this->assertSame(JobState::FAILED, $job->state);
        $this->assertSame('Video not found or not visible to the connected account', $job->lastError);
        $this->assertCount(1, $this->http->requests);
        $this->assertSame([], $this->sleeper->sleeps);
    }

    public function testT9LiveAndUpcomingRefused(): void
    {
        foreach (['live', 'upcoming'] as $state) {
            $this->setUp();
            $this->connect();
            $this->routeVideo($this->item(['liveBroadcastContent' => $state]));
            $job = $this->runMetadata();
            $this->assertSame(JobState::FAILED, $job->state);
            $this->assertSame('Wait until the broadcast has ended', $job->lastError);
        }
    }

    public function testT10UnprocessedRefused(): void
    {
        $this->connect();
        $this->routeVideo($this->item([], ['uploadStatus' => 'uploaded']));
        $job = $this->runMetadata();
        $this->assertSame(JobState::FAILED, $job->state);
        $this->assertSame('Video is still processing on YouTube, try again later', $job->lastError);
    }

    public function testT11PrivateRefusedUnlessOwnAndSettingOn(): void
    {
        $this->connect();
        $this->routeVideo($this->item(['channelId' => self::OTHER], ['privacyStatus' => 'private']));
        $this->assertSame(JobState::FAILED, $this->runMetadata()->state);

        $this->setUp();
        $this->connect();
        $this->routeVideo($this->item([], ['privacyStatus' => 'private']));
        $this->assertSame(JobState::FAILED, $this->runMetadata()->state, 'own private refused while the setting is off');

        $this->setUp();
        $this->connect();
        $this->settings(['allow_private_own' => true]);
        $this->routeVideo($this->item([], ['privacyStatus' => 'private']));
        $this->assertSame(JobState::CAPTIONS, $this->runMetadata()->state);
    }

    public function testT12UnlistedNeedsOwnChannelOrTick(): void
    {
        $this->connect();
        $this->routeVideo($this->item(['channelId' => self::OTHER], ['privacyStatus' => 'unlisted']));
        $job = $this->runMetadata();
        $this->assertSame(JobState::FAILED, $job->state);
        $this->assertStringContainsString('unlisted', $job->lastError);

        $this->setUp();
        $this->connect();
        $this->routeVideo($this->item(['channelId' => self::OTHER], ['privacyStatus' => 'unlisted']));
        $job = $this->runMetadata(['allow_unlisted' => true]);
        $this->assertSame(JobState::CAPTIONS, $job->state);
        $this->assertContains('Video is unlisted on YouTube.', $job->warnings);

        $this->setUp();
        $this->connect();
        $this->routeVideo($this->item([], ['privacyStatus' => 'unlisted']));
        $job = $this->runMetadata();
        $this->assertSame(JobState::CAPTIONS, $job->state);
        $this->assertContains('Video is unlisted on YouTube.', $job->warnings);
    }

    public function testT14WarningsRecordedJobContinues(): void
    {
        $this->connect();
        $this->settings(['site_country' => 'IN']);
        $this->routeVideo($this->item([], ['madeForKids' => true], [
            'contentRating' => ['ytRating' => 'ytAgeRestricted'],
            'regionRestriction' => ['blocked' => ['IN']],
        ]));
        $job = $this->runMetadata();
        $this->assertSame(JobState::CAPTIONS, $job->state);
        $w = implode(' ', $job->warnings);
        $this->assertStringContainsString('age-restricted', $w);
        $this->assertStringContainsString('made for kids', $w);
        $this->assertStringContainsString('blocked in IN', $w);

        $this->setUp();
        $this->connect();
        $this->settings(['site_country' => 'IN']);
        $this->routeVideo($this->item([], [], ['regionRestriction' => ['allowed' => ['US']]]));
        $this->assertStringContainsString('not available in IN', implode(' ', $this->runMetadata()->warnings));
    }

    public function testT15DurationsAndLimit(): void
    {
        $this->assertSame(3723, DurationParser::seconds('PT1H2M3S'));
        $this->assertSame(93600, DurationParser::seconds('P1DT2H'));
        $this->assertNull(DurationParser::seconds('PT0S'));
        $this->assertNull(DurationParser::seconds('P0D'));
        $this->assertNull(DurationParser::seconds('garbage'));
        $this->assertSame(45, DurationParser::seconds('PT45S'));

        $this->connect();
        $this->routeVideo($this->item([], [], ['duration' => 'PT5H']));
        $job = $this->runMetadata();
        $this->assertSame(JobState::FAILED, $job->state);
        $this->assertStringContainsString('over the limit of 240 minutes', $job->lastError);
        $this->assertStringContainsString('model call', $job->lastError, 'the estimate is shown');

        $this->setUp();
        $this->connect();
        $this->routeVideo($this->item([], [], ['duration' => 'PT5H']));
        $this->assertSame(JobState::CAPTIONS, $this->runMetadata(['override_limits' => true])->state);

        $this->setUp();
        $this->connect();
        $this->routeVideo($this->item([], [], ['duration' => 'PT0S']));
        $job = $this->runMetadata();
        $this->assertSame(JobState::CAPTIONS, $job->state);
        $this->assertNull($job->meta()?->duration, 'zero is unknown');
    }

    public function testT16OnlyListedFieldsStored(): void
    {
        $this->connect();
        $this->routeVideo($this->item());
        $job = $this->runMetadata();
        $stored = (string) json_encode($job->metadata);
        $this->assertStringNotContainsString('viewCount', $stored);
        $this->assertStringNotContainsString('"tags"', $stored);
        $this->assertEqualsCanonicalizing([
            'id', 'title', 'channelId', 'channelTitle', 'publishedAt', 'description', 'defaultLanguage', 'defaultAudioLanguage',
            'duration', 'privacyStatus', 'uploadStatus', 'embeddable', 'caption', 'liveBroadcastContent', 'ageRestricted',
            'madeForKids', 'regionAllowed', 'regionBlocked', 'thumbnail', 'source', 'channelUrl',
        ], array_keys($job->metadata ?? []));
    }

    public function testT17OwnershipMatchesConnectedChannel(): void
    {
        $this->connect(self::OWN);
        $this->routeVideo($this->item(['channelId' => self::OWN]));
        $this->assertTrue((bool) $this->runMetadata()->arg('own'));

        $this->setUp();
        $this->connect(self::OWN);
        $this->routeVideo($this->item(['channelId' => self::OTHER]));
        $this->assertFalse((bool) $this->runMetadata()->arg('own'));
        $this->assertSame(self::OWN, Connection::channelId());
    }

    public function testT17ChannelLearnedOnceAtConnect(): void
    {
        $this->settings(['client_id' => 'cid', 'client_secret' => 'sec']);
        $url = $this->services->oauth()->authorizationUrl(1);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->http->on('#oauth2\.googleapis\.com/token#', \IndianOpinion\YtToPost\Tests\Support\FakeHttp::json(['access_token' => 'a1', 'refresh_token' => 'r1', 'expires_in' => 3600]));
        $this->http->on('#/youtube/v3/channels\?#', \IndianOpinion\YtToPost\Tests\Support\FakeHttp::json(['items' => [['id' => self::OWN, 'snippet' => ['title' => 'Own']]]]));
        $this->services->oauth()->handleCallback(['state' => $q['state'], 'code' => 'c'], 1, true, $this->services->youtube());
        $this->assertSame(self::OWN, Connection::channelId());
        $this->assertCount(1, $this->http->urls('channels?'));
        $this->assertStringContainsString('mine=true', $this->http->urls('channels?')[0]);
        $this->assertSame(1, $this->services->quota()->used());
    }
}
