<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost;

/**
 * Settings: defaults, ranges, constants that win over options, and a fresh
 * read that bypasses the object cache for the switch and the caps.
 */
final class Settings
{
    public const OPTION = 'yt_to_post_settings';

    public const DEFAULTS = [
        'enabled' => true,
        'default_status' => 'draft',
        'default_author' => 0,
        'default_category' => 0,
        'default_tags' => '',
        'article_language' => 'site',
        'keep_annotations' => false,
        'topics_as_tags' => false,
        'allow_private_own' => false,
        'privacy_embed' => false,
        'allow_playlists' => false,
        'max_playlist_items' => 25,
        'site_country' => '',
        'max_videos_per_day' => 10,
        'max_model_calls_per_day' => 200,
        'youtube_quota_budget' => 9000,
        'max_duration_minutes' => 240,
        'max_chunks' => 30,
        'single_pass_tokens' => 12000,
        'chunk_tokens' => 8000,
        'min_words' => 150,
        'max_transcript_bytes' => 5242880,
        'max_job_attempts' => 3,
        'tick_seconds' => 25,
        'retention_days' => 30,
        'api_data_days' => 30,
        'api_data_on_expiry' => 'purge',
        'draft_transcript_days' => 0,
        'client_id' => '',
        'client_secret' => '',
        'api_key' => '',
        'llm_api_key' => '',
        'model_kind' => 'openrouter',
        'model_ids' => '',
        'model_base_url' => '',
        'model_token_cap' => 4096,
        'model_temperature' => 0.3,
    ];

    /** Integer settings and their allowed range. */
    public const RANGES = [
        'max_playlist_items' => [1, 50],
        'max_videos_per_day' => [0, 500],
        'max_model_calls_per_day' => [0, 5000],
        'youtube_quota_budget' => [0, 1000000],
        'max_duration_minutes' => [1, 1440],
        'max_chunks' => [1, 500],
        'single_pass_tokens' => [500, 200000],
        'chunk_tokens' => [500, 200000],
        'min_words' => [1, 100000],
        'max_transcript_bytes' => [1024, 52428800],
        'max_job_attempts' => [1, 20],
        'tick_seconds' => [5, 600],
        'retention_days' => [1, 90],
        'api_data_days' => [1, 30],
        'draft_transcript_days' => [0, 3650],
        'default_author' => [0, PHP_INT_MAX],
        'default_category' => [0, PHP_INT_MAX],
        'model_token_cap' => [256, 128000],
    ];

    public const BOOLEANS = [
        'enabled', 'keep_annotations', 'topics_as_tags', 'allow_private_own', 'privacy_embed', 'allow_playlists',
    ];

    public const SECRETS = ['client_secret', 'api_key', 'llm_api_key'];

    /** Settings a constant can set, and the constant's name. */
    public const CONSTANTS = [
        'client_id' => 'YT_TO_POST_CLIENT_ID',
        'client_secret' => 'YT_TO_POST_CLIENT_SECRET',
        'api_key' => 'YT_TO_POST_API_KEY',
        'llm_api_key' => 'YT_TO_POST_LLM_API_KEY',
        'max_videos_per_day' => 'YT_TO_POST_DAILY_VIDEOS',
        'enabled' => 'YT_TO_POST_DISABLED',
    ];

    /**
     * @return array<string,mixed>
     */
    public static function all(bool $fresh = false): array
    {
        if ($fresh && function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }
        $stored = get_option(self::OPTION, []);
        $values = array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
        if ($values['site_country'] === '' && function_exists('get_locale')) {
            $parts = explode('_', (string) get_locale());
            $values['site_country'] = isset($parts[1]) && preg_match('/^[A-Z]{2}$/', $parts[1]) === 1 ? $parts[1] : '';
        }
        return $values;
    }

    public static function get(string $key, bool $fresh = false): mixed
    {
        $const = self::constantValue($key);
        if ($const !== null) {
            return $const;
        }
        return self::all($fresh)[$key] ?? null;
    }

    public static function int(string $key, bool $fresh = false): int
    {
        $v = self::get($key, $fresh);
        return is_numeric($v) ? (int) $v : (int) (self::DEFAULTS[$key] ?? 0);
    }

    public static function bool(string $key, bool $fresh = false): bool
    {
        return (bool) self::get($key, $fresh);
    }

    public static function string(string $key, bool $fresh = false): string
    {
        $v = self::get($key, $fresh);
        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * The value a constant forces, or null when no constant applies.
     */
    public static function constantValue(string $key): mixed
    {
        $name = self::CONSTANTS[$key] ?? null;
        if ($name === null || !defined($name)) {
            return null;
        }
        $v = constant($name);
        if ($key === 'enabled') {
            return $v ? false : null;
        }
        if ($key === 'max_videos_per_day') {
            return is_numeric($v) ? max(0, (int) $v) : null;
        }
        return is_string($v) && $v !== '' ? $v : null;
    }

    public static function constantName(string $key): ?string
    {
        return self::constantValue($key) !== null ? self::CONSTANTS[$key] : null;
    }

    /**
     * Validates submitted values. Out-of-range values are refused with a
     * message and the old value kept.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $old
     * @return array{0: array<string,mixed>, 1: list<string>}
     */
    public static function validate(array $input, array $old): array
    {
        $values = array_merge(self::DEFAULTS, $old);
        $errors = [];
        foreach (self::BOOLEANS as $k) {
            if (array_key_exists($k, $input)) {
                $values[$k] = in_array($input[$k], [true, 1, '1', 'on', 'yes', 'true'], true);
            }
        }
        foreach (self::RANGES as $k => [$min, $max]) {
            if (!array_key_exists($k, $input) || $input[$k] === '') {
                continue;
            }
            $raw = $input[$k];
            if (!is_numeric($raw) || (string) (int) $raw !== trim((string) $raw)) {
                $errors[] = sprintf('%s must be a whole number.', $k);
                continue;
            }
            $n = (int) $raw;
            if ($n < $min || $n > $max) {
                $errors[] = sprintf('%s must be between %d and %d.', $k, $min, $max);
                continue;
            }
            $values[$k] = $n;
        }
        if (array_key_exists('model_temperature', $input) && $input['model_temperature'] !== '') {
            $t = $input['model_temperature'];
            if (!is_numeric($t) || (float) $t < 0 || (float) $t > 2) {
                $errors[] = 'model_temperature must be between 0 and 2.';
            } else {
                $values['model_temperature'] = (float) $t;
            }
        }
        $enums = [
            'default_status' => ['draft', 'pending'],
            'api_data_on_expiry' => ['purge', 'refresh'],
            'model_kind' => ['openrouter', 'openai', 'generic'],
        ];
        foreach ($enums as $k => $allowed) {
            if (array_key_exists($k, $input)) {
                $v = is_string($input[$k]) ? $input[$k] : '';
                if (!in_array($v, $allowed, true)) {
                    $errors[] = sprintf('%s must be one of: %s.', $k, implode(', ', $allowed));
                } else {
                    $values[$k] = $v;
                }
            }
        }
        if (array_key_exists('article_language', $input)) {
            $v = is_string($input['article_language']) ? trim($input['article_language']) : '';
            if (in_array($v, ['source', 'site'], true) || Language::isValid($v)) {
                $values['article_language'] = $v;
            } else {
                $errors[] = 'article_language must be source, site or a BCP-47 tag.';
            }
        }
        if (array_key_exists('site_country', $input)) {
            $v = is_string($input['site_country']) ? strtoupper(trim($input['site_country'])) : '';
            if ($v === '' || preg_match('/^[A-Z]{2}$/', $v) === 1) {
                $values['site_country'] = $v;
            } else {
                $errors[] = 'site_country must be a two-letter country code.';
            }
        }
        if (array_key_exists('model_base_url', $input)) {
            $v = is_string($input['model_base_url']) ? trim($input['model_base_url']) : '';
            if ($v === '' || preg_match('#^https://[^\s/]+(/\S*)?$#', $v) === 1) {
                $values['model_base_url'] = $v;
            } else {
                $errors[] = 'model_base_url must be an https URL.';
            }
        }
        foreach (['model_ids', 'default_tags', 'client_id'] as $k) {
            if (array_key_exists($k, $input)) {
                $values[$k] = is_string($input[$k]) ? trim(strip_tags($input[$k])) : '';
            }
        }
        foreach (self::SECRETS as $k) {
            // An empty secret field keeps the stored value; "-" clears it.
            if (array_key_exists($k, $input) && is_string($input[$k]) && trim($input[$k]) !== '') {
                $values[$k] = trim($input[$k]) === '-' ? '' : trim($input[$k]);
            }
        }
        if ((int) $values['chunk_tokens'] > (int) $values['single_pass_tokens']) {
            $errors[] = 'chunk_tokens must not be larger than single_pass_tokens.';
            $values['chunk_tokens'] = $old['chunk_tokens'] ?? self::DEFAULTS['chunk_tokens'];
            $values['single_pass_tokens'] = $old['single_pass_tokens'] ?? self::DEFAULTS['single_pass_tokens'];
        }
        return [$values, $errors];
    }

    /**
     * @param array<string,mixed> $values
     */
    public static function save(array $values): void
    {
        update_option(self::OPTION, array_intersect_key($values, self::DEFAULTS), false);
    }

    /** Last four characters of a secret, never the secret itself. */
    public static function mask(string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        return str_repeat('*', 8) . substr($secret, -4);
    }

    /**
     * The model routes from settings, before the `yt_to_post_model_routes` filter.
     *
     * @return list<array{kind:string,model:string,base_url:string,token_cap:int,temperature:float}>
     */
    public static function modelRoutes(): array
    {
        $all = self::all();
        $routes = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $all['model_ids']))) as $model) {
            $routes[] = [
                'kind' => (string) $all['model_kind'],
                'model' => $model,
                'base_url' => (string) $all['model_base_url'],
                'token_cap' => (int) $all['model_token_cap'],
                'temperature' => (float) $all['model_temperature'],
            ];
        }
        return $routes;
    }

    public static function siteLanguage(): string
    {
        return Language::fromLocale(function_exists('get_locale') ? (string) get_locale() : 'en_US');
    }
}
