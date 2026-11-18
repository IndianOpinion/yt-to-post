<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Admin;

use IndianOpinion\YtToPost\Capabilities;
use IndianOpinion\YtToPost\Draft\DraftWriter;
use IndianOpinion\YtToPost\Exception\YtToPostException;
use IndianOpinion\YtToPost\Jobs\JobService;
use IndianOpinion\YtToPost\Limits\KillSwitch;
use IndianOpinion\YtToPost\Services;
use IndianOpinion\YtToPost\Settings;
use IndianOpinion\YtToPost\Video\VideoIdParser;
use IndianOpinion\YtToPost\Video\VideoMeta;

/**
 * Form and row-action handlers. Every handler checks a nonce and a
 * capability before it reads anything else.
 */
final class AdminController
{
    public const PAGE = 'yt-to-post';
    public const SETTINGS_PAGE = 'yt-to-post-settings';

    public function __construct(private readonly Services $s, private readonly JobService $jobs)
    {
    }

    /** @param array<string,int|string> $args */
    public static function pageUrl(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::PAGE], $args), admin_url('tools.php'));
    }

    public static function settingsUrl(): string
    {
        return add_query_arg(['page' => self::SETTINGS_PAGE], admin_url('options-general.php'));
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     * @param array<string,mixed> $get
     */
    public function handle(string $action, array $post, array $files = [], array $get = []): AdminResult
    {
        return match ($action) {
            'yt_to_post_create' => $this->create($post, $files),
            'yt_to_post_job_action' => $this->jobAction($post),
            'yt_to_post_supply' => $this->supply($post, $files),
            'yt_to_post_settings' => $this->settings($post),
            'yt_to_post_toggle' => $this->toggle($post),
            'yt_to_post_connect' => $this->connect($post),
            'yt_to_post_disconnect' => $this->disconnect($post),
            'yt_to_post_oauth' => $this->oauth($get),
            'yt_to_post_use_thumbnail' => $this->useThumbnail($get),
            default => $this->deny('Unknown action.'),
        };
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     */
    private function create(array $post, array $files): AdminResult
    {
        if (!$this->nonce($post, 'yt_to_post_create') || !Capabilities::canCreate()) {
            return $this->deny();
        }
        if (!KillSwitch::isEnabled()) {
            Flash::add('yt-to-post is switched off.', 'error');
            return new AdminResult(self::pageUrl());
        }
        $video = self::text($post, 'video');
        try {
            if (!empty($post['estimate'])) {
                $e = $this->jobs->estimate($video);
                Flash::add(sprintf(
                    'Estimate for "%s": %s, captions %s, %d chunk(s), about %d model call(s), %d quota units.%s',
                    (string) $e['title'],
                    $e['duration'] === null ? 'duration unknown' : (int) ceil(((int) $e['duration']) / 60) . ' min',
                    $e['captions'] ? 'present' : 'absent',
                    (int) $e['estimated_chunks'],
                    (int) $e['estimated_model_calls'],
                    (int) $e['estimated_quota_units'],
                    $e['own_channel'] ? '' : ' Video is from another channel: tick the rights box.'
                ), 'info');
                return new AdminResult(self::pageUrl(['video' => rawurlencode($video)]));
            }
            $args = [
                'transcript' => $this->transcriptFrom($post, $files),
                'transcript_language' => self::text($post, 'transcript_language'),
                'article_language' => self::text($post, 'article_language'),
                'status' => self::text($post, 'status'),
                'category' => (int) ($post['category'] ?? 0),
                'chapters' => self::textarea($post, 'chapters'),
                'force' => !empty($post['force']),
                'allow_unlisted' => !empty($post['allow_unlisted']),
                'override_limits' => !empty($post['override_limits']),
                'rights_confirmed' => !empty($post['rights_confirmed']),
            ];
            $ref = VideoIdParser::parse($video);
            if ($ref !== null && $ref->isPlaylistOnly()) {
                $outcomes = $this->jobs->createFromPlaylist($video, $args, get_current_user_id(), Capabilities::canManage());
                Flash::add(sprintf('%d job(s) created from the playlist.', count(array_filter($outcomes, static fn ($o): bool => !$o->existing))));
                return new AdminResult(self::pageUrl());
            }
            $outcome = $this->jobs->create($video, $args, get_current_user_id(), Capabilities::canManage());
            Flash::add($outcome->message, $outcome->existing ? 'warning' : 'success');
        } catch (YtToPostException $e) {
            Flash::add($e->getMessage(), 'error');
        }
        return new AdminResult(self::pageUrl());
    }

    /** @param array<string,mixed> $post */
    private function jobAction(array $post): AdminResult
    {
        $id = (int) ($post['job'] ?? 0);
        if (!$this->nonce($post, 'yt_to_post_job_' . $id) || !Capabilities::canCreate()) {
            return $this->deny();
        }
        $job = $this->s->jobs->find($id);
        if ($job === null || !Capabilities::canSeeJob($job)) {
            return $this->deny();
        }
        $op = self::text($post, 'op');
        $ok = match ($op) {
            'retry' => $this->jobs->retry($id),
            'cancel' => $this->jobs->cancel($id),
            'confirm_rights' => $this->jobs->confirmRights($id, get_current_user_id()),
            default => false,
        };
        Flash::add($ok ? 'Done.' : 'Nothing to do for this job.', $ok ? 'success' : 'warning');
        return new AdminResult(self::pageUrl(['job' => $id]));
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     */
    private function supply(array $post, array $files): AdminResult
    {
        $id = (int) ($post['job'] ?? 0);
        if (!$this->nonce($post, 'yt_to_post_supply_' . $id) || !Capabilities::canCreate()) {
            return $this->deny();
        }
        $job = $this->s->jobs->find($id);
        if ($job === null || !Capabilities::canSeeJob($job)) {
            return $this->deny();
        }
        try {
            $text = $this->transcriptFrom($post, $files);
            $ok = $text !== null && $this->jobs->supplyTranscript($id, $text, [
                'transcript_language' => self::text($post, 'transcript_language'),
                'chapters' => self::textarea($post, 'chapters'),
            ]);
            Flash::add($ok ? 'Transcript received; the job resumes.' : 'This job is not waiting for a transcript.', $ok ? 'success' : 'warning');
        } catch (YtToPostException $e) {
            Flash::add($e->getMessage(), 'error');
        }
        return new AdminResult(self::pageUrl(['job' => $id]));
    }

    /** @param array<string,mixed> $post */
    private function settings(array $post): AdminResult
    {
        if (!$this->nonce($post, 'yt_to_post_settings') || !Capabilities::canManage()) {
            return $this->deny();
        }
        $input = is_array($post['settings'] ?? null) ? wp_unslash($post['settings']) : [];
        $input = is_array($input) ? $input : [];
        foreach (Settings::BOOLEANS as $b) {
            $input[$b] = !empty($input[$b]);
        }
        foreach (array_keys(Settings::CONSTANTS) as $k) {
            if (Settings::constantName($k) !== null) {
                unset($input[$k]);
            }
        }
        $old = get_option(Settings::OPTION, []);
        [$values, $errors] = Settings::validate($input, is_array($old) ? $old : []);
        if (KillSwitch::lockedByConstant()) {
            $values['enabled'] = is_array($old) ? (bool) ($old['enabled'] ?? true) : true;
        }
        Settings::save($values);
        foreach ($errors as $e) {
            Flash::add($e . ' The old value was kept.', 'error');
        }
        if ($errors === []) {
            Flash::add('Settings saved.');
        }
        return new AdminResult(self::settingsUrl());
    }

    /** @param array<string,mixed> $post */
    private function toggle(array $post): AdminResult
    {
        if (!$this->nonce($post, 'yt_to_post_toggle') || !Capabilities::canManage()) {
            return $this->deny();
        }
        $on = !empty($post['on']);
        if (!KillSwitch::set($on)) {
            Flash::add('YT_TO_POST_DISABLED is set in wp-config.php: the plugin cannot be switched on here.', 'error');
        } else {
            Flash::add($on ? 'Switched on.' : 'Switched off. Running jobs stop before their next external call.');
            if ($on) {
                \IndianOpinion\YtToPost\Jobs\Scheduler::kick();
            }
        }
        return new AdminResult(self::settingsUrl());
    }

    /** @param array<string,mixed> $post */
    private function connect(array $post): AdminResult
    {
        if (!$this->nonce($post, 'yt_to_post_connect') || !Capabilities::canManage()) {
            return $this->deny();
        }
        try {
            return new AdminResult($this->s->oauth()->authorizationUrl(get_current_user_id()), 302);
        } catch (YtToPostException $e) {
            Flash::add($e->getMessage(), 'error');
            return new AdminResult(self::settingsUrl());
        }
    }

    /** @param array<string,mixed> $post */
    private function disconnect(array $post): AdminResult
    {
        if (!$this->nonce($post, 'yt_to_post_disconnect') || !Capabilities::canManage()) {
            return $this->deny();
        }
        $err = $this->s->oauth()->disconnect();
        Flash::add(
            $err === null ? 'Disconnected and revoked at Google.' : 'Disconnected here, but the revoke at Google failed: ' . $err,
            $err === null ? 'success' : 'warning'
        );
        return new AdminResult(self::settingsUrl());
    }

    /** @param array<string,mixed> $get */
    private function oauth(array $get): AdminResult
    {
        try {
            $msg = $this->s->oauth()->handleCallback(
                array_map(static fn ($v) => is_string($v) ? sanitize_text_field($v) : null, $get),
                get_current_user_id(),
                Capabilities::canManage(),
                $this->s->youtube()
            );
            Flash::add($msg);
        } catch (YtToPostException $e) {
            Flash::add($e->getMessage(), 'error');
        }
        return new AdminResult(self::settingsUrl());
    }

    /** @param array<string,mixed> $get */
    private function useThumbnail(array $get): AdminResult
    {
        $postId = (int) ($get['post'] ?? 0);
        if (!$this->nonce($get, 'yt_to_post_thumb_' . $postId) || $postId <= 0 || !current_user_can('edit_post', $postId)) {
            return $this->deny();
        }
        $url = (string) get_post_meta($postId, DraftWriter::META_THUMBNAIL, true);
        if (!VideoMeta::isThumbnailUrl($url)) {
            Flash::add('No suggested image for this draft.', 'warning');
            return new AdminResult((string) get_edit_post_link($postId, 'raw'));
        }
        if (!function_exists('media_sideload_image') && defined('ABSPATH')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $att = media_sideload_image($url, $postId, null, 'id');
        if (is_wp_error($att)) {
            Flash::add('The image could not be added: ' . $att->get_error_message(), 'error');
        } else {
            set_post_thumbnail($postId, (int) $att);
            delete_post_meta($postId, DraftWriter::META_THUMBNAIL);
            Flash::add('The video thumbnail is now the featured image.');
        }
        return new AdminResult((string) get_edit_post_link($postId, 'raw'));
    }

    // Helpers ---------------------------------------------------------------

    /** @param array<string,mixed> $data */
    private function nonce(array $data, string $action): bool
    {
        $n = $data['_wpnonce'] ?? '';
        return is_string($n) && $n !== '' && wp_verify_nonce($n, $action) !== false;
    }

    private function deny(string $message = 'You are not allowed to do that, or the form expired. Reload and try again.'): AdminResult
    {
        Flash::add($message, 'error');
        return new AdminResult(self::pageUrl(), 403);
    }

    /**
     * Pasted text, or an uploaded file's bytes. The format is detected from
     * the content later, never from the file name.
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     */
    private function transcriptFrom(array $post, array $files): ?string
    {
        $f = $files['transcript_file'] ?? null;
        if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_string($f['tmp_name'] ?? null)) {
            $tmp = $f['tmp_name'];
            if (!is_uploaded_file($tmp) && !(defined('YT_TO_POST_TESTING') && YT_TO_POST_TESTING)) {
                throw new YtToPostException('The upload could not be read.');
            }
            $size = (int) ($f['size'] ?? 0);
            if ($size > Settings::int('max_transcript_bytes')) {
                throw new \IndianOpinion\YtToPost\Exception\InvalidTranscriptException('The transcript file is larger than the limit.');
            }
            $bytes = file_get_contents($tmp);
            if ($bytes === false) {
                throw new YtToPostException('The upload could not be read.');
            }
            return $bytes;
        }
        $pasted = isset($post['transcript_text']) && is_string($post['transcript_text']) ? wp_unslash($post['transcript_text']) : '';
        return is_string($pasted) && trim($pasted) !== '' ? $pasted : null;
    }

    /** @param array<string,mixed> $post */
    private static function text(array $post, string $key): string
    {
        $v = $post[$key] ?? '';
        return is_string($v) ? sanitize_text_field(wp_unslash($v)) : '';
    }

    /** @param array<string,mixed> $post */
    private static function textarea(array $post, string $key): string
    {
        $v = $post[$key] ?? '';
        return is_string($v) ? sanitize_textarea_field(wp_unslash($v)) : '';
    }
}
