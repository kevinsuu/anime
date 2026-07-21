<?php

namespace App\Services\Shared;

final class GenreTagStatistics
{
    /**
     * @param  iterable<iterable<mixed>|null>  $tagSets
     * @return array<int, array{tag: string, count: int}>
     */
    public function summarize(iterable $tagSets): array
    {
        $counts = [];

        foreach ($tagSets as $tags) {
            if (! is_iterable($tags)) {
                continue;
            }

            foreach ($tags as $tag) {
                if (! is_string($tag) || ! GenreTags::isGenreTag($tag)) {
                    continue;
                }

                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        return collect($counts)
            ->map(fn (int $count, string $tag): array => ['tag' => $tag, 'count' => $count])
            ->values()
            ->sortBy([['count', 'desc'], ['tag', 'asc']])
            ->values()
            ->all();
    }
}
