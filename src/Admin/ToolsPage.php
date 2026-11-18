<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Admin;

use IndianOpinion\YtToPost\Capabilities;
use IndianOpinion\YtToPost\Jobs\Job;
use IndianOpinion\YtToPost\Jobs\JobState;
use IndianOpinion\YtToPost\Limits\KillSwitch;
use IndianOpinion\YtToPost\Services;
use IndianOpinion\YtToPost\Settings;
use IndianOpinion\YtToPost\YouTube\Connection;

/**
 * Tools > Video to draft: the create form, the jobs list and job detail.
 * No remote calls on page load.
 */
final class ToolsPage
{
    public const PER_PAGE = 20;

    public function __construct(private readonly Services $s)
    {
    }

    public function render(): void
    {
        if (!Capabilities::canCreate()) {
            wp_die(esc_html__('You are not allowed to use this page.', 'yt-to-post'));
        }
        echo '<div class="wrap"><h1>' . esc_html__('Video to draft', 'yt-to-post') . '</h1>';
        Flash::render();
        $jobId = isset($_GET['job']) ? absint($_GET['job']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($jobId > 0) {
            $job = $this->s->jobs->find($jobId);
            if ($job !== null && Capabilities::canSeeJob($job)) {
                $this->detail($job);
                echo '</div>';
                return;
            }
        }
        $this->form();
        $this->table();
        echo '</div>';
    }

    private function form(): void
    {
        $enabled = KillSwitch::isEnabled();
        if (!$enabled) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('yt-to-post is switched off. New requests are refused.', 'yt-to-post') . '</p></div>';
        }
        if (!Connection::isConnected()) {
            echo '<p>' . esc_html__('No YouTube account is connected. Captions can only be downloaded through a connected account, and only for videos that account owns or may edit. For other videos, supply a transcript.', 'yt-to-post') . '</p>';
        }
        $prefill = isset($_GET['video']) ? sanitize_text_field(wp_unslash((string) $_GET['video'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $disabled = $enabled ? '' : ' disabled';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="yt_to_post_create">';
        wp_nonce_field('yt_to_post_create');
        echo '<table class="form-table" role="presentation">';
        $this->row('video', __('Video link', 'yt-to-post'), '<input type="text" class="regular-text" id="video" name="video" required value="' . esc_attr($prefill) . '"' . $disabled . '>');
        $this->row('transcript_text', __('Transcript (optional)', 'yt-to-post'), '<textarea id="transcript_text" name="transcript_text" rows="5" class="large-text"' . $disabled . '></textarea>'
            . '<p><input type="file" name="transcript_file" accept=".vtt,.srt,.sbv,.ttml,.xml,.dfxp,.txt"' . $disabled . '></p>'
            . '<p class="description">' . esc_html__('WebVTT, SRT, SBV, TTML or text. Plain text without times works, but the draft then has no timestamp links.', 'yt-to-post') . '</p>');
        $this->row('chapters', __('Chapters (optional)', 'yt-to-post'), '<textarea id="chapters" name="chapters" rows="3" class="large-text" placeholder="0:00 Intro"' . $disabled . '></textarea>');
        $this->row('transcript_language', __('Transcript language', 'yt-to-post'), '<input type="text" id="transcript_language" name="transcript_language" placeholder="same" class="small-text"' . $disabled . '>'
            . '<p class="description">' . esc_html__('A BCP-47 tag such as hi or pt-BR, or empty for the same as the video.', 'yt-to-post') . '</p>');
        $this->row('article_language', __('Article language', 'yt-to-post'), '<input type="text" id="article_language" name="article_language" placeholder="' . esc_attr(Settings::string('article_language')) . '" class="small-text"' . $disabled . '>'
            . '<p class="description">' . esc_html__('source, site, or a BCP-47 tag.', 'yt-to-post') . '</p>');
        $status = Settings::string('default_status');
        $this->row('status', __('Status', 'yt-to-post'), '<select id="status" name="status"' . $disabled . '>'
            . '<option value="draft"' . selected($status, 'draft', false) . '>draft</option>'
            . '<option value="pending"' . selected($status, 'pending', false) . '>pending</option></select>');
        $this->row('category', __('Category', 'yt-to-post'), (string) wp_dropdown_categories([
            'name' => 'category', 'id' => 'category', 'echo' => false, 'hide_empty' => false,
            'show_option_none' => __('Default', 'yt-to-post'), 'option_none_value' => '0',
            'selected' => Settings::int('default_category'),
        ]));
        $boxes = [
            'rights_confirmed' => __('I have the rights to use this video (required for videos from other channels).', 'yt-to-post'),
            'allow_unlisted' => __('I know this video is unlisted.', 'yt-to-post'),
            'override_limits' => __('Override the duration and chunk limits.', 'yt-to-post'),
            'force' => __('Force a new draft even if one exists.', 'yt-to-post'),
        ];
        $html = '';
        foreach ($boxes as $k => $label) {
            $html .= '<label><input type="checkbox" name="' . esc_attr($k) . '" value="1"' . $disabled . '> ' . esc_html($label) . '</label><br>';
        }
        $this->row('', __('Checks', 'yt-to-post'), $html);
        echo '</table>';
        echo '<p class="submit"><button class="button" name="estimate" value="1"' . $disabled . '>' . esc_html__('Estimate (1 quota unit)', 'yt-to-post') . '</button> ';
        echo '<button class="button button-primary"' . $disabled . '>' . esc_html__('Create draft', 'yt-to-post') . '</button></p>';
        echo '</form>';
    }

    private function row(string $for, string $label, string $html): void
    {
        echo '<tr><th scope="row">' . ($for !== '' ? '<label for="' . esc_attr($for) . '">' . esc_html($label) . '</label>' : esc_html($label)) . '</th><td>';
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
        echo '</td></tr>';
    }

    private function table(): void
    {
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filters = Capabilities::canManage() ? [] : ['user_id' => get_current_user_id()];
        $jobs = $this->s->jobs->list($filters, self::PER_PAGE, ($paged - 1) * self::PER_PAGE);
        echo '<h2>' . esc_html__('Jobs', 'yt-to-post') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach (['Video', 'State', 'Step', 'Requested by', 'Created', 'Finished', 'Model calls', 'Quota units', 'Error', 'Draft'] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if ($jobs === []) {
            echo '<tr><td colspan="10">' . esc_html__('No jobs yet.', 'yt-to-post') . '</td></tr>';
        }
        foreach ($jobs as $job) {
            $title = (string) (($job->metadata ?? [])['title'] ?? $job->videoId);
            $user = get_userdata($job->userId);
            echo '<tr>';
            echo '<td><a href="' . esc_url(AdminController::pageUrl(['job' => $job->id])) . '">' . esc_html($title !== '' ? $title : $job->videoId) . '</a>';
            echo '<div class="row-actions">' . $this->actions($job) . '</div></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<td>' . esc_html($job->state) . '</td><td>' . esc_html($job->step) . '</td>';
            echo '<td>' . esc_html($user !== false ? $user->display_name : (string) $job->userId) . '</td>';
            echo '<td>' . esc_html($this->date($job->createdAt)) . '</td><td>' . esc_html($this->date($job->finishedAt)) . '</td>';
            echo '<td>' . esc_html((string) $job->modelCalls) . '</td><td>' . esc_html((string) $job->quotaUnits) . '</td>';
            echo '<td>' . esc_html(mb_substr($job->lastError, 0, 160)) . '</td>';
            echo '<td>' . ($job->postId > 0 ? '<a href="' . esc_url((string) get_edit_post_link($job->postId)) . '">' . esc_html__('Edit draft', 'yt-to-post') . '</a>' : '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        $total = $this->s->jobs->count($filters);
        if ($total > self::PER_PAGE) {
            echo '<p>';
            for ($p = 1; $p <= (int) ceil($total / self::PER_PAGE); $p++) {
                echo $p === $paged ? '<strong>' . esc_html((string) $p) . '</strong> ' : '<a href="' . esc_url(AdminController::pageUrl(['paged' => $p])) . '">' . esc_html((string) $p) . '</a> ';
            }
            echo '</p>';
        }
    }

    private function actions(Job $job): string
    {
        $out = [];
        $ops = [];
        if (!in_array($job->state, [JobState::DONE, JobState::NEEDS_TRANSCRIPT, JobState::NEEDS_RIGHTS], true)) {
            $ops['retry'] = __('Retry', 'yt-to-post');
        }
        if (!JobState::isTerminal($job->state)) {
            $ops['cancel'] = __('Cancel', 'yt-to-post');
        }
        if ($job->state === JobState::NEEDS_RIGHTS) {
            $ops['confirm_rights'] = __('Confirm rights', 'yt-to-post');
        }
        foreach ($ops as $op => $label) {
            $out[] = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
                . '<input type="hidden" name="action" value="yt_to_post_job_action">'
                . '<input type="hidden" name="job" value="' . esc_attr((string) $job->id) . '">'
                . '<input type="hidden" name="op" value="' . esc_attr($op) . '">'
                . wp_nonce_field('yt_to_post_job_' . $job->id, '_wpnonce', true, false)
                . '<button class="button-link">' . esc_html($label) . '</button></form>';
        }
        if ($job->state === JobState::NEEDS_TRANSCRIPT) {
            $out[] = '<a href="' . esc_url(AdminController::pageUrl(['job' => $job->id])) . '">' . esc_html__('Supply transcript', 'yt-to-post') . '</a>';
        }
        return implode(' | ', $out);
    }

    private function detail(Job $job): void
    {
        echo '<p><a href="' . esc_url(AdminController::pageUrl()) . '">' . esc_html__('All jobs', 'yt-to-post') . '</a></p>';
        echo '<h2>' . esc_html(sprintf('Job %d: %s', $job->id, (string) (($job->metadata ?? [])['title'] ?? $job->videoId))) . '</h2>';
        echo '<table class="widefat striped"><tbody>';
        foreach ($job->summary() as $k => $v) {
            $v = is_array($v) ? implode("\n", array_map('strval', $v)) : (string) $v;
            echo '<tr><th>' . esc_html($k) . '</th><td style="white-space:pre-wrap">' . esc_html($v) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p>' . $this->actions($job) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        if ($job->state === JobState::NEEDS_TRANSCRIPT) {
            echo '<h3>' . esc_html__('Supply a transcript', 'yt-to-post') . '</h3>';
            echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="yt_to_post_supply"><input type="hidden" name="job" value="' . esc_attr((string) $job->id) . '">';
            wp_nonce_field('yt_to_post_supply_' . $job->id);
            echo '<p><textarea name="transcript_text" rows="8" class="large-text"></textarea></p>';
            echo '<p><input type="file" name="transcript_file" accept=".vtt,.srt,.sbv,.ttml,.xml,.dfxp,.txt"></p>';
            echo '<p><label>' . esc_html__('Transcript language', 'yt-to-post') . ' <input type="text" name="transcript_language" class="small-text" placeholder="same"></label></p>';
            echo '<p><label>' . esc_html__('Chapters (optional)', 'yt-to-post') . '<br><textarea name="chapters" rows="3" class="large-text"></textarea></label></p>';
            echo '<p><button class="button button-primary">' . esc_html__('Resume with this transcript', 'yt-to-post') . '</button></p></form>';
        }
        echo '<h3>' . esc_html__('Log', 'yt-to-post') . '</h3><pre style="white-space:pre-wrap">' . esc_html(implode("\n", $job->log)) . '</pre>';
    }

    private function date(int $ts): string
    {
        return $ts > 0 ? (string) wp_date('Y-m-d H:i', $ts) : '';
    }
}
