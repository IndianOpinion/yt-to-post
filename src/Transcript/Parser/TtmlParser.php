<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript\Parser;

use IndianOpinion\YtToPost\Exception\InvalidTranscriptException;
use IndianOpinion\YtToPost\Transcript\ParseResult;
use IndianOpinion\YtToPost\Transcript\Time;

/**
 * TTML (also DFXP). Streams with XMLReader, never resolves entities, never
 * touches the network, refuses a document type declaration and stops at a
 * node limit.
 */
final class TtmlParser implements TranscriptParser
{
    public const NODE_LIMIT = 200000;
    private const NS_PARAM = 'http://www.w3.org/ns/ttml#parameter';

    public function __construct(private readonly int $nodeLimit = self::NODE_LIMIT)
    {
    }

    public function format(): string
    {
        return 'ttml';
    }

    public function parse(string $text): ParseResult
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $text) === 1) {
            throw new InvalidTranscriptException('TTML with a document type declaration is not accepted.');
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            return $this->read($text);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function read(string $text): ParseResult
    {
        $reader = \XMLReader::XML($text, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT);
        if (!$reader instanceof \XMLReader) {
            throw new InvalidTranscriptException('TTML could not be read.');
        }
        $out = new CueCollector();
        $nodes = 0;
        $frameRate = 30.0;
        $tickRate = 1.0;
        $rootSeen = false;
        $advance = true;
        $ok = $reader->read();
        for (; $ok; $ok = $advance ? $reader->read() : $ok) {
            $advance = true;
            if ($reader->nodeType === \XMLReader::DOC_TYPE) {
                throw new InvalidTranscriptException('TTML with a document type declaration is not accepted.');
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if (++$nodes > $this->nodeLimit) {
                throw new InvalidTranscriptException('TTML has more than ' . $this->nodeLimit . ' nodes.');
            }
            if (!$rootSeen) {
                $rootSeen = true;
                if ($reader->localName !== 'tt') {
                    throw new InvalidTranscriptException('XML transcript is not TTML (root element is not tt).');
                }
                $fr = $reader->getAttributeNs('frameRate', self::NS_PARAM);
                if ($fr !== null && is_numeric($fr) && (float) $fr > 0) {
                    $frameRate = (float) $fr;
                    $tickRate = $frameRate;
                }
                $tr = $reader->getAttributeNs('tickRate', self::NS_PARAM);
                if ($tr !== null && is_numeric($tr) && (float) $tr > 0) {
                    $tickRate = (float) $tr;
                }
                continue;
            }
            if ($reader->localName !== 'p') {
                continue;
            }
            $begin = $reader->getAttribute('begin');
            $end = $reader->getAttribute('end');
            $dur = $reader->getAttribute('dur');
            $node = $reader->expand();
            if (!$node instanceof \DOMElement) {
                $out->bad($nodes);
                continue;
            }
            $nodes += $node->getElementsByTagName('*')->length;
            if ($nodes > $this->nodeLimit) {
                throw new InvalidTranscriptException('TTML has more than ' . $this->nodeLimit . ' nodes.');
            }
            $start = $begin === null ? null : $this->time($begin, $frameRate, $tickRate);
            $stop = $end === null ? null : $this->time($end, $frameRate, $tickRate);
            if ($stop === null && $dur !== null && $start !== null) {
                $d = $this->time($dur, $frameRate, $tickRate);
                $stop = $d === null ? null : $start + $d;
            }
            $out->add($start, $stop, $this->text($node), $nodes);
            // Skip the paragraph's subtree: next() already moves to the following node.
            $ok = $reader->next();
            $advance = false;
        }
        $errors = libxml_get_errors();
        if ($errors !== []) {
            throw new InvalidTranscriptException(sprintf('TTML is not well-formed near line %d.', $errors[0]->line));
        }
        if (!$rootSeen) {
            throw new InvalidTranscriptException('TTML has no root element.');
        }
        return $out->result($this->format());
    }

    private function text(\DOMNode $node): string
    {
        $s = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $s .= $child->data;
            } elseif ($child instanceof \DOMElement) {
                $s .= $child->localName === 'br' ? "\n" : $this->text($child);
            }
        }
        return $s;
    }

    public function time(string $v, float $frameRate = 30.0, float $tickRate = 1.0): ?float
    {
        $v = trim($v);
        if (preg_match('/^(\d+):(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?$/', $v, $m) === 1) {
            return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3] + ((int) $m[4]) / $frameRate;
        }
        if (preg_match('/^(\d+(?:\.\d+)?)(h|ms|m|s|f|t)$/', $v, $m) === 1) {
            $n = (float) $m[1];
            return match ($m[2]) {
                'h' => $n * 3600,
                'm' => $n * 60,
                's' => $n,
                'ms' => $n / 1000,
                'f' => $n / $frameRate,
                default => $n / $tickRate,
            };
        }
        return Time::parseClock($v);
    }
}
