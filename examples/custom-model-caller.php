<?php

/**
 * A must-use plugin snippet: plug in your own model caller and run without
 * llm-json-php. The callable gets a ModelRequest (purpose, schema, system,
 * input) and returns an array that matches $request->schema.
 *
 * Copy to wp-content/mu-plugins/yt-to-post-my-caller.php and adapt.
 */

declare(strict_types=1);

use IndianOpinion\YtToPost\Model\ModelRequest;

if (!defined('ABSPATH')) {
    exit;
}

add_filter('yt_to_post_model_caller', static function ($caller) {
    return static function (ModelRequest $request): array {
        $response = wp_remote_post('https://llm.example.org/v1/json', [
            'timeout' => 60,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string) wp_json_encode([
                'schema' => $request->schema,
                'system' => $request->system,
                'input' => $request->input,
            ]),
        ]);
        if (is_wp_error($response)) {
            throw new \IndianOpinion\YtToPost\Exception\ModelCallException($response->get_error_message());
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            throw new \IndianOpinion\YtToPost\Exception\ModelOutputException('Not JSON.');
        }
        return $data;
    };
});
