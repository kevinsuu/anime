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
        $img = new \Imagick();
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
}
