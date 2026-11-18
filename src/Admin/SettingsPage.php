<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Admin;

use IndianOpinion\YtToPost\Capabilities;
use IndianOpinion\YtToPost\Limits\KillSwitch;
use IndianOpinion\YtToPost\Settings;
use IndianOpinion\YtToPost\YouTube\Connection;
use IndianOpinion\YtToPost\YouTube\OAuthClient;

/**
 * Settings > YouTube to draft. Fields set by constants are read-only and
 * name the constant. Secrets are never printed back, only their last four
 * characters.
 */
final class SettingsPage
{
    public function render(): void
    {
        if (!Capabilities::canManage()) {
            wp_die(esc_html__('You are not allowed to use this page.', 'yt-to-post'));
        }
        echo '<div class="wrap"><h1>' . esc_html__('YouTube to draft', 'yt-to-post') . '</h1>';
        Flash::render();
        $this->switchBox();
        $this->connection();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="yt_to_post_settings">';
        wp_nonce_field('yt_to_post_settings');
        echo '<table class="form-table" role="presentation">';
        foreach (self::fields() as $key => [$label, $type, $help]) {
            echo $this->field($key, $label, $type, $help); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        echo '</table><p class="submit"><button class="button button-primary">' . esc_html__('Save settings', 'yt-to-post') . '</button></p></form></div>';
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function fields(): array
    {
        return [
            'client_id' => ['OAuth client id', 'text', 'From your own Google Cloud project, with the YouTube Data API v3 enabled.'],
            'client_secret' => ['OAuth client secret', 'secret', 'Prefer the YT_TO_POST_CLIENT_SECRET constant.'],
            'api_key' => ['API key (metadata only)', 'secret', 'Used for videos.list when no account is connected. Never for captions.'],
            'model_kind' => ['Model provider', 'select:openrouter,openai,generic', ''],
            'model_ids' => ['Model chain', 'text', 'One or more model ids, comma separated, tried in order.'],
            'model_base_url' => ['Base URL', 'text', 'Only for a generic OpenAI-compatible endpoint, https.'],
            'llm_api_key' => ['Model provider key', 'secret', 'Prefer the YT_TO_POST_LLM_API_KEY constant.'],
            'model_token_cap' => ['Output token cap', 'number', ''],
            'model_temperature' => ['Temperature', 'text', '0 to 2.'],
            'default_status' => ['Default status', 'select:draft,pending', ''],
            'default_author' => ['Default author id', 'number', 'Required for WP-CLI jobs without --user.'],
            'default_category' => ['Default category id', 'number', ''],
            'default_tags' => ['Default tags', 'text', 'Comma separated.'],
            'article_language' => ['Article language', 'text', 'source, site, or a BCP-47 tag.'],
            'site_country' => ['Site country', 'text', 'Two letters, for region warnings.'],
            'keep_annotations' => ['Keep sound annotations', 'bool', 'Keep [Music], (applause) and similar.'],
            'topics_as_tags' => ['Suggested topics as tags', 'bool', 'Otherwise they go in the draft notes.'],
            'allow_private_own' => ['Allow own private videos', 'bool', ''],
            'privacy_embed' => ['Privacy-enhanced player', 'bool', 'Embed with youtube-nocookie.com instead of the standard WordPress embed.'],
            'allow_playlists' => ['Allow playlist links', 'bool', 'A playlist link becomes one job per video.'],
            'max_playlist_items' => ['Videos per playlist', 'number', '1 to 50.'],
            'max_videos_per_day' => ['Videos per day', 'number', '0 to 500. 0 means no new work.'],
            'max_model_calls_per_day' => ['Model calls per day', 'number', '0 to 5,000, retries included.'],
            'youtube_quota_budget' => ['YouTube quota budget', 'number', 'Units per Pacific day. Default 9,000 of the default 10,000.'],
            'max_duration_minutes' => ['Longest video (minutes)', 'number', ''],
            'max_chunks' => ['Most chunks', 'number', ''],
            'single_pass_tokens' => ['Single pass up to (tokens)', 'number', ''],
            'chunk_tokens' => ['Chunk size (tokens)', 'number', ''],
            'min_words' => ['Fewest words', 'number', ''],
            'max_transcript_bytes' => ['Largest transcript (bytes)', 'number', ''],
            'max_job_attempts' => ['Attempts per job', 'number', ''],
            'tick_seconds' => ['Seconds per runner tick', 'number', ''],
            'retention_days' => ['Keep job working data (days)', 'number', '1 to 90. Transcript and notes in finished jobs; rows go after twice this.'],
            'api_data_days' => ['Keep YouTube API data (days)', 'number', '1 to 30. Check the current YouTube API Services policy.'],
            'api_data_on_expiry' => ['When API data is due', 'select:purge,refresh', 'Purge it, or refresh it with videos.list (1 unit each).'],
            'draft_transcript_days' => ['Keep the transcript on drafts (days)', 'number', '0 keeps it until you set a number.'],
        ];
    }

    private function field(string $key, string $label, string $type, string $help): string
    {
        $const = Settings::constantName($key);
        $value = Settings::all()[$key] ?? '';
        $name = 'settings[' . $key . ']';
        $id = 'yt-to-post-' . $key;
        $ro = $const !== null ? ' disabled' : '';
        if ($type === 'secret') {
            $shown = $const !== null ? '' : Settings::mask((string) $value);
            $input = '<input type="password" autocomplete="new-password" class="regular-text" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="" placeholder="' . esc_attr($shown) . '"' . $ro . '>';
            $help = trim($help . ' Leave empty to keep the stored value; enter - to clear it.');
        } elseif ($type === 'bool') {
            $input = '<input type="hidden" name="' . esc_attr($name) . '" value="0"><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1"' . checked((bool) $value, true, false) . $ro . '>';
        } elseif (str_starts_with($type, 'select:')) {
            $input = '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"' . $ro . '>';
            foreach (explode(',', substr($type, 7)) as $opt) {
                $input .= '<option value="' . esc_attr($opt) . '"' . selected((string) $value, $opt, false) . '>' . esc_html($opt) . '</option>';
            }
            $input .= '</select>';
        } else {
            $shown = $const !== null ? (string) Settings::get($key) : (string) $value;
            $input = '<input type="' . ($type === 'number' ? 'number' : 'text') . '" class="regular-text" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($shown) . '"' . $ro . '>';
        }
        if ($const !== null) {
            $help = sprintf('Set by the constant %s.', $const);
        }
        return '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>' . $input
            . ($help !== '' ? '<p class="description">' . esc_html($help) . '</p>' : '') . '</td></tr>';
    }

    private function switchBox(): void
    {
        $on = KillSwitch::isEnabled();
        echo '<h2>' . esc_html__('Switch', 'yt-to-post') . '</h2><p>' . esc_html($on ? 'On: jobs run.' : 'Off: new requests are refused and running jobs are held.') . '</p>';
        if (KillSwitch::lockedByConstant()) {
            echo '<p>' . esc_html__('YT_TO_POST_DISABLED is set: the plugin cannot be switched on here.', 'yt-to-post') . '</p>';
            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="yt_to_post_toggle">';
        wp_nonce_field('yt_to_post_toggle');
        echo '<input type="hidden" name="on" value="' . ($on ? '0' : '1') . '"><button class="button">' . esc_html($on ? 'Switch off' : 'Switch on') . '</button></form>';
    }

    private function connection(): void
    {
        $c = Connection::get();
        echo '<h2>' . esc_html__('YouTube connection', 'yt-to-post') . '</h2>';
        echo '<p>' . esc_html(sprintf('State: %s%s', $c['state'], $c['channel_title'] !== '' ? ' (' . $c['channel_title'] . ')' : '')) . '</p>';
        echo '<p>' . esc_html__('Register this redirect URI in your Google project:', 'yt-to-post') . ' <code>' . esc_html(OAuthClient::redirectUri()) . '</code></p>';
        echo '<p class="description">' . esc_html__('The connection asks for the youtube.force-ssl scope, which caption download requires. That scope can manage the account; the plugin only ever calls videos.list, channels.list, captions.list, captions.download and, when playlists are on, playlistItems.list. Connect a channel account used for this purpose. A Google project left in "testing" status loses its refresh token after seven days.', 'yt-to-post') . '</p>';
        $action = $c['state'] === Connection::CONNECTED ? 'yt_to_post_disconnect' : 'yt_to_post_connect';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action);
        echo '<button class="button">' . esc_html($action === 'yt_to_post_connect' ? 'Connect a YouTube account' : 'Disconnect') . '</button></form>';
    }
}
