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
}
