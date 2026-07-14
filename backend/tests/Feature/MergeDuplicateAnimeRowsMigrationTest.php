<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MergeDuplicateAnimeRowsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_merges_orphan_and_preserves_user_list_data(): void
    {
        $now = now();
        $canonicalAnimeId = $this->insertAnime('天晴爛漫！', $now);
        $duplicateAnimeId = $this->insertAnime('天晴爛漫！', $now);

        DB::table('anime_external_ids')->insert([
            'anime_id' => $canonicalAnimeId,
            'provider' => 'bangumi',
            'external_id' => '292542',
            'url' => 'https://bgm.tv/subject/292542',
            'last_synced_at' => $now,
            'payload_hash' => str_repeat('a', 64),
        ]);

        $userId = DB::table('users')->insertGetId([
            'google_sub' => 'migration-test-user',
            'email' => 'migration@example.com',
            'display_name' => 'Migration Test',
            'avatar_url' => null,
            'public_slug' => 'migration-test-user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $canonicalListItemId = DB::table('user_anime_list_items')->insertGetId([
            'user_id' => $userId,
            'anime_id' => $canonicalAnimeId,
            'watched' => false,
            'rating' => null,
            'note' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $duplicateListItemId = DB::table('user_anime_list_items')->insertGetId([
            'user_id' => $userId,
            'anime_id' => $duplicateAnimeId,
            'watched' => true,
            'rating' => 5,
            'note' => '保留這筆備註',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $collectionId = DB::table('user_collections')->insertGetId([
            'user_id' => $userId,
            'name' => '2020 夏季',
            'public_slug' => null,
            'is_public' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('collection_items')->insert([
            'collection_id' => $collectionId,
            'list_item_id' => $duplicateListItemId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration = require database_path('migrations/2026_07_14_140000_merge_duplicate_anime_rows.php');
        $migration->up();

        $this->assertDatabaseHas('anime', ['id' => $canonicalAnimeId]);
        $this->assertDatabaseMissing('anime', ['id' => $duplicateAnimeId]);
        $this->assertDatabaseHas('user_anime_list_items', [
            'id' => $canonicalListItemId,
            'anime_id' => $canonicalAnimeId,
            'watched' => true,
            'rating' => 5,
            'note' => '保留這筆備註',
        ]);
        $this->assertDatabaseMissing('user_anime_list_items', ['id' => $duplicateListItemId]);
        $this->assertDatabaseHas('collection_items', [
            'collection_id' => $collectionId,
            'list_item_id' => $canonicalListItemId,
        ]);
    }

    private function insertAnime(string $name, mixed $now): int
    {
        return DB::table('anime')->insertGetId([
            'name' => $name,
            'description' => null,
            'image_url' => null,
            'cover_image_path' => null,
            'source' => 'acgsecrets',
            'created_by_user_id' => null,
            'season_year' => 2020,
            'season_code' => 'summer',
            'air_date' => null,
            'air_date_text' => '每週五／21時0分',
            'episode_count' => null,
            'status' => null,
            'tags' => null,
            'import_hash' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
