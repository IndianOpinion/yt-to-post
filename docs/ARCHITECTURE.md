# yt-to-post architecture

This file describes how the plugin is built and records every decision taken
while building it from `docs/PRD.md`, including the owner's rulings on the
PRD's open questions.

## Modules

| Namespace | Role |
|---|---|
| `Video` | `VideoIdParser` (links to ids, pure), `DurationParser`, `ChapterParser`, `VideoMeta` (the only stored fields), `MetadataGate` (PRD cases 9 to 15). |
| `YouTube` | `YouTubeClient` (the only Data API and oEmbed calls), `OAuthClient` (PKCE flow, refresh, revoke), `TokenVault` (secretbox), `Connection`, `AccessTokens`, `CaptionTrack`, `TrackSelector`. |
| `Transcript` | One parser per format behind `Parser\TranscriptParser`, `TranscriptReader` (detection, encoding, malformed rule), `TranscriptCleaner`, `WordCounter`, `Transcript` and `Cue`. |
| `Chunking` | `TokenEstimator` (bytes / ratio), `Chunker`. |
| `Article` | `Schemas`, `PromptBuilder`, `ArticleValidator`, `QuoteVerifier`, `LanguageCheck`, `TextSanitizer`, `Article`. |
| `Model` | `ModelCaller` interface, `LlmJsonCaller` (default, llm-json-php), `CallableCaller`. |
| `Draft` | `BlockBuilder`, `CreditBuilder`, `DraftWriter`. |
| `Limits` | `KillSwitch`, `DailyCaps` (site day), `QuotaMeter` (Pacific day). |
| `Jobs` | `Job`, `JobState`, repositories (`WpdbJobRepository`, `MemoryJobRepository`), `SchemaInstaller`, `Pipeline` (one unit of work), `Runner` (ticks and locks), `JobService` (create, supply, confirm, cancel, retry, estimate), `Housekeeping` (retention), `Scheduler`, `SiteLock`. |
| `Integrations` | `GenQueue`, `SpendMeter`, `Hooks` (their hook names, filterable). |
| `Admin`, `Rest`, `Cli` | Screens and handlers, REST routes, WP-CLI commands. |
| `Plugin`, `Uninstaller`, `functions.php` | Wiring, uninstall, global wrappers. |

`Services` holds the collaborators (repository, clock, HTTP client, sleeper,
token vault, model caller). Tests build one with fakes; nothing in `src`
calls `time()`, `sleep()` or the network directly except through it.

## Data flow

```
link -> VideoIdParser -> JobService::create -> jobs table (queued)
Runner tick -> Pipeline::advance, one unit at a time, saved after each:
  metadata  videos.list (or oEmbed) -> MetadataGate -> chapters -> rights check
  captions  captions.list -> TrackSelector -> captions.download (vtt, srt, original)
  normalise TranscriptReader -> TranscriptCleaner -> min words -> chunk ranges
  map       one model call per chunk, notes saved per call
  reduce    grouped merges (at most two levels) -> article call -> validate
            -> language check (one retry) -> QuoteVerifier -> filter
  draft     BlockBuilder + CreditBuilder -> DraftWriter (status forced) -> done
```

Side states: `needs_transcript`, `needs_auth`, `needs_rights`, `held_disabled`,
`held_cap`, `held_quota`, `held_account`, `failed`, `cancelled`. A held job
keeps the step it resumes at in `step`.

## Rulings on the PRD's open questions

| # | Question | Ruling and implementation | Reason |
|---|---|---|---|
| D1 | Work with no Google project? | Yes, only with a supplied transcript. Title and channel come from YouTube's public oEmbed endpoint (`www.youtube.com/oembed`), never typed by the editor. The credit then has no date and links the channel URL oEmbed gives. If oEmbed has no answer (private, missing, embedding off) the job fails asking for an account or key. | The credit must rest on data from YouTube, not on unverified input. |
| D2 | "Rights confirmed" tick for other channels? | Yes. A video whose channel is not the connected channel (or any video in oEmbed mode) stops in `needs_rights` after metadata unless the tick was given; "Confirm rights" resumes it. The tick and the user who gave it are recorded on the job and in the draft notes. | The editor states the rights decision explicitly and it is on record. |
| D3 | Privacy-enhanced player? | Opt-in setting `privacy_embed`, off by default. When on, the draft starts with an HTML block holding a youtube-nocookie.com iframe; otherwise the standard `core/embed` block. | Core's oEmbed block is the safest default; the iframe needs `unfiltered_html` to survive later edits. |
| D4 | Retention of stored API data | Follows the YouTube API Services policy: `api_data_days` (default 30, range 1 to 30). When due, housekeeping either purges the stored video details and chapters (`purge`, default) or refreshes them with `videos.list` (`refresh`, at most 100 a day). A failed refresh retries the next day and never purges. Every purge or refresh is logged on the job. The README tells the operator to check the current policy. Nothing is purged outside these settings. | Policy compliance without silent data loss. |
| D5 | Transcript on the draft? | Yes: the cleaned transcript with `[m:ss]` stamps is stored as private meta `_yt_to_post_transcript` and shown in the notes box. Only `draft_transcript_days` (default 0, keep) removes it. Uninstall, which removes all plugin data on the operator's explicit request, also removes it. | Editors need it to check quotes; removal is the operator's choice. |
| D6 | Playlists | Opt-in `allow_playlists`. A playlist link (`/playlist?list=`) becomes one job per video via `playlistItems.list` (one page, `max_playlist_items`, default 25, at most 50). Each job is subject to the daily video cap when it starts its first model call, so extra jobs wait as `held_cap`. A watch link with `list=` still uses only its video (case 2). | Bulk input without bypassing caps. |
| D7 | Token estimate | Bytes divided by three by default, with the `yt_to_post_bytes_per_token` filter for a per-model ratio (0.5 to 16) and `yt_to_post_estimate_tokens` to replace the estimate. | Simple, errs safe for non-Latin scripts. |
| D8 | YouTube machine translation (`tlang`) | Not offered in 0.1.0. `tlang` is never sent and the `youtube_translate` setting does not exist; the model translates when the article language differs. | It compounds errors on automatic captions. |
| D9 | Thumbnail as featured image | Offered as a suggestion only, and only when the video is from the connected channel or the rights tick was given. The best `i.ytimg.com` thumbnail URL is stored on the draft; the notes box has a button (nonce, `edit_post`) that sideloads it and sets it as the featured image. | The editor accepts it explicitly, behind the rights decision. |

## Decisions taken by the builder

1. **Model calls through a `ModelCaller` interface.** `LlmJsonCaller` wraps llm-json-php (`indianopinion/llm-json-php`, Composer `vcs` dependency, bundled in the release zip, never committed). The `yt_to_post_model_caller` filter accepts a `ModelCaller` or a callable, so a site can run without the dependency. Credit exhaustion is mapped to `AccountHoldException`, so the pipeline never depends on library classes.
2. **`source_language` in the article schema.** The base article schema adds a required `source_language` (the language the model hears). It records the transcript language when nothing else gives it (case 41), and keeps strict mode possible because every field stays required.
3. **Retries (case 77).** "Up to three attempts with 2, 4, 8 seconds" is read as three retries after the first request, so all three bases are used, with up to 25 per cent jitter and `Retry-After` honoured up to 60 seconds. Each attempt reserves its quota units, since YouTube charges failed requests too.
4. **Overlapping cues (case 40).** Rolling automatic captions overlap legitimately, so "overlapping" is counted as a cue that starts before the previous cue's start (going backwards). End before start and unparsable times also count.
5. **Annotations (case 44).** Square-bracket text is always an annotation. Parenthesised text is treated as one when it is at most 30 characters with no digits, to keep real parenthetical speech.
6. **De-duplication (case 45)** compares whole words, case and space folded, so a cut never falls inside a word.
7. **Speaker-change marks** (`>>`, leading `- `) start a new cue flagged as a turn; prompts render it as "(new speaker)". No names are invented.
8. **XML that is not TTML** (root other than `tt`) is refused rather than read as plain text.
9. **Veto and spend-meter deny** hold the job as `held_cap` for one hour with the reason in `last_error`; no money is spent and no attempt is counted.
10. **Create refuses early** (before any call) when there is no OAuth, no API key and no transcript, rather than creating a job that fails at once (case 7). It also refuses when the video cap is 0. A cap reached today does not refuse: jobs wait.
11. **Dedup (case 5).** A running or waiting job is always returned, even with force. A `done` job whose draft exists is returned unless forced. A failed or cancelled job, or a deleted or trashed draft, allows a new job without force. If a draft exists with no job record (purged), create returns that post and `yt_to_post_create()` returns 0.
12. **Wrong language** fails the job without the runner's retries (case 63 says a second miss fails the job). Invalid video and invalid transcript also fail without retry.
13. **Status forcing (case 67).** A filter may switch between `draft` and `pending`; any other status goes back to the requested one. `post_date`, `post_date_gmt`, `edit_date`, `ID` and `import_id` are removed, `post_name` is empty.
14. **Account hold (case 73)** stores the hold time in an option, moves every active job to `held_account` with its step kept, and checks the option before each model call.
15. **Cancellation races.** The pipeline re-reads the job before each external call and before saving; a cancel made while a step runs wins.
16. **Locks.** The site lock is an option written with `add_option` (an atomic insert) holding its expiry; an expired lock is taken over. The per-video lock is a 15-minute transient refreshed each step.
17. **REST nonce.** Every route checks `X-WP-Nonce` in its permission callback, except requests authenticated by an application password, which carry none.
18. **Soft integration hook names.** gen-queue: `gen_queue_soft_enqueue` (accepted when it returns anything other than null, false or a `WP_Error`) and `gen_queue_register_job_type`; spend-meter: `spend_meter_pre_spend`, `spend_meter_record`, `spend_meter_release`. They are written from the PRD's description and can be renamed with `yt_to_post_integration_hooks` if those projects publish different names.
19. **Time.** Caps use the site timezone day, quota the `America/Los_Angeles` day, so DST is handled by the timezone database.
20. **Storage.** The normalised transcript is stored gzip-compressed and base64-encoded when zlib is present, JSON otherwise. Raw caption or supplied text sits in `source` only until normalising.
21. **Static analysis** runs at PHPStan level 6 with WordPress stubs, as the brief allows for plugins.

## Lessons from production, where they live

| Lesson | Code |
|---|---|
| Verify quotes, time from the source | `QuoteVerifier` |
| Check the language by letters | `LanguageCheck`, `Language::foreignShare` |
| Force the status after filters | `DraftWriter::write` |
| Read switch and caps fresh before paid calls | `Pipeline::callModel`, `Options::fresh` |
| Save after every paid step | `Pipeline::mapStep`, `reduceStep` |
| Quota on the provider's clock | `QuotaMeter`, `Days::pacificKey` |
| Detect `invalid_grant`, never loop | `OAuthClient::refresh` |
| Supply a transcript as a first-class path | `JobService::supplyTranscript` |
| De-duplicate before counting | `TranscriptCleaner::dedupe` before `WordCounter` |
| Budget by bytes | `TokenEstimator` |
| Never split inside UTF-8 | `Utf8::cut`, `Chunker::bestPrefix` |
| Check columns after dbDelta | `SchemaInstaller::install` |
| Source text is data | `PromptBuilder::fence` |
| Leave slugs to WordPress | `DraftWriter` sets `post_name` empty |
| Hold, do not fail, on credit | `Pipeline::holdAccount` |
