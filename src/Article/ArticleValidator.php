<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Article;

use IndianOpinion\YtToPost\Exception\ModelOutputException;

/**
 * Checks model output against the article shape and the length rules
 * (PRD cases 59, 60, 62 and 64). Over-long fields are trimmed at a word
 * boundary; missing fields and empty articles are rejected.
 */
final class ArticleValidator
{
    public const TITLE_MIN = 10;
    public const TITLE_MAX = 120;
    public const STANDFIRST_MAX = 300;
    public const SECTIONS_MAX = 20;
    public const QUOTES_MAX = 15;
    public const PARAGRAPHS_MAX = 12;
    public const KEY_POINTS_MAX = 8;
    public const TOPICS_MAX = 8;

    /**
     * @param array<string,mixed> $data
     * @throws ModelOutputException
     */
    public static function validate(array $data, ?int $duration): Article
    {
        foreach (Schemas::ARTICLE_REQUIRED as $field) {
            if (!array_key_exists($field, $data)) {
                throw new ModelOutputException("Model output is missing \"$field\".");
            }
        }
        foreach (['sections', 'key_points', 'quotes', 'topics'] as $list) {
            if (!is_array($data[$list])) {
                throw new ModelOutputException("Model output field \"$list\" is not a list.");
            }
        }
        foreach (['language', 'source_language', 'title', 'standfirst'] as $str) {
            if (!is_string($data[$str])) {
                throw new ModelOutputException("Model output field \"$str\" is not text.");
            }
        }
        $title = TextSanitizer::clean((string) $data['title']);
        if (mb_strlen($title) < self::TITLE_MIN) {
            throw new ModelOutputException('Model output title is shorter than 10 characters.');
        }
        $title = TextSanitizer::trimWords($title, self::TITLE_MAX);
        $standfirst = TextSanitizer::trimWords(TextSanitizer::clean((string) $data['standfirst']), self::STANDFIRST_MAX);

        $sections = [];
        foreach ($data['sections'] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $paras = [];
            foreach (is_array($s['paragraphs'] ?? null) ? $s['paragraphs'] : [] as $p) {
                $p = is_string($p) ? TextSanitizer::clean($p) : '';
                if ($p !== '') {
                    $paras[] = $p;
                }
            }
            if ($paras === []) {
                continue;
            }
            $sections[] = [
                'heading' => TextSanitizer::trimWords(TextSanitizer::clean(is_string($s['heading'] ?? null) ? $s['heading'] : ''), 200),
                'paragraphs' => array_slice($paras, 0, self::PARAGRAPHS_MAX),
                'start_seconds' => self::time($s['start_seconds'] ?? null, $duration),
            ];
            if (count($sections) >= self::SECTIONS_MAX) {
                break;
            }
        }
        if ($sections === []) {
            throw new ModelOutputException('Model output has no section with a non-empty paragraph.');
        }

        $keyPoints = [];
        foreach ($data['key_points'] as $k) {
            $k = is_string($k) ? TextSanitizer::clean($k) : '';
            if ($k !== '') {
                $keyPoints[] = $k;
            }
        }
        $quotes = [];
        foreach ($data['quotes'] as $q) {
            if (!is_array($q) || !is_string($q['text'] ?? null)) {
                continue;
            }
            $text = TextSanitizer::clean($q['text']);
            if ($text === '') {
                continue;
            }
            $tr = is_string($q['translation'] ?? null) ? TextSanitizer::clean($q['translation']) : '';
            $sp = is_string($q['speaker'] ?? null) ? TextSanitizer::clean($q['speaker']) : '';
            $quotes[] = [
                'text' => $text,
                'translation' => $tr === '' ? null : $tr,
                'speaker' => $sp === '' ? null : TextSanitizer::trimWords($sp, 100),
                'start_seconds' => self::time($q['start_seconds'] ?? null, $duration),
            ];
            if (count($quotes) >= self::QUOTES_MAX) {
                break;
            }
        }
        $topics = [];
        foreach ($data['topics'] as $t) {
            $t = is_string($t) ? TextSanitizer::trimWords(TextSanitizer::clean($t), 60) : '';
            if ($t !== '') {
                $topics[] = $t;
            }
        }
        $extra = array_diff_key($data, array_flip(Schemas::ARTICLE_REQUIRED));
        return new Article(
            trim((string) $data['language']),
            trim((string) $data['source_language']),
            $title,
            $standfirst,
            $sections,
            array_slice($keyPoints, 0, self::KEY_POINTS_MAX),
            $quotes,
            array_slice($topics, 0, self::TOPICS_MAX),
            0,
            $extra
        );
    }

    /** A start time inside 0..duration, else null (PRD case 62). */
    public static function time(mixed $v, ?int $duration): ?int
    {
        if (!is_int($v) && !is_float($v)) {
            return null;
        }
        if ($v < 0 || ($duration !== null && $v > $duration)) {
            return null;
        }
        return (int) floor($v);
    }
}
