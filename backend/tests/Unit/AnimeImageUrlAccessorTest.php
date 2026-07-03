<?php

namespace Tests\Unit;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnimeImageUrlAccessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_image_url_falls_back_to_original_when_no_cover_path(): void
    {
        $anime = Anime::create([
            'name' => '測試動畫',
            'image_url' => 'https://static.acgsecrets.hk/original.jpg',
            'cover_image_path' => null,
        ]);

        $this->assertSame('https://static.acgsecrets.hk/original.jpg', $anime->image_url);
    }

    public function test_image_url_prefers_cover_image_path_when_present(): void
    {
        $anime = Anime::create([
            'name' => '測試動畫',
            'image_url' => 'https://static.acgsecrets.hk/original.jpg',
            'cover_image_path' => 'covers/123.webp',
        ]);

        $this->assertSame(
            rtrim((string) config('app.url'), '/') . '/storage/covers/123.webp',
            $anime->image_url,
        );
    }
}
