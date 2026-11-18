<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Integrations;

/**
 * Hook names of the soft integrations. They are kept in one place and can
 * be changed with the `yt_to_post_integration_hooks` filter, so a site can
 * follow a renamed hook without a code change.
 */
final class Hooks
{
    public const DEFAULTS = [
        'gen_queue_enqueue' => 'gen_queue_soft_enqueue',
        'gen_queue_register' => 'gen_queue_register_job_type',
        'spend_pre_spend' => 'spend_meter_pre_spend',
        'spend_record' => 'spend_meter_record',
        'spend_release' => 'spend_meter_release',
    ];

    public static function name(string $key): string
    {
        $names = self::DEFAULTS;
        if (function_exists('apply_filters')) {
            $f = apply_filters('yt_to_post_integration_hooks', $names);
            if (is_array($f)) {
                foreach ($names as $k => $v) {
                    if (isset($f[$k]) && is_string($f[$k]) && $f[$k] !== '') {
                        $names[$k] = $f[$k];
                    }
                }
            }
        }
        return $names[$key];
    }
}
