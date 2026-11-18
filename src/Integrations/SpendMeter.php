<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Integrations;

use IndianOpinion\YtToPost\Model\ModelResponse;

/**
 * Soft integration with spend-meter (PRD section 5.10). Active only when
 * SPEND_METER_API equals 1. The model is called only when the answer says
 * `allowed` is exactly true; anything else, including an unknown answer,
 * is a deny.
 */
final class SpendMeter
{
    public static function active(): bool
    {
        return defined('SPEND_METER_API') && (int) constant('SPEND_METER_API') === 1;
    }

    /**
     * @return array{allowed:bool,reason:string,reservation:mixed}
     */
    public static function preSpend(string $provider, string $model, int $inputTokens): array
    {
        if (!self::active()) {
            return ['allowed' => true, 'reason' => 'inactive', 'reservation' => null];
        }
        $answer = apply_filters(Hooks::name('spend_pre_spend'), null, [
            'purpose' => 'yt_to_post',
            'caller' => 'yt-to-post',
            'provider' => $provider,
            'model' => $model,
            'estimated_input_tokens' => $inputTokens,
        ]);
        if (!is_array($answer) || ($answer['allowed'] ?? null) !== true) {
            $reason = is_array($answer) && is_string($answer['reason'] ?? null) ? $answer['reason'] : 'no_answer';
            return ['allowed' => false, 'reason' => $reason, 'reservation' => is_array($answer) ? ($answer['reservation'] ?? null) : null];
        }
        return [
            'allowed' => true,
            'reason' => is_string($answer['reason'] ?? null) ? $answer['reason'] : 'allowed',
            'reservation' => $answer['reservation'] ?? null,
        ];
    }

    public static function record(mixed $reservation, ModelResponse $response): void
    {
        if (!self::active()) {
            return;
        }
        do_action(Hooks::name('spend_record'), $reservation, [
            'caller' => 'yt-to-post',
            'provider' => $response->provider,
            'model' => $response->model,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost' => $response->cost,
        ]);
    }

    public static function release(mixed $reservation): void
    {
        if (!self::active()) {
            return;
        }
        do_action(Hooks::name('spend_release'), $reservation, ['caller' => 'yt-to-post']);
    }
}
