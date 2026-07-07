<?php

namespace Tests\Feature;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnimeCatalogRecentTest extends TestCase
{
    use RefreshDatabase;

    private function makeAnime(string $name, array $attrs = []): Anime
    {
        return Anime::query()->create(array_merge([
            'name' => $name,
            'description' => '簡介',
            'image_url' => 'https://example.com/a.jpg',
            'source' => 'test',
            'tags' => [],
        ], $attrs));
    }

    public function test_tags_endpoint_returns_genre_tags_with_counts_excluding_source_tags(): void
    {
        $this->makeAnime('A', ['tags' => ['戀愛', '漫畫改編']]);
        $this->makeAnime('B', ['tags' => ['戀愛', '戰鬥']]);
        $this->makeAnime('C', ['tags' => ['原創作品']]); // source tag only

        $response = $this->getJson('/anime/tags')->assertOk();

        $tags = collect($response->json('tags'));
        // 排除 source tag（漫畫改編/原創作品）
        $this->assertFalse($tags->contains('tag', '漫畫改編'));
        $this->assertFalse($tags->contains('tag', '原創作品'));
        // 戀愛出現 2 次，排最前
        $this->assertSame('戀愛', $tags->first()['tag']);
        $this->assertSame(2, $tags->first()['count']);
    }
}
