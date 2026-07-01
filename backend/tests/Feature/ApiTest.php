<?php

namespace Tests\Feature;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dev_google_login_issues_token_and_returns_user(): void
    {
        $response = $this->postJson('/auth/google', ['idToken' => 'dev:dev@example.com']);

        $response->assertOk()
            ->assertJsonPath('user.email', 'dev@example.com')
            ->assertJsonStructure(['token', 'user' => ['public_slug'], 'expiresIn']);
    }

    public function test_anime_search_returns_seeded_items(): void
    {
        $this->seed();

        $response = $this->getJson('/anime?q=芙莉蓮');

        $response->assertOk()
            ->assertJsonPath('items.0.name', '葬送的芙莉蓮');
    }

    public function test_authenticated_user_can_manage_anime_list(): void
    {
        $login = $this->postJson('/auth/google', ['idToken' => 'dev:dev@example.com']);
        $token = $login->json('token');
        $anime = Anime::query()->create([
            'name' => '尖帽子的魔法工房',
            'description' => '魔法工房簡介',
            'image_url' => 'https://example.com/anime.jpg',
            'source' => 'test',
        ]);

        $create = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/my/anime-list', ['animeId' => $anime->id]);

        $create->assertCreated()
            ->assertJsonPath('item.anime.name', '尖帽子的魔法工房')
            ->assertJsonPath('item.watched', false);

        $itemId = $create->json('item.id');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/my/anime-list/{$itemId}", ['watched' => true, 'rating' => 9, 'note' => '值得追'])
            ->assertOk()
            ->assertJsonPath('item.watched', true)
            ->assertJsonPath('item.rating', 9);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list')
            ->assertOk()
            ->assertJsonPath('items.0.anime.name', '尖帽子的魔法工房');
    }

    public function test_anime_index_returns_streams_aliases_and_titles(): void
    {
        app(\App\Services\AnimeCatalog\AnimeImportService::class)->importRecord([
            'season' => '202604', 'season_year' => 2026, 'season_code' => 'spring',
            'title_zh' => '測試動畫', 'title_ja' => 'テスト',
            'aliases' => ['別名A'], 'summary' => '介紹', 'cover_image' => 'https://x/y.jpg',
            'air_date_text' => '', 'air_date' => '2026-04-04', 'tags' => [],
            'streams' => [['region' => '台灣', 'platform' => '巴哈', 'url' => 'https://a']],
            'external_ids' => [],
        ]);

        $response = $this->getJson('/anime?year=2026&season=spring')->assertOk();
        $item = collect($response->json('items'))->firstWhere('name', '測試動畫');
        $this->assertNotNull($item);
        $this->assertSame('巴哈', $item['streams'][0]['platform']);
        $this->assertContains('別名A', $item['aliases']);
        $this->assertTrue(collect($item['titles'])->contains(fn ($t) => $t['locale'] === 'ja'));
    }
}
