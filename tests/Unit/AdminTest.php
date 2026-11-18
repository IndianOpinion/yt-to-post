<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Unit;

use IndianOpinion\YtToPost\Admin\AdminController;
use IndianOpinion\YtToPost\Admin\Flash;
use IndianOpinion\YtToPost\Admin\SettingsPage;
use IndianOpinion\YtToPost\Admin\ToolsPage;
use IndianOpinion\YtToPost\Jobs\JobState;
use IndianOpinion\YtToPost\Plugin;
use IndianOpinion\YtToPost\Rest\RestController;
use IndianOpinion\YtToPost\Settings;
use IndianOpinion\YtToPost\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** PRD 4.13: admin screens and access (T86 to T89). */
final class AdminTest extends TestCase
{
    private function controller(): AdminController
    {
        return new AdminController($this->services, $this->jobs);
    }

    private function rest(): RestController
    {
        return new RestController($this->services, $this->jobs);
    }

    /** @param array<string,mixed> $post */
    private function post(string $action, array $post, string $nonceAction = ''): \IndianOpinion\YtToPost\Admin\AdminResult
    {
        $post['_wpnonce'] = wp_create_nonce($nonceAction !== '' ? $nonceAction : $action);
        return $this->controller()->handle($action, $post);
    }

    private function messages(): string
    {
        return implode(' | ', array_column(Flash::take(), 'message'));
    }

    public function testT86FormValidatesAndShowsEstimate(): void
    {
        $this->connect();
        $this->post('yt_to_post_create', ['video' => 'https://evil.example/watch?v=' . self::VID]);
        $this->assertStringContainsString('Not a YouTube video link', $this->messages());
        $this->assertSame([], $this->repo->list());

        $this->routeVideo($this->item(['channelId' => self::OTHER], [], ['duration' => 'PT90M']));
        $r = $this->post('yt_to_post_create', ['video' => self::VID, 'estimate' => '1']);
        $msg = $this->messages();
        $this->assertStringContainsString('90 min', $msg);
        $this->assertStringContainsString('captions present', $msg);
        $this->assertMatchesRegularExpression('/\d+ chunk\(s\), about \d+ model call\(s\), 251 quota units/', $msg);
        $this->assertStringContainsString('tick the rights box', $msg);
        $this->assertSame([], $this->repo->list(), 'an estimate creates no job');
        $this->assertSame(1, $this->services->quota()->used());
        $this->assertStringContainsString('video=', $r->redirect);

        $this->post('yt_to_post_create', ['video' => self::VID, 'rights_confirmed' => '1', 'transcript_text' => self::vtt(), 'status' => 'pending']);
        $this->assertStringContainsString('Job created', $this->messages());
        $job = $this->repo->list()[0];
        $this->assertSame('pending', $job->arg('status'));
        $this->assertTrue((bool) $job->arg('rights_confirmed'));
        $this->assertSame('transcript', $job->sourceKind);

        $tmp = tempnam(sys_get_temp_dir(), 'ytp');
        file_put_contents((string) $tmp, self::vtt());
        $this->controller()->handle('yt_to_post_create', ['_wpnonce' => wp_create_nonce('yt_to_post_create'), 'video' => 'https://youtu.be/bbbbbbbbbbb'], ['transcript_file' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => filesize((string) $tmp), 'name' => 'x.txt']]);
        unlink((string) $tmp);
        $this->assertSame(self::vtt(), $this->repo->list()[0]->source);
    }

    public function testT87EditorsSeeOnlyTheirOwnJobs(): void
    {
        $this->connect();
        $mine = $this->createJob(self::VID, [], 2);
        $theirs = $this->createJob('bbbbbbbbbbb', [], 1);
        \WpStub::$userId = 2;
        ob_start();
        (new ToolsPage($this->services))->render();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('job=' . $mine->id, $html);
        $this->assertStringNotContainsString('job=' . $theirs->id . '"', $html);

        $req = new \WP_REST_Request(['id' => $theirs->id], ['X-WP-Nonce' => wp_create_nonce('wp_rest')]);
        $res = $this->rest()->get($req);
        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame(404, $res->get_error_data()['status']);
        $this->assertInstanceOf(\WP_REST_Response::class, $this->rest()->get(new \WP_REST_Request(['id' => $mine->id])));

        $this->post('yt_to_post_job_action', ['job' => $theirs->id, 'op' => 'cancel'], 'yt_to_post_job_' . $theirs->id);
        $this->assertNotSame(JobState::CANCELLED, $this->job($theirs->id)->state);

        \WpStub::$userId = 1;
        ob_start();
        (new ToolsPage($this->services))->render();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('job=' . $theirs->id, $html);

        try {
            $this->jobs->create('https://youtu.be/ccccccccccc', ['user_id' => 1], 2, false);
            $this->fail('an editor cannot create for someone else');
        } catch (\IndianOpinion\YtToPost\Exception\YtToPostException $e) {
            $this->assertStringContainsString('another user', $e->getMessage());
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testT88ConstantFieldsAreReadOnly(): void
    {
        define('YT_TO_POST_CLIENT_SECRET', 'from-wp-config-secret');
        define('YT_TO_POST_DAILY_VIDEOS', 3);
        $this->settings(['api_key' => 'stored-api-key-1234']);
        ob_start();
        (new SettingsPage())->render();
        $html = (string) ob_get_clean();
        $this->assertMatchesRegularExpression('/name="settings\[client_secret\]"[^>]*disabled/', $html);
        $this->assertStringContainsString('Set by the constant YT_TO_POST_CLIENT_SECRET.', $html);
        $this->assertStringContainsString('Set by the constant YT_TO_POST_DAILY_VIDEOS.', $html);
        $this->assertStringNotContainsString('from-wp-config-secret', $html);
        $this->assertStringNotContainsString('stored-api-key-1234', $html);
        $this->assertStringContainsString('********1234', $html, 'last four characters only');
        $this->assertStringContainsString(esc_html(\IndianOpinion\YtToPost\YouTube\OAuthClient::redirectUri()), $html);

        $this->post('yt_to_post_settings', ['settings' => ['max_videos_per_day' => '99', 'client_secret' => 'typed', 'max_chunks' => '9999', 'retention_days' => '45']]);
        $saved = get_option(Settings::OPTION);
        $this->assertSame(10, $saved['max_videos_per_day'] ?? 10, 'constant-set field ignored');
        $this->assertSame('', $saved['client_secret'] ?? '');
        $this->assertSame(30, $saved['max_chunks'] ?? 30, 'out of range refused, old value kept');
        $this->assertSame(45, $saved['retention_days']);
        $this->assertSame(3, Settings::int('max_videos_per_day'));
    }

    public function testT89NonceAndCapabilityOnEveryFormActionAndRoute(): void
    {
        $this->connect();
        $job = $this->createJob();
        $post = $this->createJob('bbbbbbbbbbb');
        $actions = [
            'yt_to_post_create' => ['video' => 'https://youtu.be/ccccccccccc', 'rights_confirmed' => '1'],
            'yt_to_post_job_action' => ['job' => $job->id, 'op' => 'cancel'],
            'yt_to_post_supply' => ['job' => $job->id, 'transcript_text' => self::vtt()],
            'yt_to_post_settings' => ['settings' => ['max_chunks' => '5']],
            'yt_to_post_toggle' => ['on' => '0'],
            'yt_to_post_connect' => [],
            'yt_to_post_disconnect' => [],
        ];
        foreach ($actions as $action => $data) {
            $r = $this->controller()->handle($action, $data + ['_wpnonce' => 'forged']);
            $this->assertSame(403, $r->status, "$action without a valid nonce");
        }
        \WpStub::$userId = 3;
        foreach ($actions as $action => $data) {
            $nonceAction = $action === 'yt_to_post_job_action' ? 'yt_to_post_job_' . $job->id : ($action === 'yt_to_post_supply' ? 'yt_to_post_supply_' . $job->id : $action);
            $r = $this->controller()->handle($action, $data + ['_wpnonce' => wp_create_nonce($nonceAction)]);
            $this->assertSame(403, $r->status, "$action without the capability");
        }
        \WpStub::$userId = 2;
        foreach (['yt_to_post_settings', 'yt_to_post_toggle', 'yt_to_post_connect', 'yt_to_post_disconnect'] as $action) {
            $r = $this->controller()->handle($action, $actions[$action] + ['_wpnonce' => wp_create_nonce($action)]);
            $this->assertSame(403, $r->status, "$action needs the settings capability");
        }
        $r = $this->controller()->handle('yt_to_post_use_thumbnail', [], [], ['post' => 5, '_wpnonce' => 'forged']);
        $this->assertSame(403, $r->status);
        $this->assertSame(1, count($this->repo->list(['video_id' => self::VID])));
        $this->assertSame(JobState::QUEUED, $this->job($job->id)->state);
        $this->assertSame(Settings::DEFAULTS['max_chunks'], Settings::int('max_chunks'));
        $this->assertTrue(\IndianOpinion\YtToPost\Limits\KillSwitch::isEnabled());

        // OAuth callback without the capability stores nothing.
        \WpStub::$userId = 2;
        $this->controller()->handle('yt_to_post_oauth', [], [], ['state' => str_repeat('a', 32), 'code' => 'x']);
        $this->assertStringContainsString('not allowed', $this->messages());

        // REST.
        $this->rest()->register();
        $this->assertCount(6, \WpStub::$routes);
        foreach (\WpStub::$routes as $route => $def) {
            $this->assertIsCallable($def['permission_callback'], $route);
        }
        \WpStub::$userId = 2;
        $rest = $this->rest();
        $this->assertInstanceOf(\WP_Error::class, $rest->permission(new \WP_REST_Request([], [])), 'missing nonce');
        $this->assertInstanceOf(\WP_Error::class, $rest->permission(new \WP_REST_Request([], ['X-WP-Nonce' => 'bad'])));
        $this->assertTrue($rest->permission(new \WP_REST_Request([], ['X-WP-Nonce' => wp_create_nonce('wp_rest')])));
        \WpStub::$userId = 3;
        $res = $rest->permission(new \WP_REST_Request([], ['X-WP-Nonce' => wp_create_nonce('wp_rest')]));
        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame(403, $res->get_error_data()['status']);
        \WpStub::$userId = 0;
        $this->assertInstanceOf(\WP_Error::class, $rest->permission(new \WP_REST_Request([], ['X-WP-Nonce' => wp_create_nonce('wp_rest')])));
        \WpStub::$userId = 2;
        do_action('application_password_did_authenticate');
        $this->assertTrue($rest->permission(new \WP_REST_Request([], [])), 'application passwords carry no nonce');
        $this->assertNotNull($post);
    }

    public function testRestCreateAndEstimate(): void
    {
        $this->connect();
        $res = $this->rest()->create(new \WP_REST_Request(['video' => 'nope']));
        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame('yt_to_post_invalid_video_exception', $res->get_error_code());
        $res = $this->rest()->create(new \WP_REST_Request(['video' => self::VID, 'rights_confirmed' => true, 'unknown' => 'x']));
        $this->assertInstanceOf(\WP_REST_Response::class, $res);
        $this->assertSame(201, $res->get_status());
        $this->routeVideo($this->item());
        $est = $this->rest()->estimate(new \WP_REST_Request(['video' => self::VID]));
        $this->assertInstanceOf(\WP_REST_Response::class, $est);
        $this->assertSame(1800, $est->get_data()['duration']);
    }

    public function testThumbnailSuggestionNeedsRightsOrOwnChannel(): void
    {
        $this->connect();
        $this->routeVideo($this->item(['channelId' => self::OTHER]));
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $this->createJob(self::VID, ['rights_confirmed' => true]);
        $job = $this->runAll();
        $thumb = get_post_meta($job->postId, '_yt_to_post_suggested_thumbnail', true);
        $this->assertSame('https://i.ytimg.com/vi/' . self::VID . '/hqdefault.jpg', $thumb);
        $this->assertSame(0, \WpStub::$thumbnails[$job->postId] ?? 0, 'only a suggestion until the editor accepts');

        $r = $this->controller()->handle('yt_to_post_use_thumbnail', [], [], ['post' => $job->postId, '_wpnonce' => wp_create_nonce('yt_to_post_thumb_' . $job->postId)]);
        $this->assertSame(200, $r->status);
        $this->assertSame(900 + $job->postId, \WpStub::$thumbnails[$job->postId]);
        $this->assertSame($thumb, \WpStub::$sideloads[0][0]);

        ob_start();
        \IndianOpinion\YtToPost\Admin\MetaBox::render(\WpStub::$posts[$job->postId]);
        $box = (string) ob_get_clean();
        $this->assertStringContainsString('Transcript', $box);
        $this->assertStringContainsString('dropped_quotes', $box);
        $this->assertNotNull(Plugin::services());
    }
}
