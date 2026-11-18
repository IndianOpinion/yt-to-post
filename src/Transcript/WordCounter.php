<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript;

/**
 * Counts words. Scripts that do not separate words (Chinese, Japanese,
 * Thai, Lao, Khmer, Burmese) count as characters divided by two.
 */
final class WordCounter
{
    private const NO_SPACE = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]/u';

    public static function count(string $text): int
    {
        $n = preg_match_all(self::NO_SPACE, $text);
        $rest = (string) preg_replace(self::NO_SPACE, ' ', $text);
        $words = preg_split('/\s+/u', trim($rest), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($words, static fn (string $w): bool => preg_match('/[\p{L}\p{N}]/u', $w) === 1);
        return count($words) + (int) ceil(((int) $n) / 2);
    }
}
