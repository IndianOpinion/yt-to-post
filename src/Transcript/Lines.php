<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript;

/**
 * Walks a string line by line without splitting it into an array.
 */
final class Lines
{
    /**
     * @return \Generator<int,string> line number (1-based) => line without its break
     */
    public static function of(string $text): \Generator
    {
        $len = strlen($text);
        $pos = 0;
        $n = 0;
        while ($pos < $len) {
            $nl = strpos($text, "\n", $pos);
            if ($nl === false) {
                $nl = $len;
            }
            $line = substr($text, $pos, $nl - $pos);
            if ($line !== '' && $line[-1] === "\r") {
                $line = substr($line, 0, -1);
            }
            yield ++$n => $line;
            $pos = $nl + 1;
        }
    }
}
