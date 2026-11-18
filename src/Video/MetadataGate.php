<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Video;

use IndianOpinion\YtToPost\Exception\InvalidVideoException;

/**
 * Applies PRD cases 9 to 15 to fetched metadata: refuses what cannot be
 * used and returns warnings for what only needs a note.
 */
final class MetadataGate
{
    /**
     * @param array{own:bool,allow_private_own:bool,allow_unlisted:bool,override_limits:bool,max_duration_minutes:int,site_country:string} $ctx
     * @return list<string> warnings
     * @throws InvalidVideoException
     */
    public static function check(VideoMeta $meta, array $ctx, ?callable $estimate = null): array
    {
        $warnings = [];
        if (in_array($meta->liveBroadcastContent, ['live', 'upcoming'], true)) {
            throw new InvalidVideoException('Wait until the broadcast has ended');
        }
        if ($meta->uploadStatus !== '' && $meta->uploadStatus !== 'processed') {
            throw new InvalidVideoException('Video is still processing on YouTube, try again later');
        }
        if ($meta->privacyStatus === 'private') {
            if (!($ctx['own'] && $ctx['allow_private_own'])) {
                throw new InvalidVideoException(
                    'Video is private: readers could not play the embed. Only your own channel\'s private videos can be used, with the setting on.'
                );
            }
            $warnings[] = 'Video is private on YouTube: readers cannot play it until it is public.';
        }
        if ($meta->privacyStatus === 'unlisted') {
            if (!$ctx['own'] && !$ctx['allow_unlisted']) {
                throw new InvalidVideoException('Video is unlisted. Tick "I know this video is unlisted" to continue.');
            }
            $warnings[] = 'Video is unlisted on YouTube.';
        }
        if (!$meta->embeddable) {
            $warnings[] = 'The video owner does not allow embedding: the draft links to YouTube instead of embedding.';
        }
        if ($meta->ageRestricted) {
            $warnings[] = 'Video is age-restricted on YouTube.';
        }
        if ($meta->madeForKids) {
            $warnings[] = 'Video is marked as made for kids.';
        }
        $country = strtoupper($ctx['site_country']);
        if ($country !== '') {
            if ($meta->regionBlocked !== null && in_array($country, $meta->regionBlocked, true)) {
                $warnings[] = "Video is blocked in $country.";
            }
            if ($meta->regionAllowed !== null && !in_array($country, $meta->regionAllowed, true)) {
                $warnings[] = "Video is not available in $country.";
            }
        }
        $max = $ctx['max_duration_minutes'] * 60;
        if ($meta->duration !== null && $max > 0 && $meta->duration > $max && !$ctx['override_limits']) {
            $extra = $estimate !== null ? ' ' . (string) $estimate($meta->duration) : '';
            throw new InvalidVideoException(sprintf(
                'Video is %d minutes long, over the limit of %d minutes. Tick the override to continue.%s',
                (int) ceil($meta->duration / 60),
                $ctx['max_duration_minutes'],
                $extra
            ));
        }
        return $warnings;
    }
}
