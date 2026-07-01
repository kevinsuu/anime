<?php

namespace Tests\Unit;

use App\Models\Anime;
use App\Models\AnimeStream;
use App\Services\AnimeCatalog\AnimeImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
