<?php

namespace Tests\Feature;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnimeListContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_responses_include_titles_aliases_and_cover_image_url(): void
    {
        $login = $this->postJson('/auth/google', ['idToken' => 'dev:list-contract@example.com']);
        $token = $login->json('token');
        $anime = Anime::query()->create([
            'name' => '測試動畫',
            'description' => '測試簡介',
            'image_url' => 'https://example.com/original.jpg',
            'cover_image_path' => 'anime-covers/test.webp',
            'source' => 'test',
            'tags' => ['奇幻'],
            'season_year' => 2026,
            'air_date' => '2026-07-01',
        ]);
        $anime->titles()->createMany([
            ['locale' => 'zh-Hant', 'title' => '測試動畫', 'is_primary' => true, 'source' => 'test'],
            ['locale' => 'ja', 'title' => 'テストアニメ', 'is_primary' => true, 'source' => 'test'],
        ]);
        $anime->aliases()->createMany([
            ['alias' => '測試別名'],
            ['alias' => 'Test Anime'],
        ]);

        $created = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/my/anime-list', ['animeId' => $anime->id])
            ->assertCreated();

        $this->assertStringEndsWith('/storage/anime-covers/test.webp', $created->json('item.anime.imageUrl'));
        $this->assertEqualsCanonicalizing(['測試別名', 'Test Anime'], $created->json('item.anime.aliases'));
        $this->assertTrue(collect($created->json('item.anime.titles'))->contains(fn (array $title): bool => $title === [
            'locale' => 'ja',
            'title' => 'テストアニメ',
            'is_primary' => true,
        ]));

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list')
            ->assertOk();

        $this->assertStringEndsWith('/storage/anime-covers/test.webp', $response->json('items.0.anime.imageUrl'));
        $this->assertEqualsCanonicalizing(['測試別名', 'Test Anime'], $response->json('items.0.anime.aliases'));
        $titles = collect($response->json('items.0.anime.titles'));
        $this->assertTrue($titles->contains(fn (array $title): bool => $title === [
            'locale' => 'zh-Hant',
            'title' => '測試動畫',
            'is_primary' => true,
        ]));
        $this->assertTrue($titles->contains(fn (array $title): bool => $title === [
            'locale' => 'ja',
            'title' => 'テストアニメ',
            'is_primary' => true,
        ]));
    }
}
