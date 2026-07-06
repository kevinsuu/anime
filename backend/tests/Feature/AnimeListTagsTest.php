<?php

namespace Tests\Feature;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnimeListTagsTest extends TestCase
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

    public function test_returns_deduped_counts_sorted_by_count_desc(): void
    {
        $token = $this->loginToken();
        $this->addAnimeToList($token, '作品A', ['戀愛', '戰鬥']);
        $this->addAnimeToList($token, '作品B', ['戀愛', '搞笑']);
        $this->addAnimeToList($token, '作品C', ['戰鬥']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list/tags')
            ->assertOk();

        $response->assertJsonPath('tags', [
            ['tag' => '戀愛', 'count' => 2],
            ['tag' => '戰鬥', 'count' => 2],
            ['tag' => '搞笑', 'count' => 1],
        ]);
    }

    public function test_excludes_non_genre_tags(): void
    {
        $token = $this->loginToken();
        $this->addAnimeToList($token, '作品A', ['新作', '漫畫改編', '2季度', '戀愛']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list/tags')
            ->assertOk();

        $response->assertJsonPath('tags', [
            ['tag' => '戀愛', 'count' => 1],
        ]);
    }

    public function test_returns_empty_array_when_list_is_empty(): void
    {
        $token = $this->loginToken();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list/tags')
            ->assertOk()
            ->assertJsonPath('tags', []);
    }
}
