<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript\Parser;

use IndianOpinion\YtToPost\Transcript\ParseResult;

/**
 * One parser per transcript format. Input is valid UTF-8 without a BOM.
 */
interface TranscriptParser
{
    public function format(): string;

    public function parse(string $text): ParseResult;
}
