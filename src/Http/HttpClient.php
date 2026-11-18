<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Http;

use IndianOpinion\YtToPost\Exception\NetworkException;

interface HttpClient
{
    /**
     * @param array<string,string> $headers
     * @throws NetworkException when no response arrives
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): HttpResponse;
}
