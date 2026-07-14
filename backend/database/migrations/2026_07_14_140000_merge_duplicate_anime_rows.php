<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $groups = DB::table('anime')
                ->select(['season_year', 'season_code', 'name'])
                ->whereNotNull('season_year')
                ->whereNotNull('season_code')
                ->groupBy('season_year', 'season_code', 'name')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($groups as $group) {
                $rows = DB::table('anime')
                    ->where('season_year', $group->season_year)
                    ->where('season_code', $group->season_code)
                    ->where('name', $group->name)
                    ->orderBy('id')
                    ->get();

                $canonical = $rows->first(
                    fn (object $row): bool => $this->hasExternalId($row->id),
                );

                if ($canonical === null || $canonical->source !== 'acgsecrets') {
                    continue;
                }

                foreach ($rows as $row) {
                    if (
                        $row->id === $canonical->id
                        || $row->source !== 'acgsecrets'
                        || $row->created_by_user_id !== null
                        || $this->hasExternalId($row->id)
                    ) {
                        continue;
                    }

                    $this->mergeUserListItems($canonical->id, $row->id);
                    DB::table('anime')->where('id', $row->id)->delete();
                }
            }
        });
    }

    public function down(): void
    {
        // Duplicate rows are merged into the canonical record and cannot be
        // reconstructed reliably without reintroducing conflicting user data.
    }

    private function hasExternalId(int $animeId): bool
    {
        return DB::table('anime_external_ids')->where('anime_id', $animeId)->exists();
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
};
