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

    public function test_recent_mode_orders_by_air_date_desc_with_null_last(): void
    {
        $this->makeAnime('舊番', ['air_date' => '2020-01-01']);
        $this->makeAnime('新番', ['air_date' => '2026-01-01']);
        $this->makeAnime('無日期', ['air_date' => null]);

        $names = collect($this->getJson('/anime')->assertOk()->json('items'))
            ->pluck('name')->all();

        $this->assertSame('新番', $names[0]);
        $this->assertSame('舊番', $names[1]);
        $this->assertSame('無日期', $names[2]); // null 排最後
    }

    public function test_recent_mode_caps_at_50(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->makeAnime("番{$i}", ['air_date' => sprintf('2026-01-%02d', ($i % 28) + 1)]);
        }

        $items = $this->getJson('/anime')->assertOk()->json('items');
        $this->assertCount(50, $items);
    }

    public function test_filters_by_tags_with_or_semantics(): void
    {
        $this->makeAnime('戀愛番', ['tags' => ['戀愛'], 'air_date' => '2026-01-01']);
        $this->makeAnime('戰鬥番', ['tags' => ['戰鬥'], 'air_date' => '2026-01-02']);
        $this->makeAnime('搞笑番', ['tags' => ['搞笑'], 'air_date' => '2026-01-03']);

        $names = collect($this->getJson('/anime?tags=戀愛,戰鬥')->assertOk()->json('items'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['戀愛番', '戰鬥番'], $names);
    }
}
