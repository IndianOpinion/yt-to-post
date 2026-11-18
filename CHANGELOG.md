# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-18

### Added

- One YouTube video in, one WordPress draft out: embed, standfirst, sections, key points, verified quotes with timestamp links, and a source credit that cannot be turned off.
- Captions through the official YouTube Data API v3 (`captions.list`, `captions.download`) under the site's own OAuth connection (PKCE, offline access, encrypted refresh token), or a transcript the editor supplies (WebVTT, SRT, SBV, TTML, timestamped or plain text).
- Without a Google project, title and channel for the credit come from YouTube's public oEmbed endpoint when a transcript is supplied.
- Chapters from the description or a supplied list, following YouTube's chapter rule.
- Transcript cleaning: tags, voice speakers, entities, sound annotations, speaker-change marks, and rolling caption de-duplication.
- Long videos by chunked map and reduce with per-chunk checkpoints; grouped reduce up to three levels.
- Quote verification against the transcript, with times taken from the transcript; language check with one retry.
- Drafts only: status forced to draft or pending after every filter, dates cleared.
- Kill switch, daily video and model call caps, YouTube quota budget per Pacific day, credit hold, retries with backoff.
- "Rights confirmed" tick for videos from other channels; thumbnail offered as a suggested featured image behind it.
- Opt-in privacy-enhanced player, opt-in playlist expansion (one job per video under the daily cap).
- Retention: job working data, YouTube API data (purge or refresh, 30 days by default), and an operator setting for the transcript kept on drafts.
- Tools screen, settings screen, draft notes meta box, REST routes under `yt-to-post/v1`, and `wp yt-to-post` commands.
- Soft integrations with gen-queue and spend-meter; a filter to plug in any model caller.

[Unreleased]: https://github.com/IndianOpinion/yt-to-post/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/yt-to-post/releases/tag/v0.1.0
