<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Integrations;

use IndianOpinion\YtToPost\Model\ModelResponse;

/**
 * Soft integration with spend-meter (PRD section 5.10), written against
 * spend-meter's published contract 1: the `spend_meter_pre_spend` filter
 * with an intent array, then exactly one `spend_meter_record` or
 * `spend_meter_release` action for a reservation it hands out.
 *
 * Active only when SPEND_METER_API equals 1. A null answer means no meter
 * is listening, so the plugin's own switch and caps decide. Any other
 * answer allows the call only when `allowed` is exactly true; an unknown
 * reason code or a malformed answer is a deny.
 */
final class SpendMeter
{
    public const PURPOSE = 'yt_to_post';
    public const CALLER = 'yt-to-post';
    public const CONTRACT = 1;

    public static function active(): bool
    {
        return defined('SPEND_METER_API') && (int) constant('SPEND_METER_API') === self::CONTRACT;
    }

    /**
     * Asks for a pre-spend decision.
     *
     * @return array{allowed:bool,reason:string,reservation_id:?string,provider:string,model:string}
     */
    public static function preSpend(string $provider, string $model, int $inputTokens, int $maxOutputTokens = 0): array
    {
        $out = ['allowed' => true, 'reason' => 'inactive', 'reservation_id' => null, 'provider' => self::slug($provider), 'model' => $model];
        if (!self::active()) {
            return $out;
        }
        $intent = [
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => $out['provider'],
            'model' => mb_substr($model, 0, 128),
            'est_input_tokens' => max(0, $inputTokens),
        ];
        if ($maxOutputTokens > 0) {
            $intent['max_output_tokens'] = $maxOutputTokens;
        }
        $answer = apply_filters('spend_meter_pre_spend', null, $intent);
        if ($answer === null) {
            $out['reason'] = 'absent';
            return $out;
        }
        if (!is_array($answer)) {
            return ['reason' => 'invalid_answer', 'allowed' => false] + $out;
        }
        $reservation = is_string($answer['reservation_id'] ?? null) && $answer['reservation_id'] !== '' ? $answer['reservation_id'] : null;
        $reason = is_string($answer['reason'] ?? null) && $answer['reason'] !== '' ? $answer['reason'] : 'no_reason';
        if (($answer['allowed'] ?? null) !== true) {
            // A deny holds nothing; give back a reservation if one came anyway.
            if ($reservation !== null) {
                do_action('spend_meter_release', $reservation);
            }
            return ['allowed' => false, 'reason' => $reason, 'reservation_id' => null] + $out;
        }
        return ['allowed' => true, 'reason' => $reason, 'reservation_id' => $reservation] + $out;
    }

    /**
     * Records a call that happened, successful or not.
     *
     * @param array{reservation_id:?string,provider:string,model:string} $decision
     */
    public static function record(array $decision, ?ModelResponse $response, string $status = 'ok', int $httpStatus = 0, string $errorCode = ''): void
    {
        if (!self::active()) {
            return;
        }
        $call = [
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => $decision['provider'],
            'model' => mb_substr($decision['model'], 0, 128),
            'status' => in_array($status, ['ok', 'error_billed', 'error_unbilled'], true) ? $status : 'error_unbilled',
        ];
        if ($decision['reservation_id'] !== null) {
            $call['reservation_id'] = $decision['reservation_id'];
        }
        if ($response !== null) {
            if ($response->provider !== '') {
                $call['provider'] = self::slug($response->provider);
            }
            if ($response->model !== '' && $response->model !== $decision['model']) {
                $call['model'] = mb_substr($response->model, 0, 128);
                $call['fallback_from'] = mb_substr($decision['model'], 0, 128);
            }
            $call['input_tokens'] = max(0, (int) $response->inputTokens);
            $call['output_tokens'] = max(0, (int) $response->outputTokens);
            $call['attempt'] = max(1, $response->attempts);
            if ($response->cost !== null && $response->cost >= 0) {
                // spend-meter counts money in nanos of the currency unit.
                $call['provider_cost'] = (int) round($response->cost * 1000000000);
            }
        }
        if ($httpStatus > 0) {
            $call['http_status'] = $httpStatus;
        }
        if ($errorCode !== '') {
            $call['error_code'] = substr((string) preg_replace('/[^a-z0-9_.-]/', '_', strtolower($errorCode)), 0, 64);
        }
        do_action('spend_meter_record', $call);
    }

    /**
     * Gives a reservation back when the call did not happen.
     *
     * @param array{reservation_id:?string} $decision
     */
    public static function release(array $decision): void
    {
        if (!self::active() || $decision['reservation_id'] === null) {
            return;
        }
        do_action('spend_meter_release', $decision['reservation_id']);
    }

    /** Provider slugs are lower case. */
    private static function slug(string $provider): string
    {
        $s = strtolower(trim($provider));
        return $s !== '' ? $s : 'unknown';
    }
}
