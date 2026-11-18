<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Exception;

/**
 * The job was cancelled while it ran. Raised before the next external call.
 */
final class CancelledException extends YtToPostException
{
}
