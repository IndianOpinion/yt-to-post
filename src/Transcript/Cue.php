<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript;

/**
 * One unit of transcript text. Times are seconds, or null when the source
 * has none. A turn marks a speaker change with no known name.
 */
final class Cue
{
    public function __construct(
        public readonly ?float $start,
        public readonly ?float $end,
        public readonly ?string $speaker,
        public readonly string $text,
        public readonly bool $turn = false
    ) {
    }

    public function withText(string $text): self
    {
        return new self($this->start, $this->end, $this->speaker, $text, $this->turn);
    }

    public function withEnd(?float $end): self
    {
        return new self($this->start, $end, $this->speaker, $this->text, $this->turn);
    }

    /** @return array{s:?float,e:?float,p:?string,t:string,n?:int} */
    public function toArray(): array
    {
        $a = ['s' => $this->start, 'e' => $this->end, 'p' => $this->speaker, 't' => $this->text];
        if ($this->turn) {
            $a['n'] = 1;
        }
        return $a;
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            isset($a['s']) ? (float) $a['s'] : null,
            isset($a['e']) ? (float) $a['e'] : null,
            isset($a['p']) ? (string) $a['p'] : null,
            (string) ($a['t'] ?? ''),
            !empty($a['n'])
        );
    }
}
