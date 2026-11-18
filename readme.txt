=== yt-to-post ===
Contributors: indianopinion
Tags: youtube, transcript, captions, draft, article
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Turns one YouTube video into one draft, from captions through the official Data API or a transcript you supply. Drafts only.

== Description ==

Written from scratch; not derived from any third-party plugin.

yt-to-post gets the words of a video from caption tracks downloaded through the YouTube Data API v3 under your own OAuth connection, or from a transcript file you upload. A language model writes a structured article: title, standfirst, sections aligned to chapters, key points and quotes. Every quote is checked word for word against the transcript and links to its moment in the video. The draft embeds the video and credits the source. An editor reviews and publishes it.

* Drafts only, with a kill switch, daily caps and a YouTube quota budget.
* Long videos are chunked, with checkpoints so a failure never pays twice.
* Videos without usable captions wait for a transcript and then resume.
* Non-English speech: choose the transcript and article languages.

You need your own Google Cloud project with the YouTube Data API v3, and a model provider key (OpenRouter, OpenAI or any OpenAI-compatible endpoint).

== Installation ==

1. Upload the plugin and activate it.
2. Settings > YouTube to draft: add the OAuth client, the model and key, then connect your channel account.
3. Tools > Video to draft: paste a link.

== Frequently Asked Questions ==

= Does it scrape YouTube? =

No. It uses only the documented Data API with your credentials, YouTube's public oEmbed endpoint for title and channel when no Google project is set up, or a transcript you supply.

= Can it publish? =

No. Status is always draft or pending.

== Privacy ==

Transcripts go to the model provider you configure. Video details are requested from YouTube. Stored data is purged on the schedule you set.

== Changelog ==

= 0.1.0 =
* First release.
