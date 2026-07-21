<?php

namespace Tests\Unit;

use App\Models\Anime;
use App\Models\AnimeStream;
use App\Services\AnimeCatalog\AnimeImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AnimeImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function record(array $overrides = []): array
    {
        return array_merge([
            'season' => '202604', 'season_year' => 2026, 'season_code' => 'spring',
            'title_zh' => '黃泉雙使', 'title_ja' => '黄泉のツガイ',
            'aliases' => ['黃泉的使者', 'Yomi no Tsugai'],
            'summary' => '大綱內容', 'cover_image' => 'https://static.acgsecrets.hk/x.jpg',
            'air_date_text' => '4月4日起', 'air_date' => '2026-04-04', 'tags' => ['動作', '奇幻'],
            'streams' => [['region' => '台灣', 'platform' => '巴哈姆特動畫瘋', 'url' => 'https://ani.gamer']],
            'external_ids' => ['mal' => '12345', 'bangumi' => '377130'],
        ], $overrides);
    }

    public function test_import_record_creates_anime_with_all_relations(): void
    {
        $service = app(AnimeImportService::class);

        $outcome = $service->importRecord($this->record());
        $anime = $outcome->anime;

        $this->assertFalse($outcome->wasUnchanged);
        $this->assertSame('黃泉雙使', $anime->name);
        $this->assertSame('大綱內容', $anime->description);
        $this->assertSame('https://static.acgsecrets.hk/x.jpg', $anime->image_url);
        $this->assertSame('acgsecrets', $anime->source);
        $this->assertSame(2026, $anime->season_year);
        $this->assertSame('spring', $anime->season_code);
        $this->assertSame('2026-04-04', (string) $anime->air_date);

        $jaTitle = $anime->titles()->where('locale', 'ja')->first();
        $this->assertNotNull($jaTitle);
        $this->assertSame('黄泉のツガイ', $jaTitle->title);
        $this->assertFalse($jaTitle->is_primary);

        $primary = $anime->titles()->where('is_primary', true)->first();
        $this->assertNotNull($primary);
        $this->assertSame('zh-Hant', $primary->locale);
        $this->assertSame('黃泉雙使', $primary->title);

        $this->assertSame(2, $anime->aliases()->count());

        $mal = $anime->externalIds()->where('provider', 'mal')->first();
        $this->assertNotNull($mal);
        $this->assertSame('12345', $mal->external_id);
        $this->assertSame('https://myanimelist.net/anime/12345', $mal->url);

        $bangumi = $anime->externalIds()->where('provider', 'bangumi')->first();
        $this->assertNotNull($bangumi);
        $this->assertSame('https://bgm.tv/subject/377130', $bangumi->url);

        $stream = $anime->streams()->first();
        $this->assertNotNull($stream);
        $this->assertSame('巴哈姆特動畫瘋', $stream->platform);
        $this->assertSame('台灣', $stream->region);
    }

    public function test_import_record_upserts_by_external_id(): void
    {
        $service = app(AnimeImportService::class);

        $service->importRecord($this->record());
        $outcome = $service->importRecord($this->record(['summary' => '更新後的大綱']));

        $this->assertFalse($outcome->wasUnchanged);
        $this->assertSame(1, Anime::count());
        $this->assertSame('更新後的大綱', Anime::first()->description);
        $this->assertSame(1, AnimeStream::count());
    }

    public function test_import_record_merges_orphan_created_before_external_id_enrichment(): void
    {
        $service = app(AnimeImportService::class);
        $springRecord = $this->record([
            'season' => '202004',
            'season_year' => 2020,
            'season_code' => 'spring',
            'title_zh' => '天晴爛漫！',
            'title_ja' => '天晴爛漫！',
            'cover_image' => null,
            'air_date' => '2020-04-10',
            'external_ids' => [],
        ]);
        $summerRecord = array_merge($springRecord, [
            'season' => '202007',
            'season_code' => 'summer',
            'air_date' => null,
            'air_date_text' => '每週五／21時0分',
        ]);

        $canonicalId = $service->importRecord($springRecord)->anime->id;
        $duplicateId = $service->importRecord($summerRecord)->anime->id;

        $now = now();
        $userId = DB::table('users')->insertGetId([
            'google_sub' => 'duplicate-test-user',
            'email' => 'duplicate@example.com',
            'display_name' => 'Duplicate Test',
            'avatar_url' => null,
            'public_slug' => 'duplicate-test-user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $canonicalListItemId = DB::table('user_anime_list_items')->insertGetId([
            'user_id' => $userId,
            'anime_id' => $canonicalId,
            'watched' => false,
            'rating' => null,
            'note' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $duplicateListItemId = DB::table('user_anime_list_items')->insertGetId([
            'user_id' => $userId,
            'anime_id' => $duplicateId,
            'watched' => true,
            'rating' => 5,
            'note' => '延期後資料',
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

        $springRecord['external_ids'] = ['bangumi' => '292542'];
        $summerRecord['external_ids'] = ['bangumi' => '292542'];
        $service->importRecord($springRecord);
        $service->importRecord($summerRecord);

        $this->assertSame(1, Anime::count());
        $this->assertDatabaseMissing('anime', ['id' => $duplicateId]);
        $this->assertDatabaseHas('anime', [
            'id' => $canonicalId,
            'season_year' => 2020,
            'season_code' => 'summer',
        ]);
        $this->assertDatabaseHas('anime_external_ids', [
            'anime_id' => $canonicalId,
            'provider' => 'bangumi',
            'external_id' => '292542',
        ]);
        $this->assertDatabaseHas('user_anime_list_items', [
            'id' => $canonicalListItemId,
            'anime_id' => $canonicalId,
            'watched' => true,
            'rating' => 5,
            'note' => '延期後資料',
        ]);
        $this->assertDatabaseMissing('user_anime_list_items', ['id' => $duplicateListItemId]);
        $this->assertDatabaseHas('collection_items', [
            'collection_id' => $collectionId,
            'list_item_id' => $canonicalListItemId,
        ]);
    }

    public function test_import_record_skips_unchanged_payload(): void
    {
        $service = app(AnimeImportService::class);

        $service->importRecord($this->record());
        $firstUpdatedAt = Anime::first()->updated_at;

        $this->travel(1)->hours();
        $outcome = $service->importRecord($this->record());

        $this->assertTrue($outcome->wasUnchanged);
        $this->assertSame(1, Anime::count());
        $this->assertEquals($firstUpdatedAt, Anime::first()->updated_at);
    }

    public function test_import_record_generates_cover_thumbnail(): void
    {
        Storage::fake('public');
        Http::fake([
            'static.acgsecrets.hk/*' => Http::response($this->fakeJpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $service = app(AnimeImportService::class);
        $outcome = $service->importRecord($this->record());

        $this->assertNotNull($outcome->anime->cover_image_path);
        Storage::disk('public')->assertExists($outcome->anime->cover_image_path);
    }

    public function test_import_record_leaves_cover_image_path_null_when_thumbnail_generation_fails(): void
    {
        Storage::fake('public');
        Http::fake([
            'static.acgsecrets.hk/*' => Http::response('not found', 404),
        ]);

        $service = app(AnimeImportService::class);
        $outcome = $service->importRecord($this->record());

        $this->assertFalse($outcome->wasUnchanged);
        $this->assertNull($outcome->anime->cover_image_path);
        $this->assertSame('https://static.acgsecrets.hk/x.jpg', $outcome->anime->getRawOriginal('image_url'));
    }

    private function fakeJpegBytes(): string
    {
        $img = new \Imagick();
        $img->newImage(2000, 3000, new \ImagickPixel('red'));
        $img->setImageFormat('jpeg');

        return $img->getImageBlob();
    }
}
