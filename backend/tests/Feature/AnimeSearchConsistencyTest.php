<?php

namespace Tests\Feature;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnimeSearchConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function makeAnime(string $name, array $attributes = []): Anime
    {
        return Anime::query()->create(array_merge([
            'name' => $name,
            'description' => '簡介',
            'image_url' => 'https://example.com/anime.jpg',
            'source' => 'test',
            'tags' => [],
        ], $attributes));
    }

    public function test_index_and_summaries_share_filters_and_stable_ordering(): void
    {
        $first = $this->makeAnime('一致條件甲', [
            'season_year' => 2098,
            'season_code' => 'fall',
            'air_date' => '2098-10-01',
            'tags' => ['戀愛'],
        ]);
        $second = $this->makeAnime('一致條件乙', [
            'season_year' => 2098,
            'season_code' => 'fall',
            'air_date' => '2098-10-02',
            'tags' => ['戰鬥'],
        ]);
        $withoutDate = $this->makeAnime('一致條件無日期', [
            'season_year' => 2098,
            'season_code' => 'fall',
            'air_date' => null,
            'tags' => ['戀愛'],
        ]);
        $this->makeAnime('一致條件其他季度', [
            'season_year' => 2098,
            'season_code' => 'summer',
            'air_date' => '2098-07-01',
            'tags' => ['戀愛'],
        ]);
        $this->makeAnime('不符合關鍵字', [
            'season_year' => 2098,
            'season_code' => 'fall',
            'air_date' => '2098-10-03',
            'tags' => ['戀愛'],
        ]);

        $parameters = http_build_query([
            'q' => '一致條件',
            'year' => '2098',
            'season' => 'fall',
            'tags' => '戰鬥,戀愛',
        ]);

        $indexIds = collect($this->getJson("/anime?{$parameters}")->assertOk()->json('items'))
            ->pluck('id')
            ->all();
        $summaryIds = collect($this->getJson("/anime/summaries?{$parameters}&per_page=100")
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->json('items'))
            ->pluck('id')
            ->all();

        $this->assertSame([$first->id, $second->id, $withoutDate->id], $indexIds);
        $this->assertSame($indexIds, $summaryIds);
    }

    public function test_index_rejects_malformed_search_parameters(): void
    {
        foreach ([
            '/anime?q%5B%5D=x',
            '/anime?year%5B%5D=2098',
            '/anime?season%5B%5D=fall',
            '/anime?tags%5B%5D=戀愛',
            '/anime?year=1899',
            '/anime?season=monsoon',
        ] as $url) {
            $this->getJson($url)->assertStatus(422);
        }
    }

    public function test_index_preserves_existing_unpaginated_search_input_limits(): void
    {
        $this->getJson('/anime?q='.str_repeat('a', 101))->assertOk();
        $this->getJson('/anime?tags='.implode(',', array_map(
            fn (int $index): string => "分類{$index}",
            range(1, 11),
        )))->assertOk();
    }
}
