<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Admin;

/**
 * One-shot messages for the next admin page load, per user.
 */
final class Flash
{
    public static function add(string $message, string $type = 'success'): void
    {
        $key = 'yt_to_post_flash_' . get_current_user_id();
        $list = get_transient($key);
        $list = is_array($list) ? $list : [];
        $list[] = ['message' => $message, 'type' => in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info'];
        set_transient($key, $list, 300);
    }

    /** @return list<array{message:string,type:string}> */
    public static function take(): array
    {
        $key = 'yt_to_post_flash_' . get_current_user_id();
        $list = get_transient($key);
        delete_transient($key);
        $out = [];
        foreach (is_array($list) ? $list : [] as $m) {
            if (is_array($m) && is_string($m['message'] ?? null)) {
                $out[] = ['message' => $m['message'], 'type' => is_string($m['type'] ?? null) ? $m['type'] : 'info'];
            }
        }
        return $out;
    }

    public static function render(): void
    {
        foreach (self::take() as $m) {
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($m['type']), esc_html($m['message']));
        }
    }
}
