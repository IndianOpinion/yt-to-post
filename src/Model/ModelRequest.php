<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Model;

final class ModelRequest
{
    /**
     * @param array<string,mixed> $schema
     */
    public function __construct(
        public readonly string $purpose,
        public readonly string $schemaName,
        public readonly array $schema,
        public readonly string $system,
        public readonly string $input,
        public readonly ?float $deadlineSeconds = null
    ) {
    }
}
