<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript;

use IndianOpinion\YtToPost\Exception\InvalidTranscriptException;

/**
 * Encoding rules for supplied transcripts: UTF-8 with or without a BOM,
 * UTF-16 with a BOM converted, anything else refused with the offset of the
 * first bad byte. No code page guessing.
 */
final class Utf8
{
    public static function normalise(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        } elseif (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            $from = str_starts_with($bytes, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';
            $body = substr($bytes, 2);
            if (strlen($body) % 2 !== 0 || !mb_check_encoding($body, $from)) {
                throw new InvalidTranscriptException('The UTF-16 file is damaged and cannot be converted.');
            }
            $bytes = (string) mb_convert_encoding($body, 'UTF-8', $from);
        }
        $bad = self::firstInvalidOffset($bytes);
        if ($bad !== null) {
            throw new InvalidTranscriptException(sprintf(
                'The transcript is not valid UTF-8 (first bad byte at offset %d). Save it as UTF-8 and try again.',
                $bad
            ));
        }
        return $bytes;
    }

    /** Byte offset of the first invalid UTF-8 sequence, or null. */
    public static function firstInvalidOffset(string $s): ?int
    {
        if (preg_match('//u', $s) === 1) {
            return null;
        }
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $c = ord($s[$i]);
            if ($c < 0x80) {
                $i++;
                continue;
            }
            $need = match (true) {
                $c >= 0xC2 && $c <= 0xDF => 1,
                $c >= 0xE0 && $c <= 0xEF => 2,
                $c >= 0xF0 && $c <= 0xF4 => 3,
                default => -1,
            };
            if ($need < 0) {
                return $i;
            }
            $seq = substr($s, $i, $need + 1);
            if (strlen($seq) !== $need + 1 || preg_match('//u', $seq) !== 1) {
                return $i;
            }
            $i += $need + 1;
        }
        return null;
    }

    /** Cuts to at most $bytes bytes without breaking a sequence. */
    public static function cut(string $s, int $bytes): string
    {
        if (strlen($s) <= $bytes) {
            return $s;
        }
        $cut = substr($s, 0, $bytes);
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }
        return $cut;
    }
}
