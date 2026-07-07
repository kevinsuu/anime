<?php

namespace Tests\Feature;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnimeListTagFilterTest extends TestCase
{
    use RefreshDatabase;

    private function loginToken(): string
    {
        $login = $this->postJson('/auth/google', ['idToken' => 'dev:dev@example.com']);

        return $login->json('token');
    }

    private function addAnimeToList(string $token, string $name, array $tags): void
    {
        $anime = Anime::query()->create([
            'name' => $name,
            'description' => '簡介',
            'image_url' => 'https://example.com/anime.jpg',
            'source' => 'test',
            'tags' => $tags,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/my/anime-list', ['animeId' => $anime->id])
            ->assertCreated();
    }

    public function test_filters_by_single_tag(): void
    {
        $token = $this->loginToken();
        $this->addAnimeToList($token, '戀愛作品', ['戀愛']);
        $this->addAnimeToList($token, '戰鬥作品', ['戰鬥']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list?tags=戀愛')
            ->assertOk();

        $names = collect($response->json('items'))->pluck('anime.name')->all();
        $this->assertSame(['戀愛作品'], $names);
    }

    public function test_filters_by_multiple_tags_with_or_semantics(): void
    {
        $token = $this->loginToken();
        $this->addAnimeToList($token, '戀愛作品', ['戀愛']);
        $this->addAnimeToList($token, '戰鬥作品', ['戰鬥']);
        $this->addAnimeToList($token, '搞笑作品', ['搞笑']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list?tags=戀愛,戰鬥')
            ->assertOk();

        $names = collect($response->json('items'))->pluck('anime.name')->sort()->values()->all();
        $this->assertSame(['戀愛作品', '戰鬥作品'], $names);
    }

    public function test_no_tags_param_returns_full_list(): void
    {
        $token = $this->loginToken();
        $this->addAnimeToList($token, '戀愛作品', ['戀愛']);
        $this->addAnimeToList($token, '戰鬥作品', ['戰鬥']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list')
            ->assertOk();

        $this->assertCount(2, $response->json('items'));
    }

    public function test_matches_anime_with_only_one_of_multiple_tags_present(): void
    {
        $token = $this->loginToken();
        $this->addAnimeToList($token, '戀愛戰鬥作品', ['戀愛', '戰鬥']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list?tags=戀愛,搞笑')
            ->assertOk();

        $names = collect($response->json('items'))->pluck('anime.name')->all();
        $this->assertSame(['戀愛戰鬥作品'], $names);
    }

    public function test_unknown_tag_returns_empty_list(): void
    {
        $token = $this->loginToken();
        $this->addAnimeToList($token, '戀愛作品', ['戀愛']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list?tags=不存在')
            ->assertOk();

        $this->assertSame([], $response->json('items'));
    }

    public function test_tag_filter_only_returns_current_users_items(): void
    {
        $tokenA = $this->loginToken();

        $loginB = $this->postJson('/auth/google', ['idToken' => 'dev:otheruser@example.com']);
        $tokenB = $loginB->json('token');

        $this->addAnimeToList($tokenA, '使用者A的戀愛作品', ['戀愛']);
        $this->addAnimeToList($tokenB, '使用者B的戀愛作品', ['戀愛']);

        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson('/my/anime-list?tags=戀愛')
            ->assertOk();

        $names = collect($response->json('items'))->pluck('anime.name')->all();
        $this->assertSame(['使用者A的戀愛作品'], $names);
    }
}
