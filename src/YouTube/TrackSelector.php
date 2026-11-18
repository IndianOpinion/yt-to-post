<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\YouTube;

use IndianOpinion\YtToPost\Language;

/**
 * PRD case 26: drop unusable tracks, then rank the rest.
 */
final class TrackSelector
{
    /**
     * @param list<CaptionTrack> $tracks
     * @return list<CaptionTrack> usable tracks, best first
     */
    public static function rank(array $tracks, string $picked, string $audioLanguage, string $defaultLanguage): array
    {
        $usable = array_values(array_filter($tracks, static function (CaptionTrack $t): bool {
            return $t->id !== ''
                && strcasecmp($t->status, 'serving') === 0
                && !$t->isDraft
                && strcasecmp($t->trackKind, 'forced') !== 0
                && !in_array(strtolower($t->audioTrackType), ['commentary', 'descriptive'], true);
        }));
        $key = static function (CaptionTrack $t) use ($picked, $audioLanguage, $defaultLanguage): array {
            return [
                Language::matchRank($t->language, $picked),
                Language::matchRank($t->language, $audioLanguage),
                Language::matchRank($t->language, $defaultLanguage),
                $t->isAsr() ? 1 : 0,
                strcasecmp($t->audioTrackType, 'primary') === 0 ? 0 : 1,
            ];
        };
        usort($usable, static function (CaptionTrack $a, CaptionTrack $b) use ($key): int {
            $c = $key($a) <=> $key($b);
            if ($c !== 0) {
                return $c;
            }
            return strcmp($b->lastUpdated, $a->lastUpdated);
        });
        return $usable;
    }

    /**
     * The best track after the `yt_to_post_select_track` filter, or null.
     *
     * @param list<CaptionTrack> $tracks
     * @param list<string> $exclude track ids already tried
     */
    public static function choose(array $tracks, string $picked, string $audioLanguage, string $defaultLanguage, array $exclude = []): ?CaptionTrack
    {
        $ranked = array_values(array_filter(
            self::rank($tracks, $picked, $audioLanguage, $defaultLanguage),
            static fn (CaptionTrack $t): bool => !in_array($t->id, $exclude, true)
        ));
        $choice = $ranked[0] ?? null;
        if ($choice !== null && function_exists('apply_filters')) {
            $f = apply_filters('yt_to_post_select_track', $choice, $ranked);
            if ($f instanceof CaptionTrack && in_array($f, $ranked, true)) {
                $choice = $f;
            }
        }
        return $choice;
    }
}
