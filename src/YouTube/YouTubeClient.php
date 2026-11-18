<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\YouTube;

use IndianOpinion\YtToPost\Exception\AuthException;
use IndianOpinion\YtToPost\Exception\ConfigurationException;
use IndianOpinion\YtToPost\Exception\QuotaException;
use IndianOpinion\YtToPost\Exception\YouTubeApiException;
use IndianOpinion\YtToPost\Http\HttpClient;
use IndianOpinion\YtToPost\Http\HttpResponse;
use IndianOpinion\YtToPost\Http\Retrier;
use IndianOpinion\YtToPost\Limits\QuotaMeter;
use IndianOpinion\YtToPost\Settings;

/**
 * The only YouTube calls the plugin makes: videos.list, channels.list,
 * captions.list, captions.download and (opt-in) playlistItems.list on the
 * documented Data API v3, plus the public oEmbed endpoint for title and
 * channel when no Google project is set up. It never fetches a watch page.
 */
final class YouTubeClient
{
    public const API = 'https://www.googleapis.com/youtube/v3/';
    public const OEMBED = 'https://www.youtube.com/oembed';
    public const OEMBED_MAX_BYTES = 65536;

    /** @var int quota units spent by this instance, for the job record */
    private int $spent = 0;

    public function __construct(
        private readonly HttpClient $http,
        private readonly Retrier $retrier,
        private readonly QuotaMeter $quota,
        private readonly AccessTokens $tokens
    ) {
    }

    public function unitsSpent(): int
    {
        return $this->spent;
    }

    public function resetSpent(): void
    {
        $this->spent = 0;
    }

    public static function apiKey(): string
    {
        return Settings::string('api_key');
    }

    public static function canReadMetadata(): bool
    {
        return Connection::isConnected() || self::apiKey() !== '';
    }

    /**
     * videos.list for one id. Null when the list is empty.
     *
     * @return array<string,mixed>|null
     */
    public function video(string $id): ?array
    {
        if (!self::canReadMetadata()) {
            throw new ConfigurationException('Connect a YouTube account or add an API key');
        }
        $json = $this->get('videos.list', 'videos', [
            'part' => 'snippet,contentDetails,status,liveStreamingDetails',
            'id' => $id,
        ], Connection::isConnected());
        $items = is_array($json['items'] ?? null) ? $json['items'] : [];
        $first = $items[0] ?? null;
        return is_array($first) ? $first : null;
    }

    /**
     * channels.list mine=true, once at connect time.
     *
     * @return array{id:string,title:string}|null
     */
    public function myChannel(): ?array
    {
        $json = $this->get('channels.list', 'channels', ['part' => 'snippet', 'mine' => 'true'], true);
        $item = is_array($json['items'][0] ?? null) ? $json['items'][0] : null;
        if ($item === null || !is_string($item['id'] ?? null)) {
            return null;
        }
        $title = is_array($item['snippet'] ?? null) && is_string($item['snippet']['title'] ?? null) ? $item['snippet']['title'] : '';
        return ['id' => $item['id'], 'title' => $title];
    }

    /**
     * captions.list. Never called with an API key alone.
     *
     * @return list<CaptionTrack>
     */
    public function captions(string $videoId): array
    {
        if (!Connection::isConnected()) {
            throw new AuthException('Caption tracks need a connected YouTube account.');
        }
        $json = $this->get('captions.list', 'captions', ['part' => 'snippet', 'videoId' => $videoId], true);
        $out = [];
        foreach (is_array($json['items'] ?? null) ? $json['items'] : [] as $item) {
            if (is_array($item)) {
                $out[] = CaptionTrack::fromApiItem($item);
            }
        }
        return $out;
    }

    /**
     * captions.download. $format is vtt, srt or null for the original. SCC
     * is never asked for. Translation through tlang is not offered.
     */
    public function download(string $trackId, ?string $format): string
    {
        if (!Connection::isConnected()) {
            throw new AuthException('Caption download needs a connected YouTube account.');
        }
        if ($format !== null && !in_array($format, ['vtt', 'srt'], true)) {
            throw new \InvalidArgumentException('Only vtt or srt may be requested.');
        }
        $q = $format === null ? [] : ['tfmt' => $format];
        $res = $this->send('captions.download', 'captions/' . rawurlencode($trackId), $q, true);
        return $res->body;
    }

    /**
     * playlistItems.list, one page. Video ids in playlist order.
     *
     * @return list<string>
     */
    public function playlistVideoIds(string $playlistId, int $max): array
    {
        if (!self::canReadMetadata()) {
            throw new ConfigurationException('Connect a YouTube account or add an API key');
        }
        $json = $this->get('playlistItems.list', 'playlistItems', [
            'part' => 'contentDetails',
            'playlistId' => $playlistId,
            'maxResults' => (string) max(1, min(50, $max)),
        ], Connection::isConnected());
        $ids = [];
        foreach (is_array($json['items'] ?? null) ? $json['items'] : [] as $item) {
            $id = is_array($item) && is_array($item['contentDetails'] ?? null) ? ($item['contentDetails']['videoId'] ?? null) : null;
            if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1) {
                $ids[] = $id;
            }
        }
        return array_slice(array_values(array_unique($ids)), 0, $max);
    }

    /**
     * YouTube's public oEmbed endpoint: title and channel only, no quota.
     * Null when the video is missing, private or not embeddable.
     *
     * @return array<string,mixed>|null
     */
    public function oEmbed(string $videoId): ?array
    {
        // Fixed https host, no redirects; only a validated id goes in the query.
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1) {
            return null;
        }
        $url = self::OEMBED . '?' . http_build_query([
            'url' => 'https://www.youtube.com/watch?v=' . $videoId,
            'format' => 'json',
        ]);
        $res = $this->retrier->run(function () use ($url): HttpResponse {
            $r = $this->http->request('GET', $url, ['Accept' => 'application/json'], null, 15);
            if ($r->status === 429 || $r->status >= 500) {
                throw new YouTubeApiException('oEmbed answered ' . $r->status, $r->status);
            }
            return $r;
        });
        if ($res->status !== 200 || strlen($res->body) > self::OEMBED_MAX_BYTES) {
            return null;
        }
        return $res->json();
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function get(string $method, string $path, array $query, bool $useToken): array
    {
        $res = $this->send($method, $path, $query, $useToken);
        $json = $res->json();
        if ($json === null) {
            throw new YouTubeApiException('YouTube sent an answer that is not JSON.', $res->status, 'invalidResponse');
        }
        return $json;
    }

    /**
     * Sends one call: reserves quota per attempt, retries what can change,
     * refreshes the access token once on a 401.
     *
     * @param array<string,string> $query
     */
    private function send(string $method, string $path, array $query, bool $useToken): HttpResponse
    {
        $refreshed = false;
        while (true) {
            $headers = ['Accept' => 'application/json'];
            if ($useToken) {
                $token = $this->tokens->get();
                if ($token === null) {
                    throw new AuthException('No YouTube account is connected.');
                }
                $headers['Authorization'] = 'Bearer ' . $token;
            } else {
                $query['key'] = self::apiKey();
            }
            $url = self::API . $path . ($query === [] ? '' : '?' . http_build_query($query));
            try {
                return $this->retrier->run(function () use ($method, $url, $headers): HttpResponse {
                    $this->spent += $this->quota->reserve($method);
                    $res = $this->http->request('GET', $url, $headers, null, 15);
                    if ($res->status >= 200 && $res->status < 300) {
                        return $res;
                    }
                    throw self::error($res);
                });
            } catch (YouTubeApiException $e) {
                if ($e->isQuotaExhausted()) {
                    $this->quota->exhaust();
                    throw new QuotaException('YouTube says the project quota is used up for today.', 403, $e);
                }
                if ($e->httpStatus() === 401 && $useToken && !$refreshed) {
                    $refreshed = true;
                    $this->tokens->forceRefresh();
                    continue;
                }
                throw $e;
            }
        }
    }

    public static function error(HttpResponse $res): YouTubeApiException
    {
        $json = $res->json() ?? [];
        $reason = '';
        $message = 'YouTube answered ' . $res->status;
        $err = $json['error'] ?? null;
        if (is_array($err)) {
            $first = is_array($err['errors'][0] ?? null) ? $err['errors'][0] : [];
            $reason = is_string($first['reason'] ?? null) ? $first['reason'] : (is_string($err['status'] ?? null) ? $err['status'] : '');
            if (is_string($err['message'] ?? null)) {
                $message .= ': ' . strip_tags($err['message']);
            }
        }
        $ra = $res->header('retry-after');
        $retryAfter = $ra !== null && ctype_digit(trim($ra)) ? (int) trim($ra) : null;
        return new YouTubeApiException($message, $res->status, $reason, $retryAfter);
    }
}
