<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Support;

/**
 * Fresh option reads that bypass the object cache.
 */
final class Options
{
    public static function fresh(string $name, mixed $default = false): mixed
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($name, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }
        return get_option($name, $default);
    }

    public static function freshInt(string $name): int
    {
        $v = self::fresh($name, 0);
        return is_numeric($v) ? (int) $v : 0;
    }
}
