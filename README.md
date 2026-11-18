# yt-to-post

Turns one YouTube video into one WordPress draft: the words come from captions downloaded through the official YouTube Data API with your own OAuth, or from a transcript you supply, and a language model writes a structured article with the video embedded and the source credited. An editor reads, fixes and publishes it; the plugin never publishes.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

Measured in the test suite (mock model, four seconds a call): a 60-minute captioned talk becomes a draft in 4 model calls and 16 seconds of simulated wall time, inside one 25-second runner tick, with every quote in the draft found word for word in the transcript; a 5 MiB transcript parses, cleans and chunks at a peak of about 21 MiB.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [Hooks, REST and WP-CLI](#hooks-rest-and-wp-cli)
- [YouTube API policy and your data](#youtube-api-policy-and-your-data)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

WordPress 6.4 or later, PHP 8.1 or later, with `json`, `mbstring`, `dom`/`libxml` and `sodium` (or WordPress's polyfill). `intl` and `zlib` are used when present.

With Composer (in a Composer-managed site):

```sh
composer config repositories.llm-json-php vcs https://github.com/IndianOpinion/llm-json-php
composer config repositories.yt-to-post vcs https://github.com/IndianOpinion/yt-to-post
composer require indianopinion/yt-to-post
```

Without Composer: download the release zip (it bundles `llm-json-php` under `lib/`), unzip it into `wp-content/plugins/yt-to-post/` and activate it. A plain checkout works too: run `composer install --no-dev` inside it, or put llm-json-php at `lib/llm-json-php/`. `autoload.php` loads either.

As a must-use plugin: copy the folder to `wp-content/mu-plugins/yt-to-post/` and `examples/mu-plugin-loader.php` to `wp-content/mu-plugins/yt-to-post-loader.php`. Must-use plugins never run activation hooks, so the jobs table is created on the first admin page load.

## Quick start

1. In your own Google Cloud project, enable the YouTube Data API v3 and create an OAuth client of type "Web application". Register the redirect URI shown on **Settings > YouTube to draft** (it is `wp-admin/admin-post.php?action=yt_to_post_oauth` on your site).
2. Put the client in `wp-config.php` (preferred) or the settings screen:

   ```php
   define('YT_TO_POST_CLIENT_ID', '...apps.googleusercontent.com');
   define('YT_TO_POST_CLIENT_SECRET', '...');
   define('YT_TO_POST_LLM_API_KEY', '...');
   ```

3. On the settings screen, set the model provider and a model chain, then **Connect a YouTube account**. Use the channel whose videos you will write about.
4. Go to **Tools > Video to draft**, paste a link, and press **Create draft**. For a video from another channel, tick "I have the rights to use this video". For a video you cannot download captions for, paste or upload a transcript.
5. When the job is done, the draft is in **Posts > Drafts** with a notes box showing what it was built from.

Without a Google project at all, supply a transcript: the title and channel for the credit then come from YouTube's public oEmbed endpoint.

With WP-Cron disabled, run the queue from a real cron:

```cron
* * * * * cd /var/www/site && wp yt-to-post run --time=50 --quiet
```

## Configuration

Every setting is on **Settings > YouTube to draft**, validated on save. Constants win over options and show read-only on the screen.

| Setting | Default | Notes |
|---|---|---|
| `enabled` | on | Kill switch. `YT_TO_POST_DISABLED` true forces it off. |
| `client_id`, `client_secret` | none | OAuth client. Constants `YT_TO_POST_CLIENT_ID`, `YT_TO_POST_CLIENT_SECRET`. |
| `api_key` | none | Metadata only (`videos.list`), never captions. `YT_TO_POST_API_KEY`. |
| `model_kind` | `openrouter` | `openrouter`, `openai` or `generic` (OpenAI-compatible). |
| `model_ids` | none | Comma-separated chain, tried in order. Nothing runs until set. |
| `model_base_url` | none | https, for `generic` or a custom endpoint. |
| `llm_api_key` | none | `YT_TO_POST_LLM_API_KEY`. |
| `model_token_cap`, `model_temperature` | 4096, 0.3 | |
| `default_status` | `draft` | `draft` or `pending` only. |
| `default_author` | none | Needed for WP-CLI jobs without `--user`. |
| `default_category`, `default_tags` | none | |
| `article_language` | `site` | `source`, `site` or a BCP-47 tag. |
| `site_country` | from the locale | For region warnings. |
| `keep_annotations` | off | Keep `[Music]`, `(applause)` and similar. |
| `topics_as_tags` | off | Otherwise suggested topics go in the draft notes. |
| `allow_private_own` | off | Allow your own channel's private videos. |
| `privacy_embed` | off | Use the youtube-nocookie.com player instead of the WordPress embed. |
| `allow_playlists` | off | A playlist link makes one job per video. |
| `max_playlist_items` | 25 | 1 to 50. |
| `max_videos_per_day` | 10 | 0 to 500, site day. `YT_TO_POST_DAILY_VIDEOS` overrides. 0 means no new work. |
| `max_model_calls_per_day` | 200 | 0 to 5,000, retries included. |
| `youtube_quota_budget` | 9,000 | Units per Pacific day (the default project quota is 10,000). |
| `max_duration_minutes` | 240 | Longer videos need the override tick. |
| `max_chunks` | 30 | More need the override tick. |
| `single_pass_tokens`, `chunk_tokens` | 12,000, 8,000 | Estimated tokens. |
| `min_words` | 150 | Shorter transcripts are refused. |
| `max_transcript_bytes` | 5 MiB | |
| `max_job_attempts` | 3 | |
| `tick_seconds` | 25 | WP-Cron runner time per tick. |
| `retention_days` | 30 | 1 to 90. Transcript and notes in finished jobs; rows go after twice this. |
| `api_data_days` | 30 | 1 to 30. Stored YouTube API data (video details). |
| `api_data_on_expiry` | `purge` | `purge` or `refresh` (one `videos.list` call each). |
| `draft_transcript_days` | 0 | Removes the transcript kept on drafts after this many days; 0 keeps it. |

Other constants: `YT_TO_POST_DISABLE_CRON_RUNNER` (WP-Cron does housekeeping only; run jobs with WP-CLI).

## How it works

1. **Input.** The link is parsed without any network call; only exact YouTube hosts are accepted and the 11-character id is the identity. A draft or running job for the same id is returned instead of a new one unless you force it.
2. **Metadata.** `videos.list` (1 unit) with your token or API key. Live, upcoming, processing and other people's private videos are refused; unlisted needs a tick; age, kids and region limits become warnings. Videos from another channel wait for the rights tick.
3. **Captions.** `captions.list` (50 units) and `captions.download` (200 units) as WebVTT, then SRT, then the original. A 403 (not the owner) or no usable track puts the job in "needs transcript", without spending the download.
4. **Normalise.** The format is detected from the content; encodings are checked; TTML is read without entities, network or a DOCTYPE. Tags, annotations and rolling-caption repeats are cleaned.
5. **Write.** Short transcripts take one model call. Long ones are split into chunks (on chapter starts when there are chapters), each chunk is turned into notes and saved at once, and a reduce call writes the article from the notes. Source text only ever goes in the user message, fenced as untrusted data.
6. **Check.** The output must fit the schema. Every quote must appear word for word in the transcript and takes its time from the cue where it begins; others are dropped and counted. The article language is checked by the letters, with one retry.
7. **Draft.** Block markup: embed, standfirst, sections with "Watch from" links, key points, quotes, credit. `wp_insert_post` with status forced to draft or pending after every filter.

Before every paid call the kill switch, the daily caps, the `yt_to_post_can_run` filter and (when present) spend-meter are read fresh. A held job resumes on its own when the reason clears.

## Hooks, REST and WP-CLI

Functions: `yt_to_post_create()`, `yt_to_post_get_job()`, `yt_to_post_find_by_video()`, `yt_to_post_supply_transcript()`, `yt_to_post_confirm_rights()`, `yt_to_post_cancel()`, `yt_to_post_retry()`, `yt_to_post_run()`, `yt_to_post_parse_video_id()`, `yt_to_post_is_enabled()`. `YT_TO_POST_VERSION` and the `yt_to_post_loaded` action tell you it is there.

Actions: `yt_to_post_job_created`, `yt_to_post_job_state`, `yt_to_post_needs_transcript`, `yt_to_post_draft_created`, `yt_to_post_job_failed`, `yt_to_post_connection_changed`.

Filters: `yt_to_post_select_track`, `yt_to_post_estimate_tokens`, `yt_to_post_bytes_per_token` (per-model ratio), `yt_to_post_chunk_tokens`, `yt_to_post_system_prompt`, `yt_to_post_article_schema`, `yt_to_post_article`, `yt_to_post_content`, `yt_to_post_credit_html`, `yt_to_post_post_args`, `yt_to_post_daily_caps` (lower only), `yt_to_post_can_run`, `yt_to_post_capability_create`, `yt_to_post_capability_settings`, `yt_to_post_retention_days`, `yt_to_post_model_caller` (plug in your own model, see `examples/custom-model-caller.php`), `yt_to_post_model_routes`, `yt_to_post_integration_hooks`.

REST (`yt-to-post/v1`, logged in, create capability, REST nonce): `POST /jobs`, `GET /jobs/<id>`, `POST /jobs/<id>/transcript`, `POST /jobs/<id>/retry`, `POST /jobs/<id>/cancel`, `POST /estimate`.

WP-CLI: `wp yt-to-post create|run|list|show|transcript|retry|cancel|status|enable|disable|estimate|parse|purge`. Exit codes: 0 success, 1 error, 3 disabled, 4 cap or quota reached, 5 needs transcript.

## YouTube API policy and your data

Read Google's [YouTube API Services Terms of Service](https://developers.google.com/youtube/terms/api-services-terms-of-service) and [Developer Policies](https://developers.google.com/youtube/terms/developer-policies) for your own use, including the limits on storing API data. They change; check the current text. By default the plugin keeps stored video details for 30 days and then purges them, or refreshes them if you choose; it never purges anything outside the settings above. Captions you download for your own channel's videos are kept on the draft as private meta for checking quotes until `draft_transcript_days` removes them.

The OAuth scope `youtube.force-ssl` is what caption download requires, and it can manage the account. The plugin only calls `videos.list`, `channels.list`, `captions.list`, `captions.download` and, with playlists on, `playlistItems.list`. Connect a channel account used for this purpose. A Google project left in "testing" status loses its refresh token after seven days; the plugin then asks you to reconnect.

Transcripts can hold personal data about the people speaking. They go to your configured model provider and nowhere else. The plugin adds a paragraph to the privacy policy guide.

## Limitations

- Caption download works only for videos the connected account owns or may edit. For other videos you supply the transcript.
- No audio transcription and no video download.
- No machine translation through YouTube; the model translates when the article language differs.
- The token estimate is bytes divided by three (adjustable per model); it is an estimate.
- Counters and locks use options, which are not atomic; the site lock keeps one runner at a time.
- The privacy-enhanced player is an HTML block; users without `unfiltered_html` who edit the draft may see it stripped.
- The gen-queue and spend-meter hook names follow those projects' published interfaces and can be renamed with `yt_to_post_integration_hooks`.

## FAQ

**Can it publish automatically?** No. Status is forced to draft or pending after every filter.

**Why do I need my own Google project?** The plugin uses only the official API with your credentials. Quota and consent are yours.

**The job says "needs transcript".** The account cannot download that video's captions, or there are none. Upload a WebVTT, SRT, SBV, TTML or text file from the job screen and the job resumes without another metadata call.

**A quote I expected is missing.** It was not found word for word in the transcript, so it was dropped; the draft notes give the count.

**How do I stop everything now?** `wp yt-to-post disable`, the switch on the settings screen, or `define('YT_TO_POST_DISABLED', true);`.

## Licence

MIT. Copyright (c) 2026 Indian Opinion. See [LICENSE](LICENSE).
