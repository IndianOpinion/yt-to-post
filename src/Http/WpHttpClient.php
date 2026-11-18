<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Http;

use IndianOpinion\YtToPost\Exception\NetworkException;

/**
 * The WordPress HTTP API, with TLS verification on and redirects off.
 */
final class WpHttpClient implements HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): HttpResponse
    {
        if (!str_starts_with($url, 'https://')) {
            throw new NetworkException('Refusing a non-HTTPS request.');
        }
        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $timeout,
            'redirection' => 0,
            'sslverify' => true,
            'user-agent' => 'yt-to-post/' . (defined('YT_TO_POST_VERSION') ? (string) YT_TO_POST_VERSION : '0'),
        ];
        if ($body !== null) {
            $args['body'] = $body;
        }
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            throw new NetworkException('Network error: ' . $res->get_error_message());
        }
        $headersOut = [];
        $raw = wp_remote_retrieve_headers($res);
        if (is_object($raw) && method_exists($raw, 'getAll')) {
            $raw = $raw->getAll();
        }
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                $headersOut[strtolower((string) $k)] = is_array($v) ? (string) end($v) : (string) $v;
            }
        }
        return new HttpResponse((int) wp_remote_retrieve_response_code($res), (string) wp_remote_retrieve_body($res), $headersOut);
    }
}
