<?php

/**
 * Parses, cleans and chunks a transcript file offline, the same way the
 * plugin does before any model call. No WordPress and no network needed.
 *
 *   php examples/parse-transcript.php examples/sample.vtt
 */

declare(strict_types=1);

use IndianOpinion\YtToPost\Chunking\Chunker;
use IndianOpinion\YtToPost\Chunking\TokenEstimator;
use IndianOpinion\YtToPost\Transcript\Transcript;
use IndianOpinion\YtToPost\Transcript\TranscriptCleaner;
use IndianOpinion\YtToPost\Transcript\TranscriptReader;
use IndianOpinion\YtToPost\Transcript\WordCounter;

require __DIR__ . '/../vendor/autoload.php';

$file = $argv[1] ?? __DIR__ . '/sample.vtt';
$bytes = file_get_contents($file);
if ($bytes === false) {
    fwrite(STDERR, "Cannot read $file\n");
    exit(1);
}
$parsed = (new TranscriptReader())->read($bytes);
$cues = (new TranscriptCleaner())->clean($parsed->cues);
$transcript = new Transcript($cues, 'en', Transcript::KIND_TRANSCRIPT);
echo $transcript->toReadableText(), "\n\n";
$estimator = new TokenEstimator();
$chunks = (new Chunker($estimator, 200))->split($cues);
printf(
    "format %s, %d cues (%d malformed skipped), %d words, about %d tokens, %d chunk(s) at 200 tokens\n",
    $parsed->format,
    count($cues),
    $parsed->malformed,
    WordCounter::count($transcript->text()),
    (new Chunker($estimator, 200))->totalTokens($cues),
    count($chunks)
);
