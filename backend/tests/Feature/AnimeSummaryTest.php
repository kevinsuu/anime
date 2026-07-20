<?php

namespace Tests\Feature;

use App\Models\Anime;
use DateTimeImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AnimeSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function makeAnime(string $name, array $attributes = []): Anime
    {
        return Anime::query()->create(array_merge([
            'name' => $name,
            'description' => '列表不需要的作品介紹',
            'image_url' => 'https://example.com/cover.webp',
            'source' => 'test',
            'air_date_text' => '7月1日起／每週三／20時00分',
            'tags' => [],
        ], $attributes));
    }

    public function test_returns_only_card_summary_fields_with_stream_count_and_unique_actors(): void
    {
        $anime = $this->makeAnime('摘要作品', [
            'season_year' => 2026,
            'season_code' => 'summer',
            'air_date' => '2026-07-01',
            'episode_count' => 12,
            'tags' => ['奇幻', '冒險'],
        ]);
        $anime->streams()->createMany([
            ['region' => 'TW', 'platform' => '平台一', 'url' => 'https://example.com/1'],
            ['region' => 'JP', 'platform' => '平台二', 'url' => null],
        ]);
        $anime->cast()->createMany([
            ['character' => '角色一', 'actor' => '聲優甲', 'sort_order' => 0],
            ['character' => '角色二', 'actor' => '聲優甲', 'sort_order' => 1],
            ['character' => '角色三', 'actor' => '聲優乙', 'sort_order' => 2],
            ['character' => '未定', 'actor' => '？？？', 'sort_order' => 3],
        ]);

        $response = $this->getJson('/anime/summaries?year=2026&season=summer&per_page=100')
            ->assertOk();

        $this->assertSame([
            'id' => $anime->id,
            'name' => '摘要作品',
            'image_url' => 'https://example.com/cover.webp',
            'season_year' => 2026,
            'season_code' => 'summer',
            'air_date' => '2026-07-01',
            'air_date_text' => '7月1日起／每週三／20時00分',
            'episode_count' => 12,
            'tags' => ['奇幻', '冒險'],
            'stream_count' => 2,
            'actors' => ['聲優甲', '聲優乙'],
        ], $response->json('items.0'));
        $response->assertJsonPath('meta', [
            'page' => 1,
            'per_page' => 100,
            'total' => 1,
            'last_page' => 1,
            'has_more' => false,
        ]);
    }

    public function test_searches_names_aliases_and_titles_without_exposing_search_relations(): void
    {
        $aliasMatch = $this->makeAnime('正式名稱甲', ['air_date' => '2026-01-01']);
        $aliasMatch->aliases()->create(['alias' => '別名命中']);

        $titleMatch = $this->makeAnime('正式名稱乙', ['air_date' => '2026-01-02']);
        $titleMatch->titles()->create([
            'locale' => 'ja',
            'title' => '日文標題命中',
            'is_primary' => true,
            'source' => 'test',
        ]);

        $aliasItem = $this->getJson('/anime/summaries?q='.rawurlencode('別名命中'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->json('items.0');
        $titleItem = $this->getJson('/anime/summaries?q='.rawurlencode('日文標題命中'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->json('items.0');

        $this->assertSame($aliasMatch->id, $aliasItem['id']);
        $this->assertSame($titleMatch->id, $titleItem['id']);
        foreach (['description', 'aliases', 'titles', 'streams', 'cast'] as $key) {
            $this->assertArrayNotHasKey($key, $aliasItem);
            $this->assertArrayNotHasKey($key, $titleItem);
        }
    }

    public function test_filters_by_year_season_and_tags_with_or_semantics(): void
    {
        $romance = $this->makeAnime('戀愛作品', [
            'season_year' => 2025,
            'season_code' => 'fall',
            'air_date' => '2025-10-01',
            'tags' => ['戀愛'],
        ]);
        $action = $this->makeAnime('戰鬥作品', [
            'season_year' => 2025,
            'season_code' => 'fall',
            'air_date' => '2025-10-02',
            'tags' => ['戰鬥'],
        ]);
        $this->makeAnime('其他季度', [
            'season_year' => 2025,
            'season_code' => 'summer',
            'air_date' => '2025-07-01',
            'tags' => ['戀愛'],
        ]);

        $response = $this->getJson('/anime/summaries?year=2025&season=fall&tags='.rawurlencode('戀愛,戰鬥'))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->assertSame(
            [$romance->id, $action->id],
            collect($response->json('items'))->pluck('id')->all(),
        );
    }

    public function test_paginates_without_duplicates_or_omissions(): void
    {
        for ($index = 1; $index <= 101; $index++) {
            $this->makeAnime(sprintf('作品%03d', $index), [
                'season_year' => 2024,
                'season_code' => 'winter',
                'air_date' => '2024-01-01',
            ]);
        }

        $firstPage = $this->getJson('/anime/summaries?year=2024&season=winter&page=1&per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 101)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.has_more', true);
        $secondPage = $this->getJson('/anime/summaries?year=2024&season=winter&page=2&per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 101)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.has_more', false);

        $firstIds = collect($firstPage->json('items'))->pluck('id')->all();
        $secondIds = collect($secondPage->json('items'))->pluck('id')->all();
        $allIds = [...$firstIds, ...$secondIds];

        $this->assertCount(100, $firstIds);
        $this->assertCount(1, $secondIds);
        $this->assertCount(101, array_unique($allIds));
    }

    public function test_unfiltered_recent_mode_caps_total_at_fifty_and_orders_newest_first(): void
    {
        $start = new DateTimeImmutable('2020-01-01');
        for ($index = 1; $index <= 55; $index++) {
            $this->makeAnime(sprintf('近期作品%02d', $index), [
                'air_date' => $start->modify("+{$index} days")->format('Y-m-d'),
            ]);
        }

        $firstPage = $this->getJson('/anime/summaries')
            ->assertOk()
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 40)
            ->assertJsonPath('meta.total', 50)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonCount(40, 'items');
        $secondPage = $this->getJson('/anime/summaries?page=2')
            ->assertOk()
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.per_page', 40)
            ->assertJsonPath('meta.total', 50)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonCount(10, 'items');

        $this->assertSame('近期作品55', $firstPage->json('items.0.name'));
        $this->assertSame('近期作品06', $secondPage->json('items.9.name'));
    }

    public function test_validates_page_per_page_year_and_season(): void
    {
        foreach ([
            '/anime/summaries?page=0',
            '/anime/summaries?page=-1',
            '/anime/summaries?per_page=0',
            '/anime/summaries?per_page=101',
            '/anime/summaries?per_page=invalid',
            '/anime/summaries?year=1899',
            '/anime/summaries?season=monsoon',
            '/anime/summaries?q%5B%5D=x',
            '/anime/summaries?page%5B%5D=1',
            '/anime/summaries?tags%5B%5D=奇幻',
            '/anime/summaries?q='.str_repeat('a', 101),
            '/anime/summaries?tags='.implode(',', array_map(fn (int $index): string => "分類{$index}", range(1, 11))),
            '/anime/summaries?tags='.str_repeat('分類', 26),
        ] as $url) {
            $this->getJson($url)->assertStatus(422);
        }
    }

    public function test_empty_and_out_of_range_pages_return_empty_items(): void
    {
        $this->getJson('/anime/summaries?q=不存在的作品')
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.last_page', 1);

        $this->makeAnime('唯一作品', ['season_year' => 2032]);
        $this->getJson('/anime/summaries?year=2032&page=2')
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_canonical_cache_key_reuses_equivalent_tag_queries(): void
    {
        $this->makeAnime('快取前作品', [
            'season_year' => 2030,
            'air_date' => '2030-01-01',
            'tags' => ['奇幻', '戰鬥'],
        ]);

        $this->getJson('/anime/summaries?year=2030&tags='.rawurlencode('奇幻,戰鬥'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->makeAnime('快取後作品', [
            'season_year' => 2030,
            'air_date' => '2030-01-02',
            'tags' => ['奇幻'],
        ]);

        $this->getJson('/anime/summaries?tags='.rawurlencode('戰鬥,奇幻').'&year=2030')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_distinct_parameters_do_not_share_cached_payloads(): void
    {
        $this->makeAnime('甲作品', ['season_year' => 2033]);
        $this->makeAnime('乙作品', ['season_year' => 2034]);

        $this->getJson('/anime/summaries?year=2033')
            ->assertOk()
            ->assertJsonPath('items.0.name', '甲作品');
        $this->getJson('/anime/summaries?year=2034')
            ->assertOk()
            ->assertJsonPath('items.0.name', '乙作品');
    }

    public function test_cold_summary_uses_at_most_three_queries_and_fresh_cache_uses_none(): void
    {
        $anime = $this->makeAnime('查詢數作品', [
            'season_year' => 2031,
            'season_code' => 'spring',
            'air_date' => '2031-04-01',
        ]);
        $anime->cast()->create([
            'character' => '角色',
            'actor' => '聲優',
            'sort_order' => 0,
        ]);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $url = '/anime/summaries?year=2031&season=spring';
        $this->getJson($url)->assertOk();
        $this->assertLessThanOrEqual(3, count($queries));

        $queries = [];
        $this->getJson($url)->assertOk();
        $this->assertCount(0, $queries);
    }

    public function test_tags_endpoint_uses_fresh_cache_without_database_queries(): void
    {
        $this->makeAnime('分類作品', ['tags' => ['戀愛']]);

        $this->getJson('/anime/tags')->assertOk()->assertJsonPath('tags.0.tag', '戀愛');

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson('/anime/tags')->assertOk()->assertJsonPath('tags.0.tag', '戀愛');
        $this->assertCount(0, $queries);
    }
}
