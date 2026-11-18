<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost;

use IndianOpinion\YtToPost\Jobs\Job;

/**
 * Who may do what. Both capabilities are filterable.
 */
final class Capabilities
{
    public static function create(): string
    {
        $c = apply_filters('yt_to_post_capability_create', 'edit_posts');
        return is_string($c) && $c !== '' ? $c : 'edit_posts';
    }

    public static function settings(): string
    {
        $c = apply_filters('yt_to_post_capability_settings', 'manage_options');
        return is_string($c) && $c !== '' ? $c : 'manage_options';
    }

    public static function canCreate(): bool
    {
        return current_user_can(self::create());
    }

    public static function canManage(): bool
    {
        return current_user_can(self::settings());
    }

    /** Own jobs, or every job with the settings capability. */
    public static function canSeeJob(Job $job): bool
    {
        if (!self::canCreate()) {
            return false;
        }
        return self::canManage() || $job->userId === get_current_user_id();
    }
}
