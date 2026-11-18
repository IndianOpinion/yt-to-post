<?php

/**
 * Test stand-in for gen-queue's registration function, loaded only by the
 * gen-queue test, which runs in its own process.
 */

declare(strict_types=1);

/**
 * @param array<string,mixed> $args
 */
function gen_queue_register_type(string $type, callable $handler, array $args = []): void
{
    $GLOBALS['gq_types'][$type] = $handler;
}
