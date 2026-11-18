<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Article;

/**
 * Model text is plain text (PRD case 64): tags stripped, Markdown emphasis
 * removed, URLs left as plain text.
 */
final class TextSanitizer
{
    public static function clean(string $s): string
    {
        $s = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $s);
        $s = strip_tags($s);
        // Markdown links [text](url) keep both parts as text.
        $s = (string) preg_replace('/\[([^\]\n]+)\]\((\S+?)\)/u', '$1 ($2)', $s);
        $s = (string) preg_replace('/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/u', '$2', $s);
        $s = (string) preg_replace('/(?<![\w*])\*(?=\S)([^*\n]+?)(?<=\S)\*(?![\w*])/u', '$1', $s);
        $s = (string) preg_replace('/(?<![\w])_(?=\S)([^_\n]+?)(?<=\S)_(?![\w])/u', '$1', $s);
        $s = (string) preg_replace('/`+([^`]*)`+/u', '$1', $s);
        $s = (string) preg_replace('/^\s{0,3}#{1,6}\s+/mu', '', $s);
        $s = (string) preg_replace('/^\s*[*+]\s+/mu', '', $s);
        $s = (string) preg_replace('/[ \t]+/u', ' ', $s);
        return trim($s);
    }

    /** Cuts to $max characters at a word boundary. */
    public static function trimWords(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        $cut = mb_substr($s, 0, $max + 1);
        $sp = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp > (int) ($max / 2)) {
            $cut = mb_substr($cut, 0, $sp);
        } else {
            $cut = mb_substr($s, 0, $max);
        }
        return rtrim($cut, " ,;:-");
    }
}
