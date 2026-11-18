<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Article;

/**
 * JSON schemas for the map step and the article (PRD section 5.8). All
 * fields are required and no extra properties are allowed, so strict
 * structured outputs work where the provider supports them.
 */
final class Schemas
{
    /** @return array<string,mixed> */
    public static function map(): array
    {
        $nullableNumber = ['type' => ['number', 'null']];
        $nullableString = ['type' => ['string', 'null']];
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'points' => [
                    'type' => 'array',
                    'maxItems' => 12,
                    'items' => self::obj(['text' => ['type' => 'string'], 'start_seconds' => $nullableNumber]),
                ],
                'quotes' => [
                    'type' => 'array',
                    'maxItems' => 8,
                    'items' => self::obj([
                        'text' => ['type' => 'string'],
                        'speaker' => $nullableString,
                        'start_seconds' => $nullableNumber,
                    ]),
                ],
                'names' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string']],
            ],
            'required' => ['summary', 'points', 'quotes', 'names'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The base article schema. `source_language` is the language the model
     * hears in the transcript, used when the transcript language is unknown.
     *
     * @return array<string,mixed>
     */
    public static function article(): array
    {
        $nullableNumber = ['type' => ['number', 'null']];
        $nullableString = ['type' => ['string', 'null']];
        return [
            'type' => 'object',
            'properties' => [
                'language' => ['type' => 'string'],
                'source_language' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'standfirst' => ['type' => 'string'],
                'sections' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 20,
                    'items' => self::obj([
                        'heading' => ['type' => 'string'],
                        'paragraphs' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 12, 'items' => ['type' => 'string']],
                        'start_seconds' => $nullableNumber,
                    ]),
                ],
                'key_points' => ['type' => 'array', 'maxItems' => 8, 'items' => ['type' => 'string']],
                'quotes' => [
                    'type' => 'array',
                    'maxItems' => 15,
                    'items' => self::obj([
                        'text' => ['type' => 'string'],
                        'translation' => $nullableString,
                        'speaker' => $nullableString,
                        'start_seconds' => $nullableNumber,
                    ]),
                ],
                'topics' => ['type' => 'array', 'maxItems' => 8, 'items' => ['type' => 'string']],
            ],
            'required' => self::ARTICLE_REQUIRED,
            'additionalProperties' => false,
        ];
    }

    public const ARTICLE_REQUIRED = [
        'language', 'source_language', 'title', 'standfirst', 'sections', 'key_points', 'quotes', 'topics',
    ];

    /**
     * The article schema after the `yt_to_post_article_schema` filter. A
     * filter may add optional properties; the base properties and the
     * required list always come back.
     *
     * @return array<string,mixed>
     */
    public static function filteredArticle(): array
    {
        $base = self::article();
        if (!function_exists('apply_filters')) {
            return $base;
        }
        $f = apply_filters('yt_to_post_article_schema', $base);
        if (!is_array($f) || !is_array($f['properties'] ?? null)) {
            return $base;
        }
        $f['type'] = 'object';
        $f['properties'] = array_merge($f['properties'], $base['properties']);
        $required = is_array($f['required'] ?? null) ? $f['required'] : [];
        $f['required'] = array_values(array_unique(array_merge(self::ARTICLE_REQUIRED, array_filter($required, 'is_string'))));
        $f['additionalProperties'] = false;
        return $f;
    }

    /**
     * @param array<string,mixed> $props
     * @return array<string,mixed>
     */
    private static function obj(array $props): array
    {
        return [
            'type' => 'object',
            'properties' => $props,
            'required' => array_keys($props),
            'additionalProperties' => false,
        ];
    }
}
