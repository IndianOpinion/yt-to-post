<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Support;

use IndianOpinion\YtToPost\Exception\NetworkException;
use IndianOpinion\YtToPost\Http\HttpClient;
use IndianOpinion\YtToPost\Http\HttpResponse;

/**
 * Routes requests by URL pattern to scripted responses and records them.
 * Unrouted requests fail the test loudly. No network.
 */
final class FakeHttp implements HttpClient
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int}> */
    public array $requests = [];
    /** @var list<array{0:string,1:list<HttpResponse|NetworkException|\Closure>}> */
    private array $routes = [];

    /**
     * Responses are used in order; the last one repeats. A later route for
     * the same URL wins over an earlier one.
     *
     * @param HttpResponse|NetworkException|\Closure ...$responses
     */
    public function on(string $pattern, HttpResponse|NetworkException|\Closure ...$responses): self
    {
        array_unshift($this->routes, [$pattern, array_values($responses)]);
        return $this;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];
        foreach ($this->routes as $i => [$pattern, $responses]) {
            if (preg_match($pattern, $url) !== 1) {
                continue;
            }
            $r = count($responses) > 1 ? array_shift($this->routes[$i][1]) : $responses[0];
            if ($r instanceof \Closure) {
                $r = $r($method, $url, $headers, $body);
            }
            if ($r instanceof NetworkException) {
                throw $r;
            }
            return $r;
        }
        throw new \RuntimeException('Unrouted request in test: ' . $method . ' ' . $url);
    }

    /** @return list<string> */
    public function urls(string $contains = ''): array
    {
        $out = [];
        foreach ($this->requests as $r) {
            if ($contains === '' || str_contains($r['url'], $contains)) {
                $out[] = $r['url'];
            }
        }
        return $out;
    }

    public static function json(array $data, int $status = 200, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($data), $headers);
    }

    public static function apiError(int $status, string $reason): HttpResponse
    {
        return self::json(['error' => ['code' => $status, 'message' => $reason, 'errors' => [['reason' => $reason]]]], $status);
    }
}
