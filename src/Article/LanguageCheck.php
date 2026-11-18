<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Article;

use IndianOpinion\YtToPost\Language;

/**
 * PRD case 63: the reported language must match, and the letters in the
 * title and first section must mostly be in the expected script. Quotes
 * are never checked: mixed-language speech is normal there.
 */
final class LanguageCheck
{
    public const MAX_FOREIGN_SHARE = 0.4;

    public static function passes(Article $article, string $expected): bool
    {
        if ($expected === '') {
            return true;
        }
        if ($article->language === '' || Language::primary($article->language) !== Language::primary($expected)) {
            return false;
        }
        $sample = $article->title;
        if (isset($article->sections[0])) {
            $sample .= ' ' . $article->sections[0]['heading'] . ' ' . implode(' ', $article->sections[0]['paragraphs']);
        }
        $share = Language::foreignShare($sample, $expected);
        return $share === null || $share <= self::MAX_FOREIGN_SHARE;
    }
}
