<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Unit;

use IndianOpinion\YtToPost\Exception\InvalidTranscriptException;
use IndianOpinion\YtToPost\Jobs\JobState;
use IndianOpinion\YtToPost\Tests\TestCase;
use IndianOpinion\YtToPost\Transcript\Cue;
use IndianOpinion\YtToPost\Transcript\Parser\TtmlParser;
use IndianOpinion\YtToPost\Transcript\TranscriptCleaner;
use IndianOpinion\YtToPost\Transcript\TranscriptReader;
use IndianOpinion\YtToPost\Transcript\WordCounter;

/** PRD 4.5 and 4.6: supplied transcripts and normalising (T35 to T46). */
final class TranscriptTest extends TestCase
{
    public const VTT = "WEBVTT\nKind: captions\n\nNOTE a comment\nthat spans lines\n\nSTYLE\n::cue { color: red }\n\n1\n00:00:01.000 --> 00:00:03.500 align:start position:0%\n<v Asha>Hello <b>world</b></v>\n\n00:00:04.000 --> 00:00:06.000\nSecond line\n";
    public const SRT = "1\n00:00:01,000 --> 00:00:03,500\nHello world\n\n2\n00:00:04,000 --> 00:00:06,000\nSecond line\n";
    public const SBV = "0:00:01.000,0:00:03.500\nHello world\n\n0:00:04.000,0:00:06.000\nSecond line\n";
    public const TTML = '<?xml version="1.0" encoding="UTF-8"?><tt xmlns="http://www.w3.org/ns/ttml"><body><div>'
        . '<p begin="00:00:01.000" end="00:00:03.500">Hello <span>world</span></p>'
        . '<p begin="4s" dur="2s">Second line</p></div></body></tt>';
    public const STAMPED = "[0:00:01] Hello world\n(0:04) Second line\n";
    public const PLAIN = "Hello world.\n\nSecond line.";

    public function testT35SuppliedTranscriptSkipsCaptionCallsAndSizeLimit(): void
    {
        $this->connect();
        $this->routeVideo($this->item());
        $this->answerArticle();
        $this->createJob(self::VID, ['transcript' => self::vtt()]);
        $job = $this->runAll();
        $this->assertSame(JobState::DONE, $job->state);
        $this->assertSame([], $this->http->urls('/captions'));
        $this->assertSame('transcript', $job->sourceKind);

        $this->settings(['max_transcript_bytes' => 2048]);
        $this->expectException(InvalidTranscriptException::class);
        $this->jobs->create('https://youtu.be/aaaaaaaaaaa', ['transcript' => str_repeat('a ', 2000)], 1, true);
    }

    public function testT36FormatDetectionFromContent(): void
    {
        $reader = new TranscriptReader();
        $this->assertSame('vtt', $reader->read(self::VTT)->format);
        $this->assertSame('srt', $reader->read(self::SRT)->format);
        $this->assertSame('sbv', $reader->read(self::SBV)->format);
        $this->assertSame('ttml', $reader->read(self::TTML)->format);
        $this->assertSame('text-timestamped', $reader->read(self::STAMPED)->format);
        $this->assertSame('text', $reader->read(self::PLAIN)->format);
        try {
            $reader->read("Scenarist_SCC V1.0\n\n00:00:00:00\t9420 9420");
            $this->fail('SCC must be refused');
        } catch (InvalidTranscriptException $e) {
            $this->assertSame('SCC is not supported, export WebVTT or SRT', $e->getMessage());
        }
    }

    public function testT37Encodings(): void
    {
        $reader = new TranscriptReader();
        $this->assertSame('srt', $reader->read("\xEF\xBB\xBF" . self::SRT)->format);
        $utf16 = "\xFF\xFE" . mb_convert_encoding(self::SRT . "caf\u{e9}\n", 'UTF-16LE', 'UTF-8');
        $r = $reader->read($utf16);
        $this->assertSame('Hello world', $r->cues[0]->text);
        $utf16be = "\xFE\xFF" . mb_convert_encoding(self::SRT, 'UTF-16BE', 'UTF-8');
        $this->assertSame('srt', $reader->read($utf16be)->format);
        try {
            $reader->read("Hello\xC3\x28 world");
            $this->fail('invalid UTF-8 must be refused');
        } catch (InvalidTranscriptException $e) {
            $this->assertStringContainsString('offset 5', $e->getMessage());
        }
        try {
            $reader->read("abc\xE9t\xE9");
            $this->fail('Latin-1 must not be guessed');
        } catch (InvalidTranscriptException $e) {
            $this->assertStringContainsString('offset 3', $e->getMessage());
        }
    }

    public function testT38TtmlSafety(): void
    {
        $reader = new TranscriptReader();
        $xxe = '<?xml version="1.0"?><!DOCTYPE tt [<!ENTITY x SYSTEM "file:///etc/passwd">]><tt xmlns="http://www.w3.org/ns/ttml"><body><div><p begin="0s" end="1s">&x;</p></div></body></tt>';
        try {
            $reader->read($xxe);
            $this->fail('DOCTYPE must be refused');
        } catch (InvalidTranscriptException $e) {
            $this->assertStringContainsString('document type', $e->getMessage());
        }
        $many = '<tt xmlns="http://www.w3.org/ns/ttml"><body><div>' . str_repeat('<p begin="0s" end="1s"><span>a</span></p>', 30) . '</div></body></tt>';
        try {
            (new TtmlParser(50))->parse($many);
            $this->fail('node limit must apply');
        } catch (InvalidTranscriptException $e) {
            $this->assertStringContainsString('more than 50 nodes', $e->getMessage());
        }
        $this->assertSame(1.5, (new TtmlParser())->time('1500ms'));
        $this->assertSame(2.0, (new TtmlParser())->time('60f', 30.0));
        $this->assertSame(3.0, (new TtmlParser())->time('30000000t', 30.0, 10000000.0));
        $this->expectException(InvalidTranscriptException::class);
        $reader->read('<root><p>not ttml</p></root>');
    }

    public function testT39PlainTextGivesNoTimestampLinks(): void
    {
        $this->connect();
        $this->routeVideo($this->item());
        $this->answerArticle();
        $plain = implode("\n\n", array_map([self::class, 'sentence'], range(0, 30)));
        $this->createJob(self::VID, ['transcript' => $plain]);
        $job = $this->runAll();
        $this->assertSame(JobState::DONE, $job->state);
        $content = \WpStub::$posts[$job->postId]->post_content;
        $this->assertStringNotContainsString('&#038;t=', $content);
        $this->assertStringNotContainsString('Watch from', $content);
        $this->assertStringContainsString('no timestamp links', implode(' ', $job->warnings));
    }

    public function testT40MalformedCuesSkippedAndThreshold(): void
    {
        $srt = self::SRT . "\n3\n00:00:09,000 --> 00:00:08,000\nEnd before start\n\n4\nnot a time --> x\nBad\n\n5\n00:00:10,000 --> 00:00:12,000\nThird\n";
        $r = (new TranscriptReader())->read($srt);
        $this->assertSame(2, $r->malformed);
        $this->assertSame(5, $r->total);
        $this->assertCount(3, $r->cues);

        $bad = "1\n00:00:05,000 --> 00:00:04,000\nA\n\n2\n00:00:09,000 --> 00:00:08,000\nB\n\n3\n00:00:10,000 --> 00:00:11,000\nC\n";
        try {
            (new TranscriptReader())->read($bad);
            $this->fail('more than half malformed must be refused');
        } catch (InvalidTranscriptException $e) {
            $this->assertStringContainsString('2 of 3 cues are malformed (first at line 2)', $e->getMessage());
        }
    }

    public function testT41LanguageFallbackAndModelReport(): void
    {
        $this->connect();
        $this->routeVideo($this->item(['defaultAudioLanguage' => '', 'defaultLanguage' => 'en-IN']));
        $this->answerArticle();
        $this->createJob(self::VID, ['transcript' => self::vtt(), 'transcript_language' => 'same']);
        $this->assertSame('en-IN', $this->runAll()->transcriptLanguage);

        $this->setUp();
        $this->connect();
        $this->routeVideo($this->item(['defaultAudioLanguage' => '', 'defaultLanguage' => '']));
        $this->settings(['article_language' => 'source']);
        $this->answerArticle(['language' => 'en', 'source_language' => 'en-GB']);
        $this->createJob(self::VID, ['transcript' => self::vtt()]);
        $job = $this->runAll();
        $this->assertStringContainsString('unknown, report it', $this->caller->requests[0]->input);
        $this->assertSame('en-GB', $job->transcriptLanguage);
        $this->assertSame(JobState::DONE, $job->state);
    }

    public function testT42SupplyingResumesAtNormaliseWithoutMetadataCall(): void
    {
        $this->connect();
        $this->routeVideo($this->item(['channelId' => self::OTHER]));
        $this->http->on('#/youtube/v3/captions\?#', \IndianOpinion\YtToPost\Tests\Support\FakeHttp::json(['items' => [self::track('t1', 'en')]]));
        $this->http->on('#captions/t1#', \IndianOpinion\YtToPost\Tests\Support\FakeHttp::apiError(403, 'forbidden'));
        $this->answerArticle();
        $job = $this->createJob();
        $this->runAll();
        $this->assertSame(JobState::NEEDS_TRANSCRIPT, $this->job($job->id)->state);
        $videosCalls = count($this->http->urls('/videos?'));
        $this->assertTrue(yt_to_post_supply_transcript($job->id, self::vtt(), ['transcript_language' => 'en']));
        $this->assertSame(JobState::NORMALISE, $this->job($job->id)->state);
        $this->assertSame(JobState::DONE, $this->runAll()->state);
        $this->assertSame($videosCalls, count($this->http->urls('/videos?')));
        $this->assertFalse(yt_to_post_supply_transcript($job->id, self::vtt()), 'only a waiting job takes a transcript');
    }

    public function testT43EveryFormatGivesTheSameCues(): void
    {
        $cleaner = new TranscriptCleaner();
        $expected = null;
        foreach ([self::SRT, self::SBV, self::TTML, self::VTT] as $src) {
            $cues = $cleaner->clean((new TranscriptReader())->read($src)->cues);
            $shape = array_map(static fn (Cue $c): array => [round((float) $c->start, 1), $c->text], $cues);
            $expected ??= $shape;
            $this->assertSame($expected, $shape);
        }
        $stamped = $cleaner->clean((new TranscriptReader())->read(self::STAMPED)->cues);
        $this->assertSame(['Hello world', 'Second line'], array_map(static fn (Cue $c): string => $c->text, $stamped));
        $this->assertSame(1.0, $stamped[0]->start);
    }

    public function testT44CleaningFixture(): void
    {
        $vtt = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n<v.loud Dr. Rao>We <c.yellow>begin</c> <00:00:01.500>now &amp; here</v>\n\n"
            . "00:00:03.000 --> 00:00:04.000\n[Music] \u{266A} la la \u{266A} (applause)\n\n"
            . "00:00:05.000 --> 00:00:06.000\n>> Next speaker talks\n\n"
            . "00:00:07.000 --> 00:00:08.000\n- First voice\n- Second voice\n\n"
            . "00:00:09.000 --> 00:00:10.000\nThe &lt;b&gt; stays text\n";
        $cues = (new TranscriptCleaner())->clean((new TranscriptReader())->read($vtt)->cues);
        $texts = array_map(static fn (Cue $c): string => $c->text, $cues);
        $this->assertSame(['We begin now & here', 'Next speaker talks', 'First voice', 'Second voice', 'The <b> stays text'], $texts);
        $this->assertSame('Dr. Rao', $cues[0]->speaker);
        $this->assertTrue($cues[1]->turn);
        $this->assertTrue($cues[3]->turn);

        $kept = (new TranscriptCleaner(true))->clean((new TranscriptReader())->read($vtt)->cues);
        $this->assertContains("[Music] \u{266A} la la \u{266A} (applause)", array_map(static fn (Cue $c): string => $c->text, $kept));
    }

    public function testT45RollingAsrDeduplicates(): void
    {
        $vtt = "WEBVTT\n\n"
            . "00:00:00.000 --> 00:00:02.000\nthe river flows\n\n"
            . "00:00:02.000 --> 00:00:02.010\nthe river flows\n\n"
            . "00:00:02.010 --> 00:00:04.000\nthe river flows\nthrough the valley\n\n"
            . "00:00:04.000 --> 00:00:06.000\nThrough the valley and\ninto the sea\n\n"
            . "00:00:30.000 --> 00:00:31.000\ninto the sea\n";
        $cues = (new TranscriptCleaner())->clean((new TranscriptReader())->read($vtt)->cues);
        $text = implode(' ', array_map(static fn (Cue $c): string => $c->text, $cues));
        $this->assertSame('the river flows through the valley and into the sea', $text);
    }

    public function testT46ShortAndCjkCounting(): void
    {
        $this->assertSame(3, WordCounter::count('one two three'));
        $this->assertSame(5, WordCounter::count(str_repeat('川', 10)));
        $this->assertSame(4, WordCounter::count('Hello 東京都 world'), 'two words plus three characters / 2 rounded up');

        $this->connect();
        $this->routeVideo($this->item());
        $this->createJob(self::VID, ['transcript' => "WEBVTT\n\n00:00:00.000 --> 00:00:30.000\n[Music]\n\n00:00:30.000 --> 00:01:00.000\n\u{266A}\u{266A}\n"]);
        $job = $this->runAll();
        $this->assertSame(JobState::FAILED, $job->state);
        $this->assertSame('Transcript too short for an article', $job->lastError);
        $this->assertSame([], $this->caller->requests);
    }
}
