<?php

namespace App\Services\AnimeCatalog;

use App\Models\Anime;
use Illuminate\Database\Eloquent\Builder;

final class AnimeSearchQuery
{
    /** @return Builder<Anime> */
    public function build(AnimeSearchCriteria $criteria, ?bool $recentMode = null): Builder
    {
        $term = "%{$criteria->query}%";
        $recentMode ??= $criteria->isRecentMode();

        return Anime::query()
            ->when($criteria->query !== '', function (Builder $builder) use ($term): void {
                $builder->where(function (Builder $where) use ($term): void {
                    $where->where('name', 'like', $term)
                        ->orWhereHas('aliases', fn (Builder $aliasQuery) => $aliasQuery->where('alias', 'like', $term))
                        ->orWhereHas('titles', fn (Builder $titleQuery) => $titleQuery->where('title', 'like', $term));
                });
            })
            ->when($criteria->year !== null, fn (Builder $builder) => $builder->where('season_year', $criteria->year))
            ->when($criteria->season !== '', fn (Builder $builder) => $builder->where('season_code', $criteria->season))
            ->when($criteria->tags !== [], function (Builder $builder) use ($criteria): void {
                $builder->where(function (Builder $where) use ($criteria): void {
                    foreach ($criteria->tags as $tag) {
                        $where->orWhereJsonContains('tags', $tag);
                    }
                });
            })
            ->orderByRaw('air_date is null')
            ->when(
                $recentMode,
                fn (Builder $builder) => $builder->orderByDesc('air_date'),
                fn (Builder $builder) => $builder->orderBy('air_date'),
            )
            ->orderBy('name')
            ->orderBy('id');
    }
}
