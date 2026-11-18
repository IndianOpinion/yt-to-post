<?php

/**
 * Shows the quote check: a quote must appear word for word in the
 * transcript, and its time comes from the cue where it begins.
 *
 *   php examples/quote-check.php
 */

declare(strict_types=1);

use IndianOpinion\YtToPost\Article\QuoteVerifier;
use IndianOpinion\YtToPost\Transcript\Cue;

require __DIR__ . '/../vendor/autoload.php';

$verifier = new QuoteVerifier([
    new Cue(12.0, 15.0, null, 'We will build the dam'),
    new Cue(15.0, 19.0, null, "next year, and that\u{2019}s final."),
]);
foreach (["build the dam next year, and that's FINAL", 'We will never build a dam'] as $quote) {
    $at = $verifier->locate($quote);
    printf("%-45s %s\n", $quote, $at === false ? 'not in the transcript: dropped' : 'found at ' . (int) $at . 's');
}
