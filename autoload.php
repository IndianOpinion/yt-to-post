<?php

/**
 * Loads the plugin without Composer: a PSR-4 autoloader for src/, the
 * global functions, and llm-json-php from vendor/ or lib/ when present.
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
    if (is_file(__DIR__ . '/lib/llm-json-php/bootstrap.php')) {
        require_once __DIR__ . '/lib/llm-json-php/bootstrap.php';
    }
}

require_once __DIR__ . '/src/functions.php';
