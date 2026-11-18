<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\YouTube;

/**
 * The access token cache: a transient that expires 60 seconds early.
 */
final class AccessTokens
{
    public const TRANSIENT = 'yt_to_post_access_token';

    public function __construct(private readonly OAuthClient $oauth)
    {
    }

    public function get(): ?string
    {
        if (!Connection::isConnected()) {
            return null;
        }
        $t = get_transient(self::TRANSIENT);
        if (is_string($t) && $t !== '') {
            return $t;
        }
        return $this->oauth->refresh()['token'];
    }

    public function forceRefresh(): string
    {
        delete_transient(self::TRANSIENT);
        return $this->oauth->refresh()['token'];
    }
}
