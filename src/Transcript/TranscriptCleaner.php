<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Transcript;

/**
 * Cleans parsed cues (PRD cases 44 and 45): tags, voice speakers, entities,
 * sound annotations, speaker-change marks, whitespace, then the rolling
 * caption de-duplication.
 */
final class TranscriptCleaner
{
    public function __construct(private readonly bool $keepAnnotations = false)
    {
    }

    /**
     * @param list<Cue> $cues
     * @return list<Cue>
     */
    public function clean(array $cues): array
    {
        $out = [];
        foreach ($cues as $cue) {
            foreach ($this->cleanOne($cue) as $c) {
                $out[] = $c;
            }
        }
        return $this->dedupe($out);
    }

    /**
     * @return list<Cue>
     */
    private function cleanOne(Cue $cue): array
    {
        $text = $cue->text;
        $speaker = $cue->speaker;
        // Inline timestamps such as <00:00:01.000>.
        $text = (string) preg_replace('/<\d{1,2}:\d{2}(?::\d{2})?[.,]\d{1,3}>/', '', $text);
        // Voice tag: <v Name> or <v.loud Name>.
        if (preg_match('/<v(?:\.[^\s>]+)?\s+([^>]+)>/u', $text, $m) === 1) {
            $speaker = trim($m[1]);
        }
        $text = (string) preg_replace('/<\/?[A-Za-z][^>]*>/', '', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!$this->keepAnnotations) {
            $text = (string) preg_replace('/\[[^\]\n]*\]/u', ' ', $text);
            $text = (string) preg_replace('/\((?:[^()\d\n]{1,30})\)/u', ' ', $text);
            $text = (string) preg_replace('/\x{266A}[^\x{266A}\n]*\x{266A}|[\x{266A}\x{266B}]+/u', ' ', $text);
        }
        // Speaker-change marks at the start of a line make a new turn.
        $pieces = preg_split('/(?:^|\n)\s*(?:>>|-\s)/u', $text, -1) ?: [$text];
        $hasMark = preg_match('/(?:^|\n)\s*(?:>>|-\s)/u', $text) === 1;
        $result = [];
        foreach ($pieces as $i => $piece) {
            $piece = trim((string) preg_replace('/\s+/u', ' ', $piece));
            if ($piece === '') {
                continue;
            }
            $turn = $cue->turn || ($hasMark && ($i > 0 || preg_match('/^\s*(?:>>|-\s)/u', $text) === 1));
            $result[] = new Cue($cue->start, $cue->end, $speaker === '' ? null : $speaker, $piece, $turn);
        }
        return $result;
    }

    /**
     * Removes the longest leading run of words that repeats the end of the
     * previous cue, and exact repeats within two seconds.
     *
     * @param list<Cue> $cues
     * @return list<Cue>
     */
    private function dedupe(array $cues): array
    {
        $out = [];
        $prev = null;
        foreach ($cues as $cue) {
            if ($prev !== null) {
                if (
                    self::norm($cue->text) === self::norm($prev->text)
                    && ($cue->start === null || $prev->start === null || abs($cue->start - $prev->start) <= 2.0)
                ) {
                    $out[count($out) - 1] = $prev = $prev->withEnd($cue->end ?? $prev->end);
                    continue;
                }
                $words = preg_split('/\s+/u', $cue->text) ?: [];
                $prevWords = array_map([self::class, 'norm'], preg_split('/\s+/u', $prev->text) ?: []);
                $curWords = array_map([self::class, 'norm'], $words);
                $max = min(count($prevWords), count($curWords));
                $k = 0;
                for ($n = $max; $n > 0; $n--) {
                    if (array_slice($prevWords, -$n) === array_slice($curWords, 0, $n)) {
                        $k = $n;
                        break;
                    }
                }
                if ($k > 0) {
                    $rest = implode(' ', array_slice($words, $k));
                    if ($rest === '') {
                        $out[count($out) - 1] = $prev = $prev->withEnd($cue->end ?? $prev->end);
                        continue;
                    }
                    $cue = $cue->withText($rest);
                }
            }
            $out[] = $cue;
            $prev = $cue;
        }
        return $out;
    }

    private static function norm(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }
}
