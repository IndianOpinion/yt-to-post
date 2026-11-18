<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\YouTube;

/**
 * The stored OAuth connection: state, encrypted refresh token, connected
 * channel. Autoload off.
 */
final class Connection
{
    public const OPTION = 'yt_to_post_connection';
    public const CONNECTED = 'connected';
    public const DISCONNECTED = 'disconnected';
    public const NEEDS_RECONNECT = 'needs_reconnect';

    /** @return array{state:string,token:string,channel_id:string,channel_title:string,connected_at:int} */
    public static function get(): array
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION, 'options');
        }
        $v = get_option(self::OPTION, []);
        $v = is_array($v) ? $v : [];
        return [
            'state' => is_string($v['state'] ?? null) ? $v['state'] : self::DISCONNECTED,
            'token' => is_string($v['token'] ?? null) ? $v['token'] : '',
            'channel_id' => is_string($v['channel_id'] ?? null) ? $v['channel_id'] : '',
            'channel_title' => is_string($v['channel_title'] ?? null) ? $v['channel_title'] : '',
            'connected_at' => (int) ($v['connected_at'] ?? 0),
        ];
    }

    public static function state(): string
    {
        return self::get()['state'];
    }

    public static function isConnected(): bool
    {
        $c = self::get();
        return $c['state'] === self::CONNECTED && $c['token'] !== '';
    }

    public static function channelId(): string
    {
        return self::get()['channel_id'];
    }

    /**
     * @param array<string,mixed> $values
     */
    public static function save(array $values): void
    {
        update_option(self::OPTION, array_merge(self::get(), $values), false);
    }

    public static function setState(string $state): void
    {
        $before = self::state();
        self::save(['state' => $state]);
        if ($before !== $state && function_exists('do_action')) {
            do_action('yt_to_post_connection_changed', $state);
        }
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
        delete_transient(AccessTokens::TRANSIENT);
        if (function_exists('do_action')) {
            do_action('yt_to_post_connection_changed', self::DISCONNECTED);
        }
    }
}
