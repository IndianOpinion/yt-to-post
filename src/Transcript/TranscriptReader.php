<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript;

use IndianOpinion\YtToPost\Exception\InvalidTranscriptException;
use IndianOpinion\YtToPost\Transcript\Parser\PlainTextParser;
use IndianOpinion\YtToPost\Transcript\Parser\SbvParser;
use IndianOpinion\YtToPost\Transcript\Parser\SrtParser;
use IndianOpinion\YtToPost\Transcript\Parser\TimestampedTextParser;
use IndianOpinion\YtToPost\Transcript\Parser\TranscriptParser;
use IndianOpinion\YtToPost\Transcript\Parser\TtmlParser;
use IndianOpinion\YtToPost\Transcript\Parser\VttParser;

/**
 * Detects the format from the content (never the file name), fixes the
 * encoding, parses, and applies the malformed-cue rule.
 */
final class TranscriptReader
{
    public const DEFAULT_MAX_BYTES = 5242880;

    public function __construct(private readonly int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
    }

    public function read(string $bytes): ParseResult
    {
        if (strlen($bytes) > $this->maxBytes) {
            throw new InvalidTranscriptException(sprintf(
                'The transcript is larger than the limit of %d bytes.',
                $this->maxBytes
            ));
        }
        $text = Utf8::normalise($bytes);
        if (trim($text) === '') {
            throw new InvalidTranscriptException('The transcript is empty.');
        }
        $parser = self::detect($text);
        $result = $parser->parse($text);
        if ($result->total > 0 && $result->malformed * 2 > $result->total) {
            throw new InvalidTranscriptException(sprintf(
                '%d of %d cues are malformed (first at line %d). Check the file and export it again.',
                $result->malformed,
                $result->total,
                (int) $result->firstBadLine
            ));
        }
        if ($result->cues === []) {
            throw new InvalidTranscriptException('No cues could be read from the transcript.');
        }
        return $result;
    }

    public static function detect(string $text): TranscriptParser
    {
        $head = ltrim(substr($text, 0, 4096));
        if (str_starts_with($head, 'Scenarist_SCC')) {
            throw new InvalidTranscriptException('SCC is not supported, export WebVTT or SRT');
        }
        if (str_starts_with($head, 'WEBVTT')) {
            return new VttParser();
        }
        if (str_starts_with($head, '<')) {
            return new TtmlParser();
        }
        if (preg_match('/^\s*\d+\s*\R\s*\d{1,2}:\d{2}:\d{2}[,.]\d{1,3}\s*-->/', $head) === 1) {
            return new SrtParser();
        }
        if (preg_match('/^\s*\d+:\d{2}:\d{2}\.\d{3},\d+:\d{2}:\d{2}\.\d{3}\s*$/m', $head) === 1) {
            return new SbvParser();
        }
        if (TimestampedTextParser::looksLike($text)) {
            return new TimestampedTextParser();
        }
        return new PlainTextParser();
    }
}
