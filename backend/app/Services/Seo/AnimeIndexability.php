<?php

namespace App\Services\Seo;

/**
 * Mirrors frontend/app/utils/indexability.ts: a detail page only gets
 * `index, follow` (and a sitemap entry) when its synopsis can stand alone, or
 * when a shorter synopsis is backed by first-party facts the page shows.
 * Keep both sides in sync, or IndexNow will push pages the frontend marks
 * noindex.
 */
final class AnimeIndexability
{
    public const STANDALONE_DESCRIPTION_LENGTH = 160;

    public const SUPPORTED_DESCRIPTION_LENGTH = 60;

    public const MINIMUM_SUPPORTING_FACTS = 2;

    public const MINIMUM_CAST_FOR_FACT = 3;

    public static function isIndexable(
        ?string $description,
        ?int $episodeCount,
        int $streamCount,
        int $castCount,
    ): bool {
        $compact = preg_replace('/\s+/u', '', (string) $description) ?? '';
        $length = mb_strlen($compact);

        if ($length >= self::STANDALONE_DESCRIPTION_LENGTH) {
            return true;
        }
        if ($length < self::SUPPORTED_DESCRIPTION_LENGTH) {
            return false;
        }

        $supportingFacts = count(array_filter([
            $episodeCount !== null && $episodeCount > 0,
            $streamCount > 0,
            $castCount >= self::MINIMUM_CAST_FOR_FACT,
        ]));

        return $supportingFacts >= self::MINIMUM_SUPPORTING_FACTS;
    }
}
