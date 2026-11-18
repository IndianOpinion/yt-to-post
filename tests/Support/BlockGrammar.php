<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Support;

/**
 * A strict reading of the block grammar parse_blocks() uses: every opener
 * has a matching closer, nesting is proper, attributes are a JSON object,
 * and no HTML sits between top-level blocks. Returns top-level block names.
 */
final class BlockGrammar
{
    /** @return list<string> */
    public static function parse(string $content): array
    {
        $re = '/<!--\s+(\/)?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+(\{(?:(?!-->).)*?\}\s+)?(\/)?-->/s';
        preg_match_all($re, $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $stack = [];
        $top = [];
        $last = 0;
        foreach ($m as $tok) {
            $closer = $tok[1][0] === '/';
            $name = $tok[2][0];
            $attrs = isset($tok[3]) ? trim($tok[3][0]) : '';
            $offset = $tok[0][1];
            if ($stack === [] && trim(substr($content, $last, $offset - $last)) !== '') {
                throw new \RuntimeException('Freeform HTML between blocks at ' . $last);
            }
            if ($attrs !== '' && !is_array(json_decode($attrs, true))) {
                throw new \RuntimeException('Bad attributes for ' . $name);
            }
            if ($closer) {
                $open = array_pop($stack);
                if ($open !== $name) {
                    throw new \RuntimeException("Closer $name does not match " . (string) $open);
                }
            } else {
                if ($stack === []) {
                    $top[] = $name;
                }
                $stack[] = $name;
            }
            $last = $offset + strlen($tok[0][0]);
        }
        if ($stack !== []) {
            throw new \RuntimeException('Unclosed block ' . implode(',', $stack));
        }
        if (trim(substr($content, $last)) !== '') {
            throw new \RuntimeException('Freeform HTML after the last block');
        }
        return $top;
    }
}
