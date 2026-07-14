<?php

namespace App\Services\AnimeCatalog;

use App\Models\Anime;
use Illuminate\Support\Facades\DB;

final class AnimeDuplicateMerger
{
    public function merge(Anime $canonical, Anime $duplicate): void
    {
        if ($canonical->is($duplicate)) {
            return;
        }

        $this->mergeUserListItems($canonical->id, $duplicate->id);
        $duplicate->delete();
    }

    private function mergeUserListItems(int $canonicalAnimeId, int $duplicateAnimeId): void
    {
        $duplicateItems = DB::table('user_anime_list_items')
            ->where('anime_id', $duplicateAnimeId)
            ->get();

        foreach ($duplicateItems as $duplicateItem) {
            $canonicalItem = DB::table('user_anime_list_items')
                ->where('user_id', $duplicateItem->user_id)
                ->where('anime_id', $canonicalAnimeId)
                ->first();

            if ($canonicalItem === null) {
                DB::table('user_anime_list_items')
                    ->where('id', $duplicateItem->id)
                    ->update(['anime_id' => $canonicalAnimeId]);

                continue;
            }

            $this->mergeCollectionItems($canonicalItem->id, $duplicateItem->id);

            DB::table('user_anime_list_items')
                ->where('id', $canonicalItem->id)
                ->update([
                    'watched' => (bool) $canonicalItem->watched || (bool) $duplicateItem->watched,
                    'rating' => $canonicalItem->rating ?? $duplicateItem->rating,
                    'note' => $this->preferredText($canonicalItem->note, $duplicateItem->note),
                    'updated_at' => max($canonicalItem->updated_at, $duplicateItem->updated_at),
                ]);

            DB::table('user_anime_list_items')->where('id', $duplicateItem->id)->delete();
        }
    }

    private function mergeCollectionItems(int $canonicalListItemId, int $duplicateListItemId): void
    {
        $collectionItems = DB::table('collection_items')
            ->where('list_item_id', $duplicateListItemId)
            ->get();

        foreach ($collectionItems as $collectionItem) {
            DB::table('collection_items')->insertOrIgnore([
                'collection_id' => $collectionItem->collection_id,
                'list_item_id' => $canonicalListItemId,
                'created_at' => $collectionItem->created_at,
                'updated_at' => $collectionItem->updated_at,
            ]);
        }
    }

    private function preferredText(?string $primary, ?string $fallback): ?string
    {
        return $primary !== null && trim($primary) !== '' ? $primary : $fallback;
    }
}
