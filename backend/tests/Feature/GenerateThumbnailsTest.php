<?php

namespace Tests\Feature;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class GenerateThumbnailsTest extends TestCase
{
    use RefreshDatabase;

    private function fakeJpegBytes(): string
    {
        $img = new \Imagick;
        $img->newImage(2000, 3000, new \ImagickPixel('red'));
        $img->setImageFormat('jpeg');

        return $img->getImageBlob();
    }

    public function test_backfills_thumbnails_for_anime_missing_cover_image_path(): void
    {
        Storage::fake('public');
        Http::fake([
            'static.acgsecrets.hk/*' => Http::response($this->fakeJpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $needsThumbnail = Anime::create([
            'name' => '需要補縮圖',
            'image_url' => 'https://static.acgsecrets.hk/a.jpg',
            'cover_image_path' => null,
        ]);
        $alreadyHasThumbnail = Anime::create([
            'name' => '已有縮圖',
            'image_url' => 'https://static.acgsecrets.hk/b.jpg',
            'cover_image_path' => 'covers/existing.webp',
        ]);
        $noImageUrl = Anime::create([
            'name' => '沒有原圖網址',
            'image_url' => null,
            'cover_image_path' => null,
        ]);

        $this->artisan('anime:generate-thumbnails')->assertSuccessful();

        $this->assertNotNull($needsThumbnail->fresh()->cover_image_path);
        Storage::disk('public')->assertExists($needsThumbnail->fresh()->cover_image_path);

        // 已有縮圖的不應被覆蓋觸發重新下載
        $this->assertSame('covers/existing.webp', $alreadyHasThumbnail->fresh()->cover_image_path);

        $this->assertNull($noImageUrl->fresh()->cover_image_path);

        Http::assertSentCount(1);
    }

    public function test_can_scope_backfill_to_a_season(): void
    {
        Storage::fake('public');
        Http::fake([
            'static.acgsecrets.hk/*' => Http::response($this->fakeJpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $target = Anime::create([
            'name' => '目標季度',
            'image_url' => 'https://static.acgsecrets.hk/target.jpg',
            'season_year' => 2026,
            'season_code' => 'summer',
        ]);
        $otherSeason = Anime::create([
            'name' => '其他季度',
            'image_url' => 'https://static.acgsecrets.hk/other.jpg',
            'season_year' => 2026,
            'season_code' => 'spring',
        ]);

        $this->artisan('anime:generate-thumbnails', ['--year' => '2026', '--season' => 'summer'])
            ->assertSuccessful();

        $this->assertNotNull($target->fresh()->cover_image_path);
        $this->assertNull($otherSeason->fresh()->cover_image_path);
        Http::assertSentCount(1);
    }

    public function test_rejects_invalid_scope_options(): void
    {
        $this->artisan('anime:generate-thumbnails', ['--year' => '1899'])->assertFailed();
        $this->artisan('anime:generate-thumbnails', ['--season' => 'monsoon'])->assertFailed();
    }

    public function test_force_regenerates_existing_thumbnail_within_scope(): void
    {
        Storage::fake('public');
        Http::fake([
            'static.acgsecrets.hk/*' => Http::response($this->fakeJpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $anime = Anime::create([
            'name' => '重新編碼縮圖',
            'image_url' => 'https://static.acgsecrets.hk/force.jpg',
            'cover_image_path' => 'covers/old.webp',
            'season_year' => 2026,
            'season_code' => 'summer',
        ]);

        $this->artisan('anime:generate-thumbnails', [
            '--year' => '2026',
            '--season' => 'summer',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame("covers/{$anime->id}.webp", $anime->fresh()->cover_image_path);
        Storage::disk('public')->assertExists("covers/{$anime->id}.webp");
        Http::assertSentCount(1);
    }
}
