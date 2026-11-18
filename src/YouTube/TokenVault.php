<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\YouTube;

/**
 * Encrypts the refresh token with libsodium secretbox under a key derived
 * from the site's auth salts and a fixed context label. If the salts change
 * the token cannot be decrypted, and the caller treats it as a reconnect.
 */
final class TokenVault
{
    private const CONTEXT = 'yt-to-post refresh token v1';

    /** @var \Closure():string */
    private \Closure $salt;

    /**
     * @param (callable():string)|null $salt
     */
    public function __construct(?callable $salt = null)
    {
        $this->salt = $salt === null
            ? static fn (): string => function_exists('wp_salt') ? wp_salt('auth') : ''
            : \Closure::fromCallable($salt);
    }

    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    /** Null when the value cannot be decrypted (changed salts, damage). */
    public function decrypt(string $stored): ?string
    {
        if (!str_starts_with($stored, 'v1:')) {
            return null;
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        try {
            $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key());
        } catch (\SodiumException) {
            return null;
        }
        return $plain === false ? null : $plain;
    }

    private function key(): string
    {
        return hash_hmac('sha256', self::CONTEXT, ($this->salt)(), true);
    }
}
