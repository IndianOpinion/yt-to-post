<?php

/**
 * Loads the plugin: Composer's autoloader when vendor/ exists, otherwise a
 * PSR-4 autoloader for src/. llm-json-php is a Composer dependency and is
 * never bundled; without it, plug in a caller with yt_to_post_model_caller.
 */

declare(strict_types=1);

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'IndianOpinion\\YtToPost\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

require_once __DIR__ . '/src/functions.php';
