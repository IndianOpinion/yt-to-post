<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\YouTube;

use IndianOpinion\YtToPost\Exception\AuthException;
use IndianOpinion\YtToPost\Exception\NetworkException;
use IndianOpinion\YtToPost\Http\HttpClient;
use IndianOpinion\YtToPost\Settings;
use IndianOpinion\YtToPost\Support\Clock;

/**
 * OAuth 2.0 authorization code flow for web server applications, with PKCE
 * (S256), offline access and a forced consent prompt (PRD cases 74 to 79).
 */
final class OAuthClient
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    public const SCOPE = 'https://www.googleapis.com/auth/youtube.force-ssl';
    public const STATE_PREFIX = 'yt_to_post_oauth_';
    public const STATE_TTL = 600;

    public function __construct(
        private readonly HttpClient $http,
        private readonly TokenVault $vault,
        private readonly Clock $clock
    ) {
    }

    public static function redirectUri(): string
    {
        return admin_url('admin-post.php?action=yt_to_post_oauth');
    }

    public static function clientId(): string
    {
        return Settings::string('client_id');
    }

    private static function clientSecret(): string
    {
        return Settings::string('client_secret');
    }

    public static function hasClient(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    /**
     * Builds the consent URL and stores a single-use state bound to the user.
     */
    public function authorizationUrl(int $userId): string
    {
        if (!self::hasClient()) {
            throw new AuthException('Add the OAuth client id and secret first.');
        }
        $state = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        set_transient(self::STATE_PREFIX . $state, ['user' => $userId, 'verifier' => $verifier, 'at' => $this->clock->now()], self::STATE_TTL);
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => self::clientId(),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Handles the redirect back from Google. Stores nothing unless every
     * check passes. Returns a message for the administrator.
     *
     * @param array<string,mixed> $query
     * @throws AuthException
     */
    public function handleCallback(array $query, int $userId, bool $canManage, ?YouTubeClient $youtube = null): string
    {
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $stored = $state !== '' && preg_match('/^[a-f0-9]{32}$/', $state) === 1
            ? get_transient(self::STATE_PREFIX . $state) : false;
        if ($state !== '') {
            delete_transient(self::STATE_PREFIX . $state);
        }
        if (!$canManage) {
            throw new AuthException('You are not allowed to connect a YouTube account.');
        }
        if (!is_array($stored) || (int) ($stored['user'] ?? -1) !== $userId) {
            throw new AuthException('The connection request expired or did not come from you. Start again.');
        }
        if (isset($query['error'])) {
            $err = is_string($query['error']) ? $query['error'] : 'unknown';
            throw new AuthException('Google refused the connection: ' . preg_replace('/[^a-z_]/', '', $err));
        }
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        if ($code === '') {
            throw new AuthException('Google sent no authorisation code.');
        }
        $tokens = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirectUri(),
            'code_verifier' => (string) $stored['verifier'],
        ]);
        $refresh = is_string($tokens['refresh_token'] ?? null) ? $tokens['refresh_token'] : '';
        if ($refresh === '') {
            throw new AuthException(
                'Google did not send a refresh token because this account had already granted access. '
                . 'Remove the app\'s access in your Google account settings, then connect again.'
            );
        }
        Connection::save([
            'state' => Connection::CONNECTED,
            'token' => $this->vault->encrypt($refresh),
            'connected_at' => $this->clock->now(),
            'channel_id' => '',
            'channel_title' => '',
        ]);
        $this->storeAccess($tokens);
        $msg = 'YouTube account connected.';
        if ($youtube !== null) {
            try {
                $ch = $youtube->myChannel();
                if ($ch !== null) {
                    Connection::save(['channel_id' => $ch['id'], 'channel_title' => $ch['title']]);
                    $msg = 'YouTube account connected: ' . $ch['title'] . '.';
                }
            } catch (\Throwable $e) {
                $msg .= ' The channel could not be read yet: ' . $e->getMessage();
            }
        }
        if (function_exists('do_action')) {
            do_action('yt_to_post_connection_changed', Connection::CONNECTED);
        }
        return $msg;
    }

    /**
     * Exchanges the refresh token for a new access token.
     *
     * @return array{token:string,expires_in:int}
     * @throws AuthException
     */
    public function refresh(): array
    {
        $c = Connection::get();
        if ($c['token'] === '') {
            throw new AuthException('No YouTube account is connected.');
        }
        $refresh = $this->vault->decrypt($c['token']);
        if ($refresh === null) {
            Connection::setState(Connection::NEEDS_RECONNECT);
            throw new AuthException('The stored YouTube token cannot be read (the site keys changed?). Reconnect the account.');
        }
        try {
            $tokens = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
        } catch (AuthException $e) {
            if ($e->getCode() === 400 && str_contains($e->getMessage(), 'invalid_grant')) {
                Connection::setState(Connection::NEEDS_RECONNECT);
                throw new AuthException(
                    'Google refused the stored token (invalid_grant): it was revoked, expired, or the Google project is in testing status. Reconnect the account.',
                    401,
                    $e
                );
            }
            throw $e;
        }
        return $this->storeAccess($tokens);
    }

    /**
     * Revokes at Google, then deletes locally even when the revoke fails.
     * Returns null on success or the revoke error message.
     */
    public function disconnect(int $timeout = 15): ?string
    {
        $c = Connection::get();
        $error = null;
        $refresh = $c['token'] !== '' ? $this->vault->decrypt($c['token']) : null;
        if ($refresh !== null) {
            try {
                $res = $this->http->request(
                    'POST',
                    self::REVOKE_URL,
                    ['Content-Type' => 'application/x-www-form-urlencoded'],
                    http_build_query(['token' => $refresh]),
                    $timeout
                );
                if ($res->status !== 200) {
                    $error = 'Google answered ' . $res->status . ' to the revoke request.';
                }
            } catch (NetworkException $e) {
                $error = $e->getMessage();
            }
        }
        Connection::clear();
        return $error;
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private function tokenRequest(array $params): array
    {
        $params['client_id'] = self::clientId();
        $params['client_secret'] = self::clientSecret();
        $res = $this->http->request(
            'POST',
            self::TOKEN_URL,
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            http_build_query($params),
            15
        );
        $json = $res->json() ?? [];
        if ($res->status !== 200) {
            $err = is_string($json['error'] ?? null) ? $json['error'] : 'http_' . $res->status;
            throw new AuthException('Google token request failed: ' . $err, $res->status);
        }
        if (!is_string($json['access_token'] ?? null)) {
            throw new AuthException('Google token response has no access token.');
        }
        return $json;
    }

    /**
     * @param array<string,mixed> $tokens
     * @return array{token:string,expires_in:int}
     */
    private function storeAccess(array $tokens): array
    {
        $token = (string) $tokens['access_token'];
        $expires = (int) ($tokens['expires_in'] ?? 3600);
        $ttl = $expires - 60;
        if ($ttl > 0) {
            set_transient(AccessTokens::TRANSIENT, $token, $ttl);
        }
        return ['token' => $token, 'expires_in' => $expires];
    }
}
