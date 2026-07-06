<?php

namespace Tests\Feature;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnimeControllerImageUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_thumbnail_url_when_cover_image_path_present(): void
    {
        Anime::create([
            'name' => '測試動畫',
            'image_url' => 'https://static.acgsecrets.hk/original.jpg',
            'cover_image_path' => 'covers/999.webp',
            'season_year' => 2026,
            'season_code' => 'spring',
        ]);

        $response = $this->getJson('/anime?year=2026&season=spring');

        $response->assertSuccessful();
        $this->assertSame(
            rtrim((string) config('app.url'), '/') . '/storage/covers/999.webp',
            $response->json('items.0.image_url'),
        );
    }
}
