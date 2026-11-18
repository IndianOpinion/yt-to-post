<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Exception;

/**
 * No usable captions: the editor has to supply a transcript.
 */
class NeedsTranscriptException extends YtToPostException
{
}
