# yt-to-post: product requirements

Status: draft for build, 14 November 2026. Version target: 0.1.0.
Package: WordPress plugin `yt-to-post`, Composer name
`indianopinion/yt-to-post`, namespace `IndianOpinion\YtToPost`.

Written from scratch; not derived from any third-party plugin.

yt-to-post turns one YouTube video into one WordPress draft. It gets the
words from captions downloaded through the official YouTube Data API v3
under the site's own OAuth connection, or from a transcript file the editor
supplies. A language model then writes a structured article from those
words. The draft embeds the video and credits the source. A human editor
reads, fixes and publishes it. The plugin never publishes.

This PRD is the only input the builder gets. It names public YouTube and
WordPress interfaces by their documented names. Every other name in it
(functions, hooks, options, tables, commands) is new and belongs to this
plugin.

## 1. Problem

Editors who cover video (interviews, speeches, briefings, explainers,
their own channel's uploads) write the same article by hand each time:
watch the video, find the quotes, note the timestamps, write it up, embed
the player, add the credit. A one-hour video takes an editor most of a
morning.

The obvious shortcuts are wrong or fragile:

- Scraping a watch page or using an unofficial transcript library breaks
  YouTube's terms, breaks without warning when the page changes, and can
  get the site's server blocked. This plugin never does either.
- Pasting a whole transcript into a chat model fails on long videos (the
  context fills, the end of the talk is dropped), invents quotes that the
  speaker never said, answers in the wrong language for non-English speech,
  and forgets the embed and the credit.
- Auto-generated captions have no punctuation, repeat lines, and carry
  noise such as `[Music]`. Fed raw to a model they give a poor article.
- Tools that publish straight away put unchecked text on the site.

Measured result to aim for, checked in acceptance test T-PERF: for a
60-minute talk with captions, an editor gets a reviewable draft with the
video embedded, chapter-aligned sections, verified quotes with working
timestamp links and a credit line, in under five minutes of wall time,
with every quote in the draft present word for word in the transcript.

## 2. Goals and non-goals

### Goals

1. One video in, one draft out, with the video embedded at the top and a
   source credit that names the video, the channel and the date and links
   to both.
2. Words come from exactly two sources: (a) caption tracks downloaded with
   `captions.list` and `captions.download` under the operator's own OAuth
   client and the connected account's consent, or (b) a transcript the
   editor supplies as a file or pasted text.
3. A structured article: title, standfirst, sections with headings, key
   points, verified quotes with timestamps. The shape is a JSON schema
   (section 5.8). Prompts are generic and filterable.
4. Chapters from the video description shape the sections when present.
5. Long videos work by chunking with a map and reduce pass, with
   checkpoints so a failure never pays twice for the same chunk.
6. Videos without usable captions get a clear "needs transcript" state and
   a way to supply one and resume.
7. Non-English speech works: the transcript language is known or chosen,
   and the article language is chosen separately.
8. Drafts only. A daily cap and a kill switch in the plugin's own settings.
   Soft integration with `gen-queue` and `spend-meter` when present, and
   complete without them.
9. Plain, safe operation: capability checks, nonces, encrypted tokens,
   escaped output, no shell, no eval.

### Non-goals

1. Fetching, parsing or scraping any YouTube web page, including watch
   pages, embed pages and timed-text endpoints that are not part of the
   documented Data API. Using any unofficial transcript library or
   downloader.
2. Downloading video or audio, or transcribing audio. A video with no
   captions needs a transcript from the editor. (A sibling project that
   transcribes an audio file the editor holds can produce that transcript;
   this plugin only reads the resulting file.)
3. Publishing, scheduling, or setting any status other than `draft` or
   `pending`.
4. Playlists, channels or feeds as input. One video per job (see open
   question 6).
5. Using the video thumbnail as a featured image. Rights are unclear and
   the editor chooses images (see open question 9).
6. Deciding whether the site has the right to publish an article based on a
   video. The plugin records who asked; rights are the editor's call.
7. Uploading or editing captions on YouTube. The plugin is read-only
   against YouTube.
8. Any editorial policy, house style or topic list. Prompts ask for a
   neutral, factual article and nothing more.
9. Comment, like, view or subscriber data. None is requested.

## 3. Users and setting

- **Site administrator** installs the plugin, creates an OAuth client in
  their own Google Cloud project with the YouTube Data API v3 enabled,
  enters the client id and secret (preferably as constants), connects the
  account, and configures the language model provider, caps and defaults.
- **Editor** (anyone with the create capability, default `edit_posts`)
  pastes a video URL, optionally supplies a transcript, picks languages,
  and gets a draft in their name.
- **Operator at a shell** uses WP-CLI to create jobs, run the queue, check
  status and flip the kill switch.
- Runs inside WordPress 6.4 or later on PHP 8.1 or later, on ordinary
  shared or virtual hosts. Work happens in background jobs driven by
  WP-Cron, by a real cron calling WP-CLI, or by `gen-queue` when present.
  Nothing runs on front-end requests.
- The connected Google account is usually the site's own channel. Caption
  download through the Data API works only for videos that account owns or
  may edit. For other people's videos the editor supplies the transcript.
  The admin screens say this plainly.

## 4. Behaviour

Cases are numbered and stable. Section 12 maps each case to a test.

### 4.1 Input and video identity

1. The editor gives a video reference. Accepted forms, after trimming
   whitespace: `https://www.youtube.com/watch?v=ID` (with or without
   `www.`, on `m.youtube.com` and `music.youtube.com`, with any other query
   parameters in any order), `https://youtu.be/ID`, `/shorts/ID`, `/live/ID`,
   `/embed/ID`, `/v/ID`, `https://www.youtube-nocookie.com/embed/ID`, plain
   `http` variants, and a bare 11-character id. The id matches
   `^[A-Za-z0-9_-]{11}$`.
2. A time parameter (`t=`, `start=`, `#t=`) in the URL is ignored for the
   article and recorded on the job only. A `list=` parameter is ignored and
   the single video in `v` is used; the screen says playlists are not
   supported.
3. Anything else is refused before any network call with "Not a YouTube
   video link": other hosts (including look-alike hosts such as
   `youtube.com.example.org`), channel or playlist URLs without a video id,
   ids of the wrong length or alphabet, `javascript:` and `data:` URLs,
   strings over 2,048 bytes.
4. The canonical form of every video is its id. All dedup and locking keys
   use the id, never the URL.
5. If a draft or job for the same id already exists on this site, the
   request returns the existing job or post and says so. The editor may
   force a new job; a forced job always creates a new draft and never
   overwrites an existing post, edited or not.
6. Only one job per video id runs at a time (a lock keyed by the id with a
   time-to-live of 15 minutes, refreshed on each step). A second request
   while one runs returns the running job.

### 4.2 Metadata

7. The first network call is `videos.list` with parts `snippet`,
   `contentDetails`, `status` and `liveStreamingDetails` for the id (1
   quota unit). It uses the OAuth token when connected, or an API key when
   one is configured and no token exists. With neither, the job fails with
   "Connect a YouTube account or add an API key" before any call.
8. An empty `items` list means the video does not exist, was removed, or is
   private to another account: the job fails with "Video not found or not
   visible to the connected account". No retry.
9. `snippet.liveBroadcastContent` of `live` or `upcoming` (a stream or a
   premiere not yet finished): the job is refused with "Wait until the
   broadcast has ended". It is not queued for later.
10. `status.uploadStatus` other than `processed`: refused with "Video is
    still processing on YouTube, try again later".
11. `status.privacyStatus` of `private`: refused unless the video belongs to
    the connected channel and the setting `allow_private_own` is on
    (default off), because the embed would not play for readers.
12. `status.privacyStatus` of `unlisted`: allowed only when the video belongs
    to the connected channel, or the editor ticks "I know this video is
    unlisted" on the form (or passes `--allow-unlisted`). The draft notes
    carry a warning either way.
13. `status.embeddable` false: the job continues, but the draft has no
    embed block. It gets a linked thumbnail-free notice paragraph ("Watch
    the video on YouTube") at the top instead, and the draft notes say why.
14. Age restriction (`contentDetails.contentRating.ytRating` of
    `ytAgeRestricted`), `status.madeForKids` true, and a
    `contentDetails.regionRestriction` that blocks or omits the site's
    configured country are recorded as warnings in the draft notes. None
    stops the job.
15. Duration is parsed from `contentDetails.duration` (ISO 8601, for
    example `PT1H2M3S`, `P1DT2H`, `PT0S`). A zero duration is treated as
    unknown. A duration above `max_duration_minutes` (default 240) is
    refused unless the editor overrides it, with the estimated number of
    model calls shown first.
16. The plugin keeps from the response only: id, title, channel id, channel
    title, published time, description, default language, default audio
    language, duration, privacy status, embeddable, caption flag,
    live-broadcast state and the warning fields above. Nothing else is
    stored.
17. When OAuth is connected, the plugin learns the connected channel id once
    at connect time with `channels.list` (`mine=true`, 1 unit) and stores
    it. A video "belongs to the connected channel" when its
    `snippet.channelId` equals that id.

### 4.3 Chapters

18. Chapters come from the video description. A line holds a chapter when it
    starts (after optional bullets, dashes or brackets) with a timestamp
    `m:ss`, `mm:ss`, `h:mm:ss` or `hh:mm:ss`, followed by a title. Timestamps
    in the middle of a line are ignored.
19. The list is valid only when it follows YouTube's published chapter rule:
    the first chapter starts at `0:00`, there are at least three chapters,
    times strictly increase, and each chapter is at least 10 seconds long.
    An invalid list is ignored as a whole (no partial chapters) and the
    draft notes say "chapters ignored: <reason>".
20. A chapter time beyond the video duration invalidates the list.
21. Chapter titles are trimmed, have leading separators (`-`, `|`, `:`,
    `.`) removed, and are capped at 120 characters. Empty titles invalidate
    the list.
22. A supplied transcript may carry its own chapter list in a sidecar field
    on the form or a `--chapters=<file>` flag, in the same line format.
    When valid it takes precedence over the description.
23. With valid chapters, chunk boundaries align to chapter boundaries
    (case 47) and the prompt asks for one section per chapter or per group
    of short chapters, with the chapter title as a hint, not a required
    heading.
24. The described chapter titles are untrusted text and go to the model
    only inside the user content, never in the system instruction.

### 4.4 Caption tracks from the Data API

25. With OAuth connected and the transcript not supplied, the plugin calls
    `captions.list` for the video (50 units). It never calls caption
    endpoints with an API key alone.
26. Track choice, in order: drop tracks whose `status` is not `serving`,
    drop `isDraft` true, drop `trackKind` `forced`, drop `audioTrackType`
    `commentary` and `descriptive`. From the rest prefer, in this order:
    the language the editor picked; the video's `defaultAudioLanguage`;
    the video's `defaultLanguage`; then `standard` over `ASR`; then
    `primary` audio over `unknown`; then the most recently updated. The
    filter `yt_to_post_select_track` may override the choice from the
    candidate list.
27. Language matching compares BCP-47 tags case-insensitively, first on the
    full tag (`pt-BR`), then on the primary subtag (`pt`). `zh-Hans` and
    `zh-Hant` count as different full tags but the same primary subtag.
28. The chosen track is downloaded with `captions.download` (200 units),
    asking for `tfmt=vtt`. If YouTube answers `couldNotConvert`, it asks for
    `srt`, then for the original format, and detects the format from the
    body (case 36). It never asks for `scc`.
29. A `403 forbidden` on download means the account may not download that
    track (usual for other people's videos). The job moves to
    `needs_transcript` with "Captions for this video can only be downloaded
    by its owner. Supply a transcript to continue." No retry.
30. A `404 captionNotFound` lists the tracks once more and tries the next
    best track. A second miss moves the job to `needs_transcript`.
31. No candidate tracks (none listed, or all dropped by case 26), or
    `contentDetails.caption` is `"false"` and the list is empty: the job
    moves to `needs_transcript` with the reason, without spending the 200
    units of a download.
32. Machine translation by YouTube (`tlang`) is off by default. When the
    setting `youtube_translate` is on and the chosen track's language
    differs from the article language, the plugin asks for `tlang` set to
    the article language's two-letter code. If that fails with
    `couldNotConvert`, it downloads the original track and lets the model
    translate.
33. An `ASR` track is marked as auto-generated on the job and in the draft
    notes, and the prompt says the text is automatic speech recognition
    with no reliable punctuation or names.
34. The owner's videos in a language that YouTube lists only as an `ASR`
    track still go through; a video with only `forced` tracks goes to
    `needs_transcript`.

### 4.5 Supplied transcripts

35. The editor may upload a file (`.vtt`, `.srt`, `.sbv`, `.ttml`, `.xml`,
    `.dfxp`, `.txt`) or paste text, up to `max_transcript_bytes` (default
    5 MiB). A supplied transcript always wins over captions; no caption
    calls are made.
36. Format is detected from content, not the file name: `WEBVTT` header is
    WebVTT; numbered blocks with `-->` and comma milliseconds is SRT;
    `h:mm:ss.mmm,h:mm:ss.mmm` lines is SBV; an XML root `tt` is TTML;
    lines that start with `[h:mm:ss]`, `(m:ss)` or `h:mm:ss` followed by
    text are timestamped plain text; anything else is plain text. A
    `Scenarist_SCC` header is refused with "SCC is not supported, export
    WebVTT or SRT".
37. Encoding: UTF-8 with or without a byte order mark is accepted. UTF-16
    with a byte order mark is converted to UTF-8. Anything that is not valid
    UTF-8 after that is refused with the byte offset of the first bad
    sequence. No guessing of legacy code pages.
38. TTML is parsed with external entities and network access disabled and a
    node limit of 200,000. A document type declaration is refused.
39. A plain-text transcript without timestamps works, but the article has
    no timestamp links and quotes carry no time. The form says so.
40. Malformed cues (end before start, unparsable time, overlapping times)
    are skipped and counted. If more than half of the cues are malformed
    the transcript is refused with the count and the first bad line number.
41. The editor states the transcript language, or chooses "same as video",
    which uses `defaultAudioLanguage`, then `defaultLanguage`. If neither
    exists the model is asked to report the language in its output and the
    job records what it said.
42. A supplied transcript for a video in `needs_transcript` resumes that job
    at the normalising step. No metadata call is repeated.

### 4.6 Normalising the transcript

43. Every source becomes one internal list of cues: start seconds (float or
    null), end seconds (or null), speaker (or null), text.
44. Cleaning, in order: remove WebVTT `NOTE`, `STYLE` and `REGION` blocks
    and cue settings; strip inline tags (`<c>`, `<i>`, `<b>`, `<u>`,
    `<ruby>`, `<lang>`, inline timestamps `<00:00:01.000>`), keeping a
    `<v Name>` voice tag as the speaker; decode HTML entities once; remove
    sound and music annotations in square brackets or parentheses
    (`[Music]`, `(applause)`, `[Laughter]`, `♪` runs) unless the setting
    `keep_annotations` is on; turn leading `>>` and `-` speaker-change marks
    into a speaker change; collapse whitespace.
45. Rolling auto-captions repeat text: each cue often starts with the tail of
    the previous one. Consecutive cues are de-duplicated by removing the
    longest prefix of a cue that equals a suffix of the previous cue's text
    (compared on normalised whitespace and case). Exact duplicate cues
    within two seconds are dropped.
46. After cleaning, a transcript with fewer than `min_words` (default 150;
    counted as whitespace-separated words, or as characters divided by two
    for scripts that do not separate words, such as Chinese, Japanese and
    Thai) is refused with "Transcript too short for an article". A video
    with only music or silence ends here.

### 4.7 Chunking long transcripts

47. The transcript is measured with an estimated token count: UTF-8 byte
    length divided by 3, rounded up. This over-counts Latin text and is
    close for Indic, Arabic and CJK scripts, so it errs safe. The filter
    `yt_to_post_estimate_tokens` may replace it.
48. At or below `single_pass_tokens` (default 12,000) the article is written
    in one model call.
49. Above it, the transcript is split into chunks of at most `chunk_tokens`
    (default 8,000). Boundaries go at chapter starts when chapters exist,
    else at the cue boundary closest to the target size where the gap
    between cues is largest within the last 10 per cent of the chunk, else
    at any cue boundary. A cue is never split. A single cue larger than a
    chunk (common in plain text) is split at sentence ends, then at
    whitespace, then at a character boundary, never inside a UTF-8
    sequence.
50. Each chunk carries its start and end time and the chapter titles it
    spans.
51. Map step: one model call per chunk returns notes in the map schema
    (section 5.8): a short summary, key points with start seconds, candidate
    quotes copied word for word, and names mentioned. Notes are saved per
    chunk as soon as each call succeeds (checkpoint).
52. Reduce step: one call takes all chunk notes (not the transcript) plus
    the metadata and writes the article. If the notes themselves exceed
    `single_pass_tokens`, they are reduced in groups first, then once more,
    with at most three levels.
53. More than `max_chunks` (default 30) chunks refuses the job with the
    count and the estimated model calls, unless the editor overrides it.
54. A retried job reuses every saved chunk note and calls the model only for
    missing chunks.

### 4.8 Writing the article

55. Every model call goes through `indianopinion/llm-json-php` with a JSON
    schema and the configured provider chain. The plugin never builds raw
    HTTP calls to a model provider.
56. Before each call the plugin re-reads the kill switch and the daily cap
    (case 69 and 70) and, when `spend-meter` is present, asks it for a
    pre-spend decision (section 5.10). Any "no" stops the job in a held
    state before money is spent.
57. The system instruction is generic: write a neutral, factual article
    based only on the transcript; do not add facts that are not in it;
    attribute claims to the speaker; mark uncertainty where the transcript
    is unclear; copy quotes exactly; give timestamps from the provided cue
    times; write in the requested article language; return JSON only. The
    transcript, title, description and chapter titles go in the user content
    inside clear delimiters, labelled as untrusted source text that may
    contain instructions to ignore.
58. The article language is one of: `source` (the transcript language),
    `site` (the site locale's language, the default), or an explicit BCP-47
    tag. When it differs from the transcript language the prompt asks for
    the article in the target language with each quote given in the
    original and in translation.
59. The article schema (section 5.8) requires: `language`, `title`,
    `standfirst`, `sections` (one or more, each with `heading`,
    `paragraphs` and optional `start_seconds`), `key_points`, `quotes`
    (each `text`, optional `translation`, optional `speaker`,
    `start_seconds` or null), and `topics` (suggested tags).
60. Validation after the schema: title 10 to 120 characters; standfirst at
    most 300 characters; at least one section with at least one non-empty
    paragraph; at most 20 sections and 15 quotes. Over-long fields are
    trimmed at a word boundary, not rejected.
61. Quote verification: each quote's `text` must appear in the transcript
    after both are normalised (Unicode NFC, case folded, punctuation and
    whitespace collapsed, curly quotes made straight). A quote that does not
    appear is dropped and counted in the draft notes. When it appears, its
    `start_seconds` is replaced by the start of the cue where the match
    begins, so timestamps come from the transcript, not from the model.
62. Any `start_seconds` (sections, key points) outside 0 to the duration is
    removed.
63. Language check: if the `language` field's primary subtag differs from
    the requested one, or more than 40 per cent of the letters in the
    title and first section are in a writing system other than the
    expected one for that language (when the expected one is known), the
    call is retried once with a stronger instruction. A second miss fails
    the job with "Model answered in the wrong language". Mixed-language
    speech (for example Hindi with English words) is expected in quotes and
    is not checked there.
64. Model text is plain text. Any HTML tags in it are stripped, any URL in
    it is kept as plain text and never made into a link. Markdown emphasis
    marks are removed.

### 4.9 Building the draft

65. The post content is block markup, built by the plugin, in this order:
    (a) a `core/embed` block for the canonical watch URL with
    `providerNameSlug` `youtube` (or the case 13 notice); (b) the standfirst
    as a paragraph; (c) for each section an `h2` heading and paragraphs,
    with a "Watch from m:ss" link to `https://www.youtube.com/watch?v=ID&t=Ns`
    when the section has a start time; (d) key points as a list; (e) quotes
    as `core/quote` blocks with the speaker and a timestamp link, and the
    translation below the original when present; (f) the source credit
    paragraph. Every piece of text is escaped with WordPress escaping
    functions for its context.
66. The credit reads, by default: "Source: <video title> by <channel title>,
    published on YouTube on <date in site format>." The video title links to
    the watch URL and the channel title to
    `https://www.youtube.com/channel/<channel id>`. A transcript source line
    follows: "Based on the video's captions", "Based on YouTube's
    automatic captions" or "Based on a transcript supplied by the editor".
    The filter `yt_to_post_credit_html` may restyle it, but if the result
    does not contain the watch URL the default is used and a notice is
    logged. The credit cannot be turned off.
67. The post is created with `wp_insert_post`: status `draft` by default or
    `pending` when configured or asked for; author the requesting user (the
    configured default author for CLI jobs without `--user`); category and
    tags from settings; suggested topics added as tags only when
    `topics_as_tags` is on (default off; otherwise they go in the notes).
    The slug is left to WordPress. The filter `yt_to_post_post_args` may
    change the arguments, but `post_status` is forced back to `draft` or
    `pending` after it runs, and `post_date` and `post_date_gmt` are
    cleared, so no filter can publish or schedule.
68. Post meta written on the draft: video id, source kind (`captions`,
    `captions_asr`, `transcript`), transcript language, article language,
    job id, and the draft notes (warnings, dropped quote count, chunk count,
    model calls, track kind). An editor meta box shows the notes on the
    edit screen. The notes are never in the content.

### 4.10 Caps, switch and quota

69. Kill switch: the option `enabled` (default on after setup) and the
    constant `YT_TO_POST_DISABLED`. When the constant is true the screen
    cannot turn the plugin on. When off: the create form is disabled with a
    message, new requests are refused, and a running job stops before its
    next external call and is held as `held_disabled`. Turning it back on
    lets held jobs resume on the next run. The switch is read fresh (no
    object cache) before each external call.
70. Daily caps, counted per site in the site's timezone day: `max_videos_per_day`
    (default 10, counted when a job starts its first model call) and
    `max_model_calls_per_day` (default 200, counted per model attempt
    including retries). The constant `YT_TO_POST_DAILY_VIDEOS` overrides the
    first. Reaching a cap holds the job as `held_cap` and it resumes the next
    day. A cap of 0 means no new work. The filter `yt_to_post_daily_caps`
    may lower caps but never raise them above the stored or constant value.
71. YouTube quota: the plugin counts units it spends per Pacific Time day
    (the day YouTube's quota resets on), using the documented costs: 1 for
    `videos.list` and `channels.list`, 50 for `captions.list`, 200 for
    `captions.download`. It refuses to start a call that would take the
    day's count above `youtube_quota_budget` (default 9,000, below the
    default project quota of 10,000, to leave room for other users of the
    project). The job is held as `held_quota` until Pacific midnight.
72. A `403 quotaExceeded` or `dailyLimitExceeded` from YouTube sets the day's
    count to the budget at once, holds the job, and shows an admin notice.
    `rateLimitExceeded` and `userRateLimitExceeded` retry with backoff
    (case 77).
73. A model call refused for credit (`CreditExhaustedException` from
    llm-json-php) holds every job as `held_account` for one hour and shows
    an admin notice. It is not counted as a job failure.

### 4.11 OAuth connection

74. Connecting uses the OAuth 2.0 authorization code flow for web server
    applications with PKCE (S256), `access_type=offline`, `prompt=consent`,
    and the scope `https://www.googleapis.com/auth/youtube.force-ssl`, which
    the caption endpoints require. The `state` value is random, single-use,
    bound to the user and valid for ten minutes. The redirect URI is a
    fixed admin URL shown on the settings page for the administrator to
    register in their Google project.
75. On callback: a missing or mismatched `state`, an `error` parameter, or a
    user without the settings capability ends the flow with a message and
    stores nothing. A response without a refresh token (the account had
    already granted access) tells the administrator to remove the app's
    access in their Google account and connect again.
76. Tokens: the refresh token is stored encrypted (section 8). The access
    token is kept in a transient until 60 seconds before its expiry. A `401`
    from YouTube triggers one refresh and one retry. A refresh that returns
    `invalid_grant` (revoked, expired, or a Google project in "testing"
    status, where refresh tokens last seven days) marks the connection
    `needs_reconnect`, moves waiting jobs to `needs_auth`, and shows an
    admin notice to users with the settings capability.
77. Retries for network errors, timeouts, `429`, `5xx`, `rateLimitExceeded`
    and `backendError`: up to three attempts with exponential backoff and
    jitter (2, 4, 8 seconds base), honouring `Retry-After` up to 60 seconds.
    A job step that still fails is retried by the runner later, up to
    `max_job_attempts` (default 3), then the job is `failed` with the last
    error.
78. Disconnecting revokes the refresh token at Google's documented revoke
    endpoint, then deletes it locally even if the revoke call fails (the
    failure is shown). The connected channel id is deleted too.
79. If the WordPress salts change, the stored token cannot be decrypted:
    the connection shows `needs_reconnect`, nothing crashes.

### 4.12 Jobs and running

80. A job moves through: `queued`, `metadata`, `captions`, `normalise`,
    `map`, `reduce`, `draft`, `done`. Side states: `needs_transcript`,
    `needs_auth`, `held_disabled`, `held_cap`, `held_quota`,
    `held_account`, `failed`, `cancelled`. Every change is saved before the
    next external call, so a crash resumes at the last saved step.
81. Without `gen-queue` the plugin runs jobs itself: a single WP-Cron event
    is scheduled when a job is queued; each run works for at most
    `tick_seconds` (default 25) and stops between steps, rescheduling
    itself while work remains. A site-wide lock (option-based, with a
    time-to-live) keeps one runner at a time. WP-CLI `run` uses the same
    runner without the time limit unless given one.
82. With `gen-queue` active, the plugin registers a job type and enqueues
    each job through gen-queue's soft-enqueue filter with the video id plus
    a force counter as the idempotency key. gen-queue's own switch and cap
    apply as well; the plugin's switch, caps and quota still apply first.
    Model costs reported by llm-json-php are passed to the job context.
83. A cancelled job stops before its next external call and keeps its
    saved notes until purge. Cancelling a job in `done` does nothing.
84. A draft deleted by the editor does not affect the job record. Creating
    the same video again then makes a new job without forcing.
85. Any uncaught error in a step is caught at the runner, recorded with its
    class and message on the job, and the job is retried or failed per
    case 77. The runner never lets one job's failure stop other jobs.

### 4.13 Admin screens and access

86. A screen under Tools, "Video to draft", for users with the create
    capability: video URL, optional transcript (file or paste), transcript
    language, article language, status (`draft` or `pending`), category,
    force and override boxes. It shows the estimate before submit when
    metadata has been fetched: duration, captions present, chunk count,
    model calls, quota units.
87. A jobs list on the same screen: video title, state, step, requester,
    created, finished, model calls, quota units, error, link to the draft.
    Editors see their own jobs; users with the settings capability see all.
    Row actions: retry, cancel, supply transcript.
88. A settings screen (settings capability, default `manage_options`):
    connection status and connect or disconnect buttons, the redirect URI,
    API key, model provider and chain, caps, quota budget, defaults, kill
    switch, retention. Fields set by constants show as read-only with the
    constant's name.
89. Every form and row action checks a nonce and the capability. REST
    routes (section 5.5) check the capability in their permission callback.

## 5. Public interface

Everything is under the namespace `IndianOpinion\YtToPost`. Global
functions are thin wrappers with the prefix `yt_to_post_` so other code can
test with `function_exists()`.

### 5.1 Detection

- Constant `YT_TO_POST_VERSION` (semver string) once loaded.
- Action `yt_to_post_loaded` after the plugin has loaded.

### 5.2 Functions

| function | returns | notes |
|---|---|---|
| `yt_to_post_create(string $video, array $args = [])` | `int` job id | args: `transcript` (string), `transcript_language`, `article_language`, `status`, `user_id`, `category`, `chapters` (string), `force`, `allow_unlisted`, `override_limits`. Throws `InvalidVideoException`, `DisabledException`, `CapReachedException` |
| `yt_to_post_get_job(int $id)` | `?Job` | |
| `yt_to_post_find_by_video(string $video_id)` | `?Job` | latest job |
| `yt_to_post_supply_transcript(int $job_id, string $transcript, array $args = [])` | `bool` | resumes a `needs_transcript` job |
| `yt_to_post_cancel(int $id)` / `yt_to_post_retry(int $id)` | `bool` | |
| `yt_to_post_run(array $opts = [])` | `RunSummary` | opts `time`, `max` |
| `yt_to_post_parse_video_id(string $input)` | `?string` | pure, no network |
| `yt_to_post_is_enabled()` | `bool` | fresh read |

### 5.3 Objects

- `Job` (read-only): `id`, `videoId`, `state`, `step`, `userId`,
  `sourceKind`, `transcriptLanguage`, `articleLanguage`, `postId`,
  `attempts`, `modelCalls`, `quotaUnits`, `warnings`, `lastError`,
  `createdAt`, `finishedAt`.
- `Cue`, `Transcript` (list of cues plus language and source kind),
  `Chapter`, `Chunk`, `Article` (validated article), `RunSummary`
  (`claimed`, `done`, `held`, `failed`, `seconds`, `stopReason`).
- Pure services the builder exposes for testing and reuse:
  `VideoIdParser`, `DurationParser`, `ChapterParser`, `TranscriptParser`
  (one per format behind one interface), `TranscriptCleaner`, `Chunker`,
  `QuoteVerifier`, `BlockBuilder`, `TrackSelector`.
- Exceptions, all extending `YtToPostException`: `InvalidVideoException`,
  `InvalidTranscriptException`, `DisabledException`, `CapReachedException`,
  `QuotaException`, `AuthException`, `YouTubeApiException` (HTTP status,
  reason), `ModelOutputException`.

### 5.4 Hooks

Actions:

| action | arguments |
|---|---|
| `yt_to_post_loaded` | none |
| `yt_to_post_job_created` | `Job $job` |
| `yt_to_post_job_state` | `Job $job, string $from, string $to` |
| `yt_to_post_needs_transcript` | `Job $job, string $reason` |
| `yt_to_post_draft_created` | `int $post_id, Job $job, Article $article` |
| `yt_to_post_job_failed` | `Job $job, \Throwable $error` |
| `yt_to_post_connection_changed` | `string $state` (`connected`, `disconnected`, `needs_reconnect`) |

Filters:

| filter | default | purpose |
|---|---|---|
| `yt_to_post_select_track` | plugin's choice | pick another track from the candidates |
| `yt_to_post_estimate_tokens` | bytes / 3 | replace the estimator |
| `yt_to_post_chunk_tokens` | setting | chunk size |
| `yt_to_post_system_prompt` | generic text | change the system instruction (map, reduce, single) |
| `yt_to_post_article_schema` | section 5.8 | add optional fields; required ones stay |
| `yt_to_post_article` | validated `Article` | edit before the draft is built |
| `yt_to_post_content` | block markup | change the content |
| `yt_to_post_credit_html` | default credit | restyle, must keep the watch URL |
| `yt_to_post_post_args` | insert args | change args; status and date forced after |
| `yt_to_post_daily_caps` | stored values | lower only |
| `yt_to_post_can_run` | true | veto a job; cannot override switch or caps |
| `yt_to_post_capability_create` | `edit_posts` | who may create jobs |
| `yt_to_post_capability_settings` | `manage_options` | who may configure and connect |
| `yt_to_post_retention_days` | 30 | purge age for job data |

### 5.5 REST

Namespace `yt-to-post/v1`, all routes need a logged-in user with the create
capability and a REST nonce:

- `POST /jobs` (body as `yt_to_post_create` args plus `video`), returns the
  job.
- `GET /jobs/<id>`, `POST /jobs/<id>/transcript`, `POST /jobs/<id>/retry`,
  `POST /jobs/<id>/cancel`.
- `POST /estimate` with `video`: metadata call only (1 unit), returns
  duration, captions flag, estimated chunks, calls and quota units.

### 5.6 WP-CLI

| command | effect |
|---|---|
| `wp yt-to-post create <video> [--transcript=<file>] [--chapters=<file>] [--lang=<tag>] [--article-lang=<source\|site\|tag>] [--status=draft\|pending] [--user=<id>] [--category=<id>] [--force] [--allow-unlisted] [--override-limits] [--wait]` | create a job; with `--wait` run it and print the post id |
| `wp yt-to-post run [--time=<s>] [--max=<n>]` | run jobs |
| `wp yt-to-post list [--state=<s>] [--limit=50] [--format=table\|json]` | jobs |
| `wp yt-to-post show <id>` | one job with its log lines |
| `wp yt-to-post transcript <id> <file> [--lang=<tag>]` | supply a transcript |
| `wp yt-to-post retry <id>` / `cancel <id>` | |
| `wp yt-to-post status` | switch, caps used today, quota used today, connection state |
| `wp yt-to-post enable` / `disable` | kill switch |
| `wp yt-to-post estimate <video>` | as the REST route |
| `wp yt-to-post parse <file>` | parse and clean a transcript locally, print cues and stats, no network |
| `wp yt-to-post purge [--dry-run]` | retention purge now |

Exit codes: 0 success; 1 error; 3 disabled; 4 cap or quota reached;
5 needs transcript.

### 5.7 Constants

| constant | effect |
|---|---|
| `YT_TO_POST_DISABLED` | true forces the switch off |
| `YT_TO_POST_DAILY_VIDEOS` | overrides `max_videos_per_day` |
| `YT_TO_POST_CLIENT_ID`, `YT_TO_POST_CLIENT_SECRET` | OAuth client, preferred over options |
| `YT_TO_POST_API_KEY` | API key for metadata only |
| `YT_TO_POST_LLM_API_KEY` | model provider key, preferred over the option |
| `YT_TO_POST_DISABLE_CRON_RUNNER` | WP-Cron does housekeeping only; run jobs with WP-CLI |

### 5.8 JSON shapes

Map schema (one per chunk): `summary` (string, at most 1,200 characters),
`points` (array of `{text, start_seconds|null}`, at most 12), `quotes`
(array of `{text, speaker|null, start_seconds|null}`, at most 8), `names`
(array of strings, at most 30). All fields required, no extra properties.

Article schema: `language` (BCP-47 string), `title`, `standfirst`,
`sections` (array, 1 to 20, of `{heading, paragraphs: string[1..12],
start_seconds|null}`), `key_points` (string array, 0 to 8), `quotes` (array,
0 to 15, of `{text, translation|null, speaker|null, start_seconds|null}`),
`topics` (string array, 0 to 8). All fields required, no extra properties,
so strict schema mode works with providers that support it.

### 5.9 Language model configuration

Through llm-json-php: a chain of one or more routes, each with provider
kind (OpenRouter, OpenAI or generic OpenAI-compatible), base URL, model id,
token cap and temperature (default 0.3). The plugin passes a request with a
purpose label `yt-to-post.map`, `yt-to-post.reduce` or `yt-to-post.single`.

### 5.10 Soft integrations

- gen-queue (when `GEN_QUEUE_VERSION` is defined): case 82, through its
  public soft-enqueue filter, job type `yt_to_post.article`.
- spend-meter (when `SPEND_METER_API` equals 1): before each model call the
  plugin applies its pre-spend filter with purpose `yt_to_post`, caller
  `yt-to-post`, provider and model from the route, and an estimate of input
  tokens; it calls the model only when `allowed` is exactly true, and then
  fires the record action with the reported usage, or the release action
  when the call did not happen. Unknown reason codes are a deny.
- Without either, the plugin is complete with its own switch and caps.

## 6. Data

- **Jobs table** `{prefix}yt_to_post_jobs`, created on activation with
  `dbDelta` and checked by column list afterwards: id, video id, state,
  step, user id, post id, source kind, languages, attempts, model calls,
  quota units, metadata JSON (case 16 fields only), chapters JSON,
  transcript (compressed normalised cues), chunk notes JSON, warnings JSON,
  log lines (last 50, no transcript text), last error, timestamps.
  Schema version kept in an option; upgrades check columns after running.
- **Counters**: per-day caps and quota counts in options keyed by the day,
  pruned after 7 days.
- **Options**: settings array; connection (encrypted refresh token,
  connected channel id and title, state); plugin version and schema
  version. Autoload off for the connection and counters.
- **Transients**: access token, the OAuth `state` values, locks.
- **Post meta** on drafts: case 68 keys, all with the `_yt_to_post_`
  prefix so they are hidden from the custom fields box.
- **Retention**: transcript, chunk notes and metadata in a job are cleared
  `retention_days` (default 30, range 1 to 90) after the job ends in
  `done`, `failed` or `cancelled`; the job row itself is deleted after
  twice that. A daily housekeeping event does this. Drafts are never
  touched.
- **Uninstall** (`uninstall.php`): revoke the refresh token at Google (best
  effort, with a five-second timeout), delete options, transients, counters
  and scheduled events, drop the jobs table, and delete the plugin's post
  meta keys. Drafts and posts stay; they belong to the site.
- **Multisite**: everything is per site. Network uninstall walks each site.

## 7. Configuration and sensible defaults

| setting | default | notes |
|---|---|---|
| `enabled` | true once a model is configured | kill switch; constant wins |
| `default_status` | `draft` | `draft` or `pending` only |
| `default_author` | none | required for CLI jobs without `--user` |
| `default_category` | none | |
| `article_language` | `site` | `source`, `site`, or a BCP-47 tag |
| `youtube_translate` | false | case 32 |
| `keep_annotations` | false | case 44 |
| `topics_as_tags` | false | case 67 |
| `allow_private_own` | false | case 11 |
| `site_country` | from the site locale, editable | case 14 warnings |
| `max_videos_per_day` | 10 | 0 to 500 |
| `max_model_calls_per_day` | 200 | 0 to 5,000 |
| `youtube_quota_budget` | 9,000 | units per Pacific day |
| `max_duration_minutes` | 240 | |
| `max_chunks` | 30 | |
| `single_pass_tokens` | 12,000 | |
| `chunk_tokens` | 8,000 | |
| `min_words` | 150 | |
| `max_transcript_bytes` | 5 MiB | |
| `max_job_attempts` | 3 | |
| `tick_seconds` | 25 | |
| `retention_days` | 30 | 1 to 90 |
| model chain, provider key | none | the plugin does nothing until set |

Every setting is validated on save with its range; an out-of-range value is
refused with a message and the old value kept.

## 8. Security and privacy

- Capabilities: create (`edit_posts`), settings and connect
  (`manage_options`). A user can see and act on only their own jobs unless
  they hold the settings capability. A job created for `user_id` other than
  the caller needs the settings capability.
- Nonces on every form and row action; the REST nonce on every route; the
  OAuth `state` as in case 74.
- Secrets: client secret, API key and model key are read from constants
  first. When stored in options they are never printed back in full (last
  four characters only) and never sent to the browser. The refresh token is
  encrypted with libsodium secretbox (WordPress ships a compatible
  polyfill) under a key derived from the site's auth salts with a fixed
  context label. Nothing secret is logged.
- Scope: `youtube.force-ssl` lets the holder manage the YouTube account.
  The plugin only ever calls `videos.list`, `channels.list`,
  `captions.list` and `captions.download`. The settings page says so and
  recommends connecting a channel account used for this purpose. Google
  requires app verification for sensitive scopes when many users connect;
  for one site's own account the "testing" status works but its refresh
  tokens expire after seven days (case 76), so the screen warns.
- Untrusted input: URLs (case 3), transcripts (cases 36 to 40), TTML
  (case 38), descriptions and chapter titles, and model output (case 64)
  are all untrusted. Model output is escaped as text. The prompt marks
  source text as data (case 57).
- No shell, no eval, no `@`. Outbound HTTP only through the WordPress HTTP
  API (YouTube, Google OAuth) and llm-json-php (model provider), to fixed
  HTTPS hosts, with TLS verification on.
- Privacy: transcripts can contain personal data about speakers. They go to
  the configured model provider and nowhere else. The plugin adds text to
  the WordPress privacy policy guide naming YouTube and the model provider
  as recipients. Retention per section 6. The README links Google's API
  services terms and developer policies and tells the operator to read them
  for their own use, including the limits on storing API data.

## 9. Performance budget

- Front end: zero cost. No hooks run on front-end requests apart from what
  WordPress does for the embed block.
- Admin screens: no remote calls on page load; the jobs list is one indexed
  query with a page size of 20.
- A runner tick stays within `tick_seconds` and starts no step it cannot
  finish within the remaining time, using the step's last measured length
  as the estimate.
- Memory: a 5 MiB transcript must normalise and chunk within 64 MiB of
  PHP memory. Parsers work line by line where the format allows.
- HTTP timeouts: 15 seconds for YouTube and Google OAuth; model calls use
  llm-json-php's own deadlines, capped at the remaining tick time.
- Model calls per video: 1 for a single pass; chunks plus reduce calls plus
  at most one language retry per call for long videos.
- Target from section 1: 60-minute captioned video to draft in under five
  minutes wall time with a typical hosted model.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3; `declare(strict_types=1)` everywhere; no 8.2+ syntax.
- WordPress 6.4 or later, single site and multisite. Block editor and
  classic editor (block markup renders in both).
- Extensions: `json`, `mbstring`, `dom` and `libxml` (TTML), `sodium` or the
  WordPress polyfill, `zlib` (transcript compression; if absent, stored
  uncompressed). `intl` used for NFC normalisation when present; without it
  quote matching skips NFC and says so in the notes.
- Hosts with WP-Cron disabled: the README shows the real cron line for
  `wp yt-to-post run`.
- Object caches: the switch and caps are read bypassing the object cache
  (case 69). Page caches are irrelevant; nothing is served to readers.
- Runtime dependency: `indianopinion/llm-json-php` only (a `vcs`
  repository in `composer.json`). The release zip bundles it so WordPress
  sites need no Composer.

## 11. Observability

- Job list and job detail (section 4.13) with state, step, calls, quota
  units and the last 50 log lines per job.
- `wp yt-to-post status` prints the switch, caps and quota used today, the
  connection state and the time of the last runner tick.
- Admin notices, shown only to the settings capability: connection needs
  reconnect, quota exhausted, account credit hold, runner not ticking for
  more than an hour while jobs wait.
- The draft's meta box shows the notes (case 68), so the editor sees what
  the draft was built from and what was dropped.
- Every state change fires `yt_to_post_job_state`, so a site can log or
  alert from it. With `WP_DEBUG_LOG` on, one line per state change is
  written to the debug log without transcript text.

## 12. Acceptance tests

Tests run with PHPUnit and a stub layer, with YouTube, Google OAuth and the
model mocked. Test Tn covers case n.

| test | checks |
|---|---|
| T1 | every accepted URL form and the bare id parse to the same id |
| T2 | `t=` recorded, `list=` ignored with the single video used |
| T3 | look-alike hosts, bad ids, `javascript:` and over-long input refused with no HTTP call |
| T4 | two URL forms of one video share a lock and dedup key |
| T5 | second create returns the existing job; `force` creates a second draft and leaves the first untouched |
| T6 | concurrent create during a running job returns the running job |
| T7 | `videos.list` uses the token, else the API key, else fails before any call |
| T8 | empty items fails with the not-found message and no retry |
| T9 | `live` and `upcoming` refused |
| T10 | `uploadStatus` other than `processed` refused |
| T11 | private video refused; own private allowed only with the setting |
| T12 | unlisted refused for a foreign channel without the tick; allowed with it, warning noted |
| T13 | not embeddable: no embed block, notice paragraph present |
| T14 | age, kids and region warnings recorded, job continues |
| T15 | ISO 8601 durations parse; zero is unknown; over the limit refused unless overridden |
| T16 | stored metadata holds only the listed fields |
| T17 | channel ownership matches on connected channel id |
| T18 | chapter lines with each timestamp form and bullet style parse; mid-line times ignored |
| T19 | lists not starting at 0:00, with two chapters, out of order or under 10 s ignored whole |
| T20 | a chapter past the duration invalidates the list |
| T21 | chapter titles trimmed and capped; empty title invalidates |
| T22 | supplied chapters override the description |
| T23 | chunk boundaries fall on chapter starts |
| T24 | chapter titles appear only in user content |
| T25 | no caption calls with an API key alone |
| T26 | track selection order over a fixture of mixed tracks; filter override works |
| T27 | `pt-BR` and `pt` matching; `zh-Hans` against `zh-Hant` |
| T28 | `couldNotConvert` falls back to `srt` then original; `scc` never requested |
| T29 | `403 forbidden` moves to `needs_transcript` with the message |
| T30 | `404` relists once, tries next track, then `needs_transcript` |
| T31 | no candidates: `needs_transcript` with no download call |
| T32 | `tlang` only when enabled and languages differ; fallback on failure |
| T33 | ASR source marked in notes and prompt |
| T34 | ASR-only proceeds; forced-only goes to `needs_transcript` |
| T35 | supplied transcript skips all caption calls; size limit enforced |
| T36 | format detection fixtures for VTT, SRT, SBV, TTML, timestamped and plain text; SCC refused |
| T37 | UTF-8 with BOM accepted; UTF-16 converted; invalid bytes refused with offset |
| T38 | TTML with a DOCTYPE or external entity refused; node limit enforced |
| T39 | plain text yields no timestamp links |
| T40 | malformed cues skipped and counted; over half refused |
| T41 | language fallback chain; model-reported language recorded |
| T42 | supplying a transcript resumes at normalise with no metadata call |
| T43 | every format yields the same cue structure |
| T44 | cleaning fixture: tags, voice speakers, entities, annotations, `>>` marks |
| T45 | rolling ASR fixture de-duplicates to clean text |
| T46 | short and music-only transcripts refused; CJK counting rule |
| T47 | estimator default and filter |
| T48 | small transcript makes one model call |
| T49 | chunk sizes respected; cues never split; giant cue split at sentences; no broken UTF-8 |
| T50 | chunks carry times and chapter titles |
| T51 | map notes saved after each successful call |
| T52 | reduce uses notes only; grouped reduce for large notes, at most three levels |
| T53 | too many chunks refused unless overridden |
| T54 | retry after a mid-map failure calls the model only for missing chunks |
| T55 | all model calls go through the llm-json-php mock with a schema |
| T56 | switch, cap or spend-meter deny before a call holds the job with no call made |
| T57 | system and user content separation; delimiters present |
| T58 | `source`, `site` and explicit tag article languages; translation fields asked when different |
| T59 | schema rejects missing fields |
| T60 | length rules trim at word boundaries; empty sections rejected |
| T61 | invented quote dropped and counted; real quote gets the cue start time |
| T62 | out-of-range times removed |
| T63 | wrong language retried once then fails; mixed-language quotes pass |
| T64 | HTML, links and Markdown marks stripped from model text |
| T65 | block markup order and escaping; parses with `parse_blocks` |
| T66 | credit text and links; filter without the watch URL falls back |
| T67 | status forced to draft or pending against a filter that sets `publish` or a future date |
| T68 | meta keys written; notes not in content |
| T69 | constant beats option; running job stops before next call; resumes when re-enabled |
| T70 | caps per site day; filter can lower, not raise; cap 0 |
| T71 | quota counted per Pacific day across a DST change; budget refuses a call |
| T72 | `quotaExceeded` holds until Pacific midnight; rate limits retried |
| T73 | credit exhaustion holds all jobs one hour, not a failure |
| T74 | auth URL holds PKCE challenge, offline access, consent prompt, scope and state |
| T75 | bad state, error parameter, missing capability and missing refresh token handled |
| T76 | 401 refreshes once; `invalid_grant` sets `needs_reconnect` and `needs_auth` |
| T77 | backoff timings and `Retry-After`; job fails after max attempts |
| T78 | disconnect revokes and deletes even when revoke fails |
| T79 | changed salts give `needs_reconnect` without a fatal error |
| T80 | crash between steps resumes from the saved step |
| T81 | runner respects tick time and the site lock |
| T82 | with a gen-queue stub, jobs go through its soft-enqueue filter with the right key |
| T83 | cancel stops before next call; cancel on `done` is a no-op |
| T84 | deleted draft allows a fresh create without force |
| T85 | an exception in one job does not stop the next |
| T86 | form validates input and shows the estimate |
| T87 | editors see only their own jobs |
| T88 | constant-set fields read-only on settings |
| T89 | missing nonce or capability refused on every form, action and route |
| T-PERF | 60-minute fixture transcript with a mock model runs end to end within memory and tick limits, and every quote in the draft is in the transcript |
| T-UNINSTALL | uninstall removes table, options, transients, events and meta, keeps posts, attempts revoke |

## 13. Open questions

The builder answers each in `docs/ARCHITECTURE.md`.

1. Without OAuth or an API key, should the plugin accept a transcript plus
   a title and channel typed in by the editor, so it works with no Google
   project at all? It is left out of 0.1.0 because the credit then rests on
   unverified input.
2. Should videos from other channels need a "rights confirmed" tick on the
   form, recorded on the job, or is the editor's review enough?
3. Should the embed use the privacy-enhanced `youtube-nocookie.com` player
   (a custom embed) instead of WordPress core's oEmbed block, as an option?
4. What retention default does the current YouTube developer policy on
   storing API data require for the stored metadata, and should the job
   metadata be refreshed or purged sooner than `retention_days`?
5. Should the cleaned transcript be kept on the draft as private meta for
   the editor to check quotes against, or only in the job until purge?
6. Should a playlist URL expand into one job per video, under the daily
   cap, in a later version?
7. Is the byte-based token estimate good enough, or should the plugin read
   a per-model ratio from settings?
8. Is YouTube's machine translation (`tlang`) worth offering at all, given
   that it compounds errors on automatic captions?
9. Should the plugin offer the video thumbnail, fetched from the API's
   thumbnail URL, as a suggested featured image the editor must accept?

## 14. Lessons from production

General lessons from running language-model content tools and Google APIs
on WordPress, written as rules.

1. Never let a model's quote stand unchecked. Verify every quote against
   the source text and take its timestamp from the source, not the model.
2. Check the language of the output, not just the prompt. Models drift back
   to English on non-English input, and a field that says `hi` proves
   nothing on its own: count the letters.
3. Never trust a single setting to keep a draft a draft. Force the status
   after every filter, and clear any date that would schedule it.
4. Read the kill switch and the caps fresh before every paid call, never
   once at the start of a long run. A run can last minutes.
5. Save after every paid step. A timeout on step nine must not pay again
   for steps one to eight.
6. Count quota on the provider's clock. YouTube resets at midnight Pacific
   Time, not at the site's midnight.
7. A Google project left in testing status kills refresh tokens after seven
   days. Detect `invalid_grant`, say so plainly, and never loop on refresh.
8. Caption download is for owners. Plan the "supply a transcript" path as a
   first-class path, not an error.
9. Auto-generated captions repeat themselves line by line. De-duplicate
   before counting words or chunking, or every number is wrong.
10. Count text by bytes when budgeting for models. Non-Latin scripts cost
    several times more tokens per character than English.
11. Never split inside a UTF-8 sequence. Cut on cue, sentence or whitespace
    boundaries.
12. `dbDelta` can silently skip a column change. After every schema install
    or upgrade, list the columns and fail loudly if one is missing.
13. Treat every piece of source text as data that may contain instructions.
    Keep it out of the system instruction and fence it in the user content.
14. Leave slugs to WordPress. Do not build slugs from non-Latin titles by
    hand; a long title in a multi-byte script can exceed the 200-byte slug
    column.
15. Hold, do not fail, when the account is out of credit. A credit stop is
    the operator's problem, not the job's, and must not burn retries.
