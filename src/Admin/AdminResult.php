<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Admin;

/**
 * What an admin handler decided: where to go next. Kept apart from the
 * redirect and exit so handlers can be tested.
 */
final class AdminResult
{
    public function __construct(
        public readonly string $redirect,
        public readonly int $status = 200
    ) {
    }
}
