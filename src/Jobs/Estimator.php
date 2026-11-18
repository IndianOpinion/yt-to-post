<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

use IndianOpinion\YtToPost\Settings;

/**
 * Rough pre-flight numbers from the duration alone, shown before submit.
 * Speech runs at about 160 words a minute, about 6 bytes a word, and the
 * default estimate is 3 bytes a token: about 320 tokens a minute.
 */
final class Estimator
{
    public const TOKENS_PER_MINUTE = 320;

    /**
     * @return array{tokens:int,chunks:int,model_calls:int,quota_units:int}
     */
    public static function fromDuration(?int $seconds, bool $captions): array
    {
        $tokens = (int) ceil(((int) $seconds) / 60 * self::TOKENS_PER_MINUTE);
        $single = Settings::int('single_pass_tokens');
        $chunk = max(1, Settings::int('chunk_tokens'));
        $chunks = $tokens <= $single ? 1 : (int) ceil($tokens / $chunk);
        $calls = $chunks === 1 ? 1 : $chunks + 1;
        $units = 1 + ($captions ? 250 : 0);
        return ['tokens' => $tokens, 'chunks' => $chunks, 'model_calls' => $calls, 'quota_units' => $units];
    }
}
