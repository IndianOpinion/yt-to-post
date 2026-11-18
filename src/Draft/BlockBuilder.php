<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Draft;

use IndianOpinion\YtToPost\Article\Article;
use IndianOpinion\YtToPost\Transcript\Time;
use IndianOpinion\YtToPost\Video\VideoMeta;

/**
 * Builds the post content as block markup (PRD case 65). Every piece of
 * text is escaped for its context with the WordPress escaping functions.
 */
final class BlockBuilder
{
    public function __construct(
        private readonly bool $privacyEmbed = false,
        private readonly bool $withTimes = true
    ) {
    }

    public function build(Article $article, VideoMeta $meta, string $sourceKind, string $credit): string
    {
        $blocks = [];
        $blocks[] = $this->embed($meta);
        if ($article->standfirst !== '') {
            $blocks[] = self::paragraph(esc_html($article->standfirst));
        }
        foreach ($article->sections as $s) {
            if ($s['heading'] !== '') {
                $blocks[] = '<!-- wp:heading -->' . "\n" . '<h2 class="wp-block-heading">' . esc_html($s['heading']) . '</h2>' . "\n" . '<!-- /wp:heading -->';
            }
            foreach ($s['paragraphs'] as $p) {
                $blocks[] = self::paragraph(esc_html($p));
            }
            if ($this->withTimes && $s['start_seconds'] !== null) {
                $blocks[] = self::paragraph($this->timeLink($meta->id, $s['start_seconds'], 'Watch from ' . Time::clock($s['start_seconds'])));
            }
        }
        if ($article->keyPoints !== []) {
            $items = '';
            foreach ($article->keyPoints as $k) {
                $items .= '<!-- wp:list-item -->' . "\n" . '<li>' . esc_html($k) . '</li>' . "\n" . '<!-- /wp:list-item -->' . "\n";
            }
            $blocks[] = '<!-- wp:list -->' . "\n" . '<ul class="wp-block-list">' . "\n" . $items . '</ul>' . "\n" . '<!-- /wp:list -->';
        }
        foreach ($article->quotes as $q) {
            $inner = self::paragraph(esc_html($q['text']));
            if ($q['translation'] !== null && $q['translation'] !== '') {
                $inner .= "\n" . self::paragraph(esc_html($q['translation']));
            }
            $cite = [];
            if ($q['speaker'] !== null && $q['speaker'] !== '') {
                $cite[] = esc_html($q['speaker']);
            }
            if ($this->withTimes && $q['start_seconds'] !== null) {
                $cite[] = $this->timeLink($meta->id, $q['start_seconds'], Time::clock($q['start_seconds']));
            }
            $citeHtml = $cite === [] ? '' : '<cite>' . implode(', ', $cite) . '</cite>';
            $blocks[] = '<!-- wp:quote -->' . "\n" . '<blockquote class="wp-block-quote">' . "\n" . $inner . $citeHtml . '</blockquote>' . "\n" . '<!-- /wp:quote -->';
        }
        $blocks[] = $credit;
        return implode("\n\n", $blocks);
    }

    public static function paragraph(string $html): string
    {
        return '<!-- wp:paragraph -->' . "\n" . '<p>' . $html . '</p>' . "\n" . '<!-- /wp:paragraph -->';
    }

    public static function watchUrl(string $id, ?int $t = null): string
    {
        return 'https://www.youtube.com/watch?v=' . $id . ($t !== null ? '&t=' . $t . 's' : '');
    }

    private function timeLink(string $id, int $seconds, string $label): string
    {
        return '<a href="' . esc_url(self::watchUrl($id, $seconds)) . '">' . esc_html($label) . '</a>';
    }

    private function embed(VideoMeta $meta): string
    {
        $url = self::watchUrl($meta->id);
        if (!$meta->embeddable) {
            return self::paragraph('<a href="' . esc_url($url) . '">' . esc_html('Watch the video on YouTube') . '</a>');
        }
        if ($this->privacyEmbed) {
            $src = 'https://www.youtube-nocookie.com/embed/' . $meta->id;
            $iframe = '<iframe width="560" height="315" src="' . esc_url($src) . '" title="' . esc_attr($meta->title)
                . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; encrypted-media; picture-in-picture; web-share" allowfullscreen></iframe>';
            return '<!-- wp:html -->' . "\n" . '<figure class="wp-block-embed is-type-video is-provider-youtube yt-to-post-privacy-embed">' . $iframe . '</figure>' . "\n" . '<!-- /wp:html -->';
        }
        $attrs = self::attrs([
            'url' => $url,
            'type' => 'video',
            'providerNameSlug' => 'youtube',
            'responsive' => true,
            'className' => 'wp-embed-aspect-16-9 wp-has-aspect-ratio',
        ]);
        return '<!-- wp:embed ' . $attrs . ' -->' . "\n"
            . '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">' . "\n"
            . esc_url($url) . "\n"
            . '</div></figure>' . "\n"
            . '<!-- /wp:embed -->';
    }

    /**
     * Block attribute JSON, escaped the way serialize_block_attributes() does.
     *
     * @param array<string,mixed> $a
     */
    public static function attrs(array $a): string
    {
        $json = (string) json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json = (string) preg_replace('/--/', '\\u002d\\u002d', $json);
        return strtr($json, ['<' => '\\u003c', '>' => '\\u003e', '&' => '\\u0026', '\\"' => '\\u0022']);
    }
}
