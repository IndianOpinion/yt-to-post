<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Unit;

use IndianOpinion\YtToPost\Draft\DraftWriter;
use IndianOpinion\YtToPost\Jobs\Job;
use IndianOpinion\YtToPost\Jobs\JobState;
use IndianOpinion\YtToPost\Tests\Support\BlockGrammar;
use IndianOpinion\YtToPost\Tests\TestCase;

/** PRD 4.9: building the draft (T13, T65 to T68). */
final class DraftTest extends TestCase
{
    /** @param array<string,mixed> $snippet */
    private function make(array $snippet = [], array $status = [], array $article = [], array $args = []): Job
    {
        $this->connect();
        $this->routeVideo($this->item($snippet, $status));
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle($article);
        $this->createJob(self::VID, $args);
        $job = $this->runAll();
        $this->assertSame(JobState::DONE, $job->state, $job->lastError);
        return $job;
    }

    private function content(Job $job): string
    {
        return \WpStub::$posts[$job->postId]->post_content;
    }

    public function testT13NotEmbeddableHasNoticeNotEmbed(): void
    {
        $job = $this->make([], ['embeddable' => false]);
        $c = $this->content($job);
        $this->assertStringNotContainsString('wp:embed', $c);
        $this->assertStringContainsString('Watch the video on YouTube', $c);
        $this->assertSame('paragraph', BlockGrammar::parse($c)[0]);
        $this->assertStringContainsString('does not allow embedding', implode(' ', $job->warnings));
    }

    public function testT65BlockOrderEscapingAndGrammar(): void
    {
        $job = $this->make(['title' => 'Rivers & <Farms>'], [], [
            'standfirst' => 'Water <script>x</script> & life',
            'sections' => [['heading' => 'Q&A "time"', 'paragraphs' => ['One < two & three'], 'start_seconds' => 75]],
            'key_points' => ['First & foremost'],
            'quotes' => [['text' => self::sentence(4), 'translation' => 'Translated & kept', 'speaker' => 'Dr. <i>Rao</i>', 'start_seconds' => 1]],
        ]);
        $c = $this->content($job);
        $top = BlockGrammar::parse($c);
        $this->assertSame(['embed', 'paragraph', 'heading', 'paragraph', 'paragraph', 'list', 'quote', 'paragraph', 'paragraph'], $top);
        $this->assertStringContainsString('"providerNameSlug":"youtube"', $c);
        $this->assertStringContainsString('https://www.youtube.com/watch?v=' . self::VID, $c);
        $this->assertStringContainsString('<p>Water &amp; life</p>', $c);
        $this->assertStringContainsString('Q&amp;A &quot;time&quot;', $c);
        $this->assertStringContainsString('One &lt; two &amp; three', $c);
        $this->assertStringContainsString('Watch from 1:15', $c);
        $this->assertStringContainsString('watch?v=' . self::VID . '&#038;t=75s', $c);
        $this->assertStringContainsString('<li>First &amp; foremost</li>', $c);
        $this->assertStringContainsString('Translated &amp; kept', $c);
        $this->assertStringContainsString('<cite>Dr. Rao, <a href=', $c);
        $this->assertStringContainsString('&#038;t=20s', $c, 'quote time from the transcript cue, not the model');
        $this->assertStringNotContainsString('<script>', $c);
    }

    public function testT66CreditTextLinksAndFilterFallback(): void
    {
        $job = $this->make();
        $c = $this->content($job);
        $this->assertStringContainsString('Source: <a href="https://www.youtube.com/watch?v=' . self::VID . '">A talk about rivers</a> by <a href="https://www.youtube.com/channel/' . self::OWN . '">Own Channel</a>, published on YouTube on ', $c);
        $this->assertStringContainsString("Based on the video&#039;s captions.", $c);

        $this->setUp();
        add_filter('yt_to_post_credit_html', static fn (string $html) => '<p>Credit removed</p>');
        $c = $this->content($this->make());
        $this->assertStringNotContainsString('Credit removed', $c);
        $this->assertStringContainsString('Source: <a href="https://www.youtube.com/watch?v=' . self::VID, $c);

        $this->setUp();
        add_filter('yt_to_post_credit_html', static fn (string $html) => str_replace('Source:', 'Video:', $html));
        $this->assertStringContainsString('Video: <a href', $this->content($this->make()));

        $this->setUp();
        $this->connect();
        $this->routeVideo($this->item());
        $this->answerArticle();
        $this->createJob(self::VID, ['transcript' => self::vtt()]);
        $this->assertStringContainsString('Based on a transcript supplied by the editor.', $this->content($this->runAll()));
    }

    public function testT67StatusForcedAndDatesCleared(): void
    {
        add_filter('yt_to_post_post_args', static function (array $a): array {
            $a['post_status'] = 'publish';
            $a['post_date'] = '2030-01-01 00:00:00';
            $a['post_date_gmt'] = '2030-01-01 00:00:00';
            return $a;
        });
        $job = $this->make();
        $post = \WpStub::$posts[$job->postId];
        $this->assertSame('draft', $post->post_status);
        $this->assertSame('', $post->post_date);
        $this->assertArrayNotHasKey('post_date_gmt', $post->args);
        $this->assertSame('', $post->post_name, 'slug left to WordPress');

        $this->setUp();
        add_filter('yt_to_post_post_args', static fn (array $a): array => ['post_status' => 'future'] + $a);
        $job = $this->make([], [], [], ['status' => 'pending']);
        $this->assertSame('pending', \WpStub::$posts[$job->postId]->post_status);
        $this->assertSame(1, \WpStub::$posts[$job->postId]->post_author);
    }

    public function testT67TopicsAsTagsOnlyWhenOn(): void
    {
        $this->settings(['default_tags' => 'video']);
        $job = $this->make();
        $this->assertSame(['video'], \WpStub::$posts[$job->postId]->args['tags_input']);
        $this->setUp();
        $this->settings(['topics_as_tags' => true]);
        $job = $this->make();
        $this->assertSame(['rivers', 'farming'], \WpStub::$posts[$job->postId]->args['tags_input']);
    }

    public function testT68MetaWrittenNotesNotInContent(): void
    {
        $job = $this->make();
        $meta = \WpStub::$meta[$job->postId];
        $this->assertSame(self::VID, $meta[DraftWriter::META_VIDEO_ID]);
        $this->assertSame('captions', $meta[DraftWriter::META_SOURCE_KIND]);
        $this->assertSame('en', $meta[DraftWriter::META_TRANSCRIPT_LANGUAGE]);
        $this->assertSame('en-GB', $meta[DraftWriter::META_ARTICLE_LANGUAGE]);
        $this->assertSame($job->id, $meta[DraftWriter::META_JOB_ID]);
        $notes = json_decode((string) $meta[DraftWriter::META_NOTES], true);
        $this->assertSame(1, $notes['model_calls']);
        $this->assertSame(1, $notes['chunks']);
        foreach (array_keys($meta) as $k) {
            $this->assertStringStartsWith('_yt_to_post_', $k);
        }
        $this->assertStringNotContainsString('model_calls', $this->content($job));
        $this->assertStringNotContainsString('dropped_quotes', $this->content($job));
    }
}
