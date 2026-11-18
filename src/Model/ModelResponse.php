<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Model;

final class ModelResponse
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly int $attempts = 1,
        public readonly string $provider = '',
        public readonly string $model = '',
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?float $cost = null
    ) {
    }
}
