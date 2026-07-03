<?php

namespace App\Services\AnimeCatalog;

/**
 * Strict title matching for Bangumi search results. Only accepts a candidate
 * when, after normalizing punctuation/whitespace variance, it is character-for-
 * character identical to the query title — this is deliberately conservative:
 * false positives (wrong anime matched) corrupt data silently, whereas a false
 * negative just leaves episode_count null for manual follow-up.
 */
final class BangumiTitleMatcher
{
    /**
     * @param list<array{id: int, name: string, name_cn: string}> $candidates
     * @return array{id: int, name: string, name_cn: string}|null
     */
    public function matchExact(string $queryTitle, array $candidates): ?array
    {
        $normalizedQuery = $this->normalize($queryTitle);
        if ($normalizedQuery === '') {
            return null;
        }

        $matches = array_values(array_filter(
            $candidates,
            fn (array $c) => $this->normalize($c['name']) === $normalizedQuery
                || $this->normalize($c['name_cn']) === $normalizedQuery
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function normalize(string $title): string
    {
        $title = mb_convert_kana($title, 'as'); // full-width alnum/space -> half-width
        $title = str_replace(['～', '〜', '~'], '~', $title);
        $title = preg_replace('/\s+/u', '', $title) ?? $title;
        $title = preg_replace('/[・･]/u', '', $title) ?? $title;

        return trim($title);
    }
}
