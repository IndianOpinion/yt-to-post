<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Article;

use IndianOpinion\YtToPost\Transcript\Cue;

/**
 * Every quote must appear word for word in the transcript (PRD case 61).
 * Matching is on normalised text: NFC (when intl is present), case folded,
 * curly quotes straightened, punctuation and whitespace collapsed. A match
 * takes its time from the cue where it begins.
 */
final class QuoteVerifier
{
    private string $haystack = '';
    /** @var list<int> byte offsets where each cue starts in the haystack */
    private array $offsets = [];
    /** @var list<?float> */
    private array $starts = [];

    /**
     * @param list<Cue> $cues
     */
    public function __construct(array $cues)
    {
        $parts = [];
        $pos = 1;
        foreach ($cues as $c) {
            $n = self::normalise($c->text);
            if ($n === '') {
                continue;
            }
            $this->offsets[] = $pos;
            $this->starts[] = $c->start;
            $parts[] = $n;
            $pos += strlen($n) + 1;
        }
        $this->haystack = ' ' . implode(' ', $parts) . ' ';
    }

    public static function hasNfc(): bool
    {
        return class_exists(\Normalizer::class);
    }

    public static function normalise(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
            if (is_string($n)) {
                $s = $n;
            }
        }
        $s = mb_convert_case($s, MB_CASE_FOLD, 'UTF-8');
        $s = strtr($s, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{00AB}" => '"', "\u{00BB}" => '"',
        ]);
        $s = (string) preg_replace('/[\p{P}\p{S}]+/u', ' ', $s);
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Start seconds of the cue where the quote begins; false when the quote
     * is not in the transcript; null when it is but the cue has no time.
     */
    public function locate(string $quote): float|false|null
    {
        $q = self::normalise($quote);
        if ($q === '') {
            return false;
        }
        $at = strpos($this->haystack, ' ' . $q . ' ');
        if ($at === false) {
            return false;
        }
        $at++;
        $lo = 0;
        $hi = count($this->offsets) - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if ($this->offsets[$mid] <= $at) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        return $this->starts[$lo] ?? null;
    }

    /**
     * Drops quotes not in the transcript and resets times from the cues.
     */
    public function verify(Article $article): Article
    {
        $kept = [];
        $dropped = 0;
        foreach ($article->quotes as $q) {
            $where = $this->locate($q['text']);
            if ($where === false) {
                $dropped++;
                continue;
            }
            $q['start_seconds'] = $where === null ? null : (int) floor($where);
            $kept[] = $q;
        }
        return $article->with(['quotes' => $kept, 'dropped_quotes' => $article->droppedQuotes + $dropped]);
    }
}
