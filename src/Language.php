<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost;

/**
 * BCP-47 helpers: validation, primary subtag, matching, expected script.
 */
final class Language
{
    private const SCRIPTS = [
        'hi' => ['Devanagari'], 'mr' => ['Devanagari'], 'ne' => ['Devanagari'], 'sa' => ['Devanagari'],
        'bn' => ['Bengali'], 'as' => ['Bengali'], 'ta' => ['Tamil'], 'te' => ['Telugu'],
        'kn' => ['Kannada'], 'ml' => ['Malayalam'], 'gu' => ['Gujarati'], 'pa' => ['Gurmukhi'],
        'or' => ['Oriya'], 'si' => ['Sinhala'], 'ur' => ['Arabic'], 'ar' => ['Arabic'], 'fa' => ['Arabic'],
        'ps' => ['Arabic'], 'he' => ['Hebrew'], 'yi' => ['Hebrew'], 'el' => ['Greek'],
        'ru' => ['Cyrillic'], 'uk' => ['Cyrillic'], 'bg' => ['Cyrillic'], 'mk' => ['Cyrillic'],
        'zh' => ['Han'], 'ja' => ['Han', 'Hiragana', 'Katakana'], 'ko' => ['Hangul', 'Han'],
        'th' => ['Thai'], 'km' => ['Khmer'], 'lo' => ['Lao'], 'my' => ['Myanmar'], 'ka' => ['Georgian'],
        'hy' => ['Armenian'], 'am' => ['Ethiopic'],
        'en' => ['Latin'], 'fr' => ['Latin'], 'de' => ['Latin'], 'es' => ['Latin'], 'pt' => ['Latin'],
        'it' => ['Latin'], 'nl' => ['Latin'], 'sv' => ['Latin'], 'da' => ['Latin'], 'no' => ['Latin'],
        'nb' => ['Latin'], 'fi' => ['Latin'], 'pl' => ['Latin'], 'cs' => ['Latin'], 'sk' => ['Latin'],
        'ro' => ['Latin'], 'hu' => ['Latin'], 'tr' => ['Latin'], 'id' => ['Latin'], 'ms' => ['Latin'],
        'vi' => ['Latin'], 'sw' => ['Latin'], 'tl' => ['Latin'], 'ga' => ['Latin'], 'cy' => ['Latin'],
    ];

    public static function isValid(string $tag): bool
    {
        return preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $tag) === 1;
    }

    public static function primary(string $tag): string
    {
        return strtolower(explode('-', str_replace('_', '-', $tag))[0]);
    }

    /** 0 for a full match, 1 for a primary-subtag match, 2 for none. */
    public static function matchRank(string $a, string $b): int
    {
        if ($a === '' || $b === '') {
            return 2;
        }
        $a = str_replace('_', '-', $a);
        $b = str_replace('_', '-', $b);
        if (strcasecmp($a, $b) === 0) {
            return 0;
        }
        return self::primary($a) === self::primary($b) ? 1 : 2;
    }

    /** From a WordPress locale such as en_GB to en-GB. */
    public static function fromLocale(string $locale): string
    {
        $tag = str_replace('_', '-', $locale);
        $tag = (string) preg_replace('/-formal$|-informal$/', '', $tag);
        return self::isValid($tag) ? $tag : 'en';
    }

    /**
     * Unicode script names expected for a language, or null when unknown.
     *
     * @return list<string>|null
     */
    public static function scripts(string $tag): ?array
    {
        $p = self::primary($tag);
        return self::SCRIPTS[$p] ?? null;
    }

    /**
     * Share (0..1) of letters in $text that are not in the expected scripts.
     * Null when the language's script is unknown or there are no letters.
     */
    public static function foreignShare(string $text, string $tag): ?float
    {
        $scripts = self::scripts($tag);
        if ($scripts === null) {
            return null;
        }
        $letters = preg_match_all('/\p{L}/u', $text);
        if (!$letters) {
            return null;
        }
        $class = implode('', array_map(static fn (string $s): string => '\p{' . $s . '}', $scripts));
        $native = (int) preg_match_all('/[' . $class . ']/u', $text);
        return 1.0 - $native / $letters;
    }
}
