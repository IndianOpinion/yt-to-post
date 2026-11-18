<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Rest;

use IndianOpinion\YtToPost\Capabilities;
use IndianOpinion\YtToPost\Exception\CapReachedException;
use IndianOpinion\YtToPost\Exception\DisabledException;
use IndianOpinion\YtToPost\Exception\YtToPostException;
use IndianOpinion\YtToPost\Jobs\JobService;
use IndianOpinion\YtToPost\Services;

/**
 * REST routes under yt-to-post/v1. Every route needs a logged-in user with
 * the create capability and a valid REST nonce (requests authenticated by
 * an application password carry no nonce and need none).
 */
final class RestController
{
    public const NS = 'yt-to-post/v1';

    public function __construct(private readonly Services $s, private readonly JobService $jobs)
    {
    }

    public function register(): void
    {
        $perm = [$this, 'permission'];
        register_rest_route(self::NS, '/jobs', ['methods' => 'POST', 'callback' => [$this, 'create'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, '/jobs/(?P<id>\d+)', ['methods' => 'GET', 'callback' => [$this, 'get'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, '/jobs/(?P<id>\d+)/transcript', ['methods' => 'POST', 'callback' => [$this, 'transcript'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, '/jobs/(?P<id>\d+)/retry', ['methods' => 'POST', 'callback' => [$this, 'retry'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, '/jobs/(?P<id>\d+)/cancel', ['methods' => 'POST', 'callback' => [$this, 'cancel'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, '/estimate', ['methods' => 'POST', 'callback' => [$this, 'estimate'], 'permission_callback' => $perm]);
    }

    public function permission(\WP_REST_Request $request): bool|\WP_Error
    {
        if (!is_user_logged_in()) {
            return new \WP_Error('rest_forbidden', 'Log in first.', ['status' => 401]);
        }
        if (!did_action('application_password_did_authenticate')) {
            $nonce = $request->get_header('X-WP-Nonce');
            if ($nonce === null || $nonce === '' || wp_verify_nonce($nonce, 'wp_rest') === false) {
                return new \WP_Error('rest_cookie_invalid_nonce', 'Missing or invalid REST nonce.', ['status' => 403]);
            }
        }
        if (!Capabilities::canCreate()) {
            return new \WP_Error('rest_forbidden', 'You are not allowed to create drafts from videos.', ['status' => 403]);
        }
        return true;
    }

    public function create(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $params = $r->get_json_params();
        $params = is_array($params) && $params !== [] ? $params : $r->get_params();
        $video = is_string($params['video'] ?? null) ? $params['video'] : '';
        $args = array_intersect_key($params, array_flip(JobService::ARG_KEYS));
        try {
            $o = $this->jobs->create($video, $args, get_current_user_id(), Capabilities::canManage());
        } catch (YtToPostException $e) {
            return $this->error($e);
        }
        return new \WP_REST_Response([
            'job' => $o->job?->summary(),
            'existing' => $o->existing,
            'message' => $o->message,
            'post_id' => $o->postId,
        ], $o->existing ? 200 : 201);
    }

    public function get(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $job = $this->s->jobs->find((int) $r['id']);
        if ($job === null || !Capabilities::canSeeJob($job)) {
            return new \WP_Error('yt_to_post_not_found', 'No such job.', ['status' => 404]);
        }
        $out = $job->summary();
        $out['log'] = $job->log;
        return new \WP_REST_Response($out);
    }

    public function transcript(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $job = $this->s->jobs->find((int) $r['id']);
        if ($job === null || !Capabilities::canSeeJob($job)) {
            return new \WP_Error('yt_to_post_not_found', 'No such job.', ['status' => 404]);
        }
        $text = $r->get_param('transcript');
        if (!is_string($text)) {
            return new \WP_Error('yt_to_post_bad_request', 'Send the transcript as a string.', ['status' => 400]);
        }
        try {
            $ok = $this->jobs->supplyTranscript($job->id, $text, [
                'transcript_language' => is_string($r->get_param('transcript_language')) ? $r->get_param('transcript_language') : '',
                'chapters' => is_string($r->get_param('chapters')) ? $r->get_param('chapters') : '',
            ]);
        } catch (YtToPostException $e) {
            return $this->error($e);
        }
        return new \WP_REST_Response(['resumed' => $ok], $ok ? 200 : 409);
    }

    public function retry(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->op($r, 'retry');
    }

    public function cancel(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        return $this->op($r, 'cancel');
    }

    public function estimate(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $video = $r->get_param('video');
        try {
            return new \WP_REST_Response($this->jobs->estimate(is_string($video) ? $video : ''));
        } catch (YtToPostException $e) {
            return $this->error($e);
        }
    }

    private function op(\WP_REST_Request $r, string $op): \WP_REST_Response|\WP_Error
    {
        $job = $this->s->jobs->find((int) $r['id']);
        if ($job === null || !Capabilities::canSeeJob($job)) {
            return new \WP_Error('yt_to_post_not_found', 'No such job.', ['status' => 404]);
        }
        $ok = $op === 'retry' ? $this->jobs->retry($job->id) : $this->jobs->cancel($job->id);
        return new \WP_REST_Response(['ok' => $ok], $ok ? 200 : 409);
    }

    private function error(YtToPostException $e): \WP_Error
    {
        $status = match (true) {
            $e instanceof DisabledException => 503,
            $e instanceof CapReachedException => 429,
            default => 400,
        };
        $code = 'yt_to_post_' . strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', (new \ReflectionClass($e))->getShortName()));
        return new \WP_Error($code, $e->getMessage(), ['status' => $status]);
    }
}
