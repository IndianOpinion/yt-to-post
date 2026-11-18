<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Unit;

use IndianOpinion\YtToPost\Http\HttpResponse;
use IndianOpinion\YtToPost\Jobs\Job;
use IndianOpinion\YtToPost\Jobs\JobState;
use IndianOpinion\YtToPost\Tests\Support\FakeHttp;
use IndianOpinion\YtToPost\Tests\TestCase;
use IndianOpinion\YtToPost\Transcript\Transcript;
use IndianOpinion\YtToPost\YouTube\CaptionTrack;
use IndianOpinion\YtToPost\YouTube\TrackSelector;

/** PRD 4.4: caption tracks (T25 to T34). */
final class CaptionsTest extends TestCase
{
    private function toNormalise(array $args = []): Job
    {
        $p = $this->runner()->pipeline();
        $job = $p->advance($this->createJob(self::VID, $args));
        return $job->state === JobState::CAPTIONS ? $p->advance($job) : $job;
    }

    public function testT25NoCaptionCallsWithApiKeyAlone(): void
    {
        $this->settings(['api_key' => 'AIzaKey']);
        $this->routeVideo($this->item());
        $job = $this->toNormalise();
        $this->assertSame(JobState::NEEDS_TRANSCRIPT, $job->state);
        $this->assertSame([], $this->http->urls('/captions'));
        $this->assertSame(1, $this->services->quota()->used());
    }

    public function testT26SelectionOrderAndFilter(): void
    {
        $t = static fn (string $id, string $lang, string $kind = 'standard', array $x = []): CaptionTrack => CaptionTrack::fromApiItem(self::track($id, $lang, $kind, $x));
        $tracks = [
            $t('draft', 'hi', 'standard', ['isDraft' => true]),
            $t('forced', 'hi', 'forced'),
            $t('failed', 'hi', 'standard', ['status' => 'failed']),
            $t('comm', 'hi', 'standard', ['audioTrackType' => 'commentary']),
            $t('desc', 'hi', 'standard', ['audioTrackType' => 'descriptive']),
            $t('asr-hi', 'hi', 'ASR'),
            $t('std-en', 'en'),
            $t('std-hi-old', 'hi', 'standard', ['audioTrackType' => 'unknown', 'lastUpdated' => '2025-01-01T00:00:00Z']),
            $t('std-hi-new', 'hi', 'standard', ['audioTrackType' => 'unknown', 'lastUpdated' => '2026-01-01T00:00:00Z']),
            $t('std-hi-primary', 'hi', 'standard', ['lastUpdated' => '2024-01-01T00:00:00Z']),
        ];
        $ranked = array_map(static fn (CaptionTrack $c): string => $c->id, TrackSelector::rank($tracks, 'hi', 'en', ''));
        $this->assertSame(['std-hi-primary', 'std-hi-new', 'std-hi-old', 'asr-hi', 'std-en'], $ranked);
        $this->assertSame('std-en', TrackSelector::choose($tracks, '', 'en', 'hi')?->id, 'audio language before default language');
        $this->assertSame('std-hi-primary', TrackSelector::choose($tracks, '', 'fr', 'hi')?->id);

        add_filter('yt_to_post_select_track', static fn ($choice, array $c) => $c[3], 10, 2);
        $this->assertSame('asr-hi', TrackSelector::choose($tracks, 'hi', 'en', '')?->id);
    }

    public function testT27LanguageMatching(): void
    {
        $tracks = [
            CaptionTrack::fromApiItem(self::track('pt', 'pt')),
            CaptionTrack::fromApiItem(self::track('ptbr', 'pt-BR')),
            CaptionTrack::fromApiItem(self::track('hant', 'zh-Hant')),
        ];
        $this->assertSame('ptbr', TrackSelector::choose($tracks, 'PT-br', '', '')?->id);
        $this->assertSame('pt', TrackSelector::choose($tracks, 'pt', '', '')?->id);
        $this->assertSame('hant', TrackSelector::choose($tracks, 'zh-Hans', '', '')?->id, 'same primary subtag still matches');
        $this->assertSame(1, \IndianOpinion\YtToPost\Language::matchRank('zh-Hans', 'zh-Hant'));
    }

    public function testT28FormatFallbackNeverScc(): void
    {
        $this->connect();
        $this->routeVideo($this->item());
        $this->http->on('#/youtube/v3/captions\?#', FakeHttp::json(['items' => [self::track('t1', 'en')]]));
        $this->http->on('#captions/t1\?tfmt=vtt#', FakeHttp::apiError(400, 'couldNotConvert'));
        $this->http->on('#captions/t1\?tfmt=srt#', FakeHttp::apiError(400, 'couldNotConvert'));
        $srt = "1\n00:00:01,000 --> 00:00:04,000\n" . str_repeat(self::sentence(1) . ' ', 30) . "\n";
        $this->http->on('#captions/t1$#', new HttpResponse(200, $srt));
        $job = $this->toNormalise();
        $this->assertSame(JobState::NORMALISE, $job->state);
        $urls = $this->http->urls('captions/');
        $this->assertCount(3, $urls);
        $this->assertStringEndsWith('tfmt=vtt', $urls[0]);
        $this->assertStringEndsWith('tfmt=srt', $urls[1]);
        $this->assertStringEndsWith('captions/t1', $urls[2]);
        foreach ($this->http->urls() as $u) {
            $this->assertStringNotContainsString('scc', $u);
        }
        $job = $this->runner()->pipeline()->advance($job);
        $this->assertSame('srt', $job->arg('format'), 'format detected from the body');
    }

    public function testT29ForbiddenMovesToNeedsTranscript(): void
    {
        $this->connect();
        $this->routeVideo($this->item(['channelId' => self::OTHER]));
        $this->http->on('#/youtube/v3/captions\?#', FakeHttp::json(['items' => [self::track('t1', 'en')]]));
        $this->http->on('#captions/t1#', FakeHttp::apiError(403, 'forbidden'));
        $job = $this->toNormalise();
        $this->assertSame(JobState::NEEDS_TRANSCRIPT, $job->state);
        $this->assertSame('Captions for this video can only be downloaded by its owner. Supply a transcript to continue.', $job->lastError);
        $this->assertCount(1, $this->http->urls('captions/'), 'no retry');
        $this->assertSame(1, did_action('yt_to_post_needs_transcript'));
    }

    public function testT30NotFoundRelistsOnceThenNeedsTranscript(): void
    {
        $this->connect();
        $this->routeVideo($this->item());
        $this->http->on('#/youtube/v3/captions\?#', FakeHttp::json(['items' => [self::track('t1', 'en'), self::track('t2', 'en', 'ASR')]]));
        $this->http->on('#captions/t1#', FakeHttp::apiError(404, 'captionNotFound'));
        $this->http->on('#captions/t2#', new HttpResponse(200, self::vtt()));
        $job = $this->toNormalise();
        $this->assertSame(JobState::NORMALISE, $job->state);
        $this->assertCount(2, $this->http->urls('captions?'), 'listed once more');
        $this->assertSame(Transcript::KIND_CAPTIONS_ASR, $job->sourceKind);

        $this->setUp();
        $this->connect();
        $this->routeVideo($this->item());
        $this->http->on('#/youtube/v3/captions\?#', FakeHttp::json(['items' => [self::track('t1', 'en'), self::track('t2', 'en')]]));
        $this->http->on('#captions/t#', FakeHttp::apiError(404, 'captionNotFound'));
        $job = $this->toNormalise();
        $this->assertSame(JobState::NEEDS_TRANSCRIPT, $job->state);
        $this->assertCount(2, $this->http->urls('captions?'));
    }

    public function testT31NoCandidatesNoDownload(): void
    {
        $this->connect();
        $this->routeVideo($this->item([], [], ['caption' => 'false']));
        $this->http->on('#/youtube/v3/captions\?#', FakeHttp::json(['items' => []]));
        $job = $this->toNormalise();
        $this->assertSame(JobState::NEEDS_TRANSCRIPT, $job->state);
        $this->assertStringContainsString('no captions', $job->lastError);
        $this->assertSame([], $this->http->urls('captions/'));
        $this->assertSame(51, $this->services->quota()->used(), 'no 200 units spent on a download');
    }

    public function testT32NoYoutubeMachineTranslation(): void
    {
        $this->connect();
        $this->settings(['article_language' => 'fr']);
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle(['language' => 'fr', 'title' => 'Comment la rivière nourrit les fermes', 'sections' => [['heading' => 'La rivière', 'paragraphs' => ['Le fleuve est décrit.'], 'start_seconds' => 0]]]);
        $this->createJob();
        $this->runAll();
        foreach ($this->http->urls() as $u) {
            $this->assertStringNotContainsString('tlang', $u);
        }
        $this->assertStringContainsString('translation', $this->caller->requests[0]->system, 'the model translates instead');
    }

    public function testT33AsrMarkedInNotesAndPrompt(): void
    {
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en', 'ASR')]);
        $this->answerArticle();
        $this->createJob();
        $job = $this->runAll();
        $this->assertSame(JobState::DONE, $job->state);
        $this->assertSame(Transcript::KIND_CAPTIONS_ASR, $job->sourceKind);
        $this->assertStringContainsString('automatic speech recognition', $this->caller->requests[0]->system);
        $notes = json_decode((string) get_post_meta($job->postId, '_yt_to_post_notes', true), true);
        $this->assertSame('ASR', $notes['track_kind']);
        $this->assertStringContainsString('automatic captions', implode(' ', $notes['warnings']));
    }

    public function testT34AsrOnlyProceedsForcedOnlyNeedsTranscript(): void
    {
        $this->connect();
        $this->routeVideo($this->item(['defaultAudioLanguage' => 'ta']));
        $this->routeCaptions([self::track('t1', 'ta', 'ASR')]);
        $this->assertSame(JobState::NORMALISE, $this->toNormalise()->state);

        $this->setUp();
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en', 'forced')]);
        $job = $this->toNormalise();
        $this->assertSame(JobState::NEEDS_TRANSCRIPT, $job->state);
        $this->assertSame([], $this->http->urls('captions/'));
    }
}
