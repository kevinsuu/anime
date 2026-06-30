<?php

namespace Tests\Feature;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_authenticated_user_can_sync_and_filter_seasonal_anime(): void
    {
        Http::fake([
            'https://api.bgm.tv/v0/subjects*' => Http::response([
                'data' => [
                    [
                        'id' => 12345,
                        'name' => 'Witch Hat Atelier',
                        'name_cn' => '尖帽子的魔法工房',
                        'summary' => '少女可可展開魔法學徒生活。',
                        'air_date' => '2026-04-03',
                        'eps' => 12,
                        'images' => ['common' => 'https://example.com/witch-hat.jpg'],
                        'url' => 'http://bgm.tv/subject/12345',
                    ],
                ],
            ]),
        ]);

        $login = $this->postJson('/auth/google', ['idToken' => 'dev:dev@example.com']);
        $token = $login->json('token');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/anime/sync-seasonal', ['year' => 2026, 'season' => 'spring'])
            ->assertOk()
            ->assertJsonPath('result.provider', 'bangumi');

        $response
            ->assertJsonPath('result.imported', 1);

        $this->getJson('/anime?year=2026&season=spring')
            ->assertOk()
            ->assertJsonPath('items.0.name', '尖帽子的魔法工房')
            ->assertJsonPath('items.0.season_year', 2026)
            ->assertJsonPath('items.0.season_code', 'spring');
    }
}
