<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript;

/**
 * A list of cues plus the language and where the words came from.
 */
final class Transcript
{
    public const KIND_CAPTIONS = 'captions';
    public const KIND_CAPTIONS_ASR = 'captions_asr';
    public const KIND_TRANSCRIPT = 'transcript';

    /**
     * @param list<Cue> $cues
     */
    public function __construct(
        public readonly array $cues,
        public readonly string $language,
        public readonly string $sourceKind
    ) {
    }

    public function hasTimes(): bool
    {
        foreach ($this->cues as $c) {
            if ($c->start !== null) {
                return true;
            }
        }
        return false;
    }

    public function text(): string
    {
        return implode(' ', array_map(static fn (Cue $c): string => $c->text, $this->cues));
    }

    public function isAsr(): bool
    {
        return $this->sourceKind === self::KIND_CAPTIONS_ASR;
    }

    /** Compressed JSON for storage; plain JSON when zlib is missing. */
    public function encode(): string
    {
        $json = (string) json_encode([
            'l' => $this->language,
            'k' => $this->sourceKind,
            'c' => array_map(static fn (Cue $c): array => $c->toArray(), $this->cues),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (function_exists('gzcompress')) {
            $z = gzcompress($json, 6);
            if ($z !== false) {
                return 'z:' . base64_encode($z);
            }
        }
        return 'j:' . $json;
    }

    public static function decode(string $stored): ?self
    {
        $json = null;
        if (str_starts_with($stored, 'z:') && function_exists('gzuncompress')) {
            $raw = base64_decode(substr($stored, 2), true);
            $json = $raw === false ? null : gzuncompress($raw);
        } elseif (str_starts_with($stored, 'j:')) {
            $json = substr($stored, 2);
        }
        if (!is_string($json)) {
            return null;
        }
        $a = json_decode($json, true);
        if (!is_array($a) || !is_array($a['c'] ?? null)) {
            return null;
        }
        $cues = [];
        foreach ($a['c'] as $c) {
            if (is_array($c)) {
                $cues[] = Cue::fromArray($c);
            }
        }
        return new self($cues, (string) ($a['l'] ?? ''), (string) ($a['k'] ?? self::KIND_TRANSCRIPT));
    }

    /** Plain text with `[m:ss]` stamps, for the editor's meta box. */
    public function toReadableText(): string
    {
        $out = [];
        foreach ($this->cues as $c) {
            $prefix = $c->start !== null ? '[' . Time::clock((int) floor($c->start)) . '] ' : '';
            $who = $c->speaker !== null && $c->speaker !== '' ? $c->speaker . ': ' : ($c->turn ? '>> ' : '');
            $out[] = $prefix . $who . $c->text;
        }
        return implode("\n", $out);
    }
}
