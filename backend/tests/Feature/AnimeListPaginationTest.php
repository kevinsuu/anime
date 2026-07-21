<?php

namespace Tests\Feature;

use App\Models\Anime;
use App\Models\UserAnimeListItem;
use App\Models\UserCollection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnimeListPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_list_is_paginated_by_fifty_and_defaults_to_latest_air_date(): void
    {
        [$token, $userId] = $this->login();
        $start = CarbonImmutable::parse('2020-01-01');

        for ($index = 0; $index < 55; $index++) {
            $anime = Anime::query()->create([
                'name' => sprintf('作品 %02d', $index),
                'description' => '測試簡介',
                'image_url' => 'https://example.com/anime.jpg',
                'source' => 'test',
                'tags' => [],
                'air_date' => $start->addDays($index)->toDateString(),
            ]);
            UserAnimeListItem::query()->create([
                'user_id' => $userId,
                'anime_id' => $anime->id,
                'watched' => $index % 2 === 0,
            ]);
        }

        $firstPage = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list')
            ->assertOk()
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 55)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.has_more', true);

        $this->assertCount(50, $firstPage->json('items'));
        $this->assertSame('作品 54', $firstPage->json('items.0.anime.name'));

        $secondPage = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list?page=2')
            ->assertOk()
            ->assertJsonPath('meta.has_more', false);

        $this->assertCount(5, $secondPage->json('items'));
        $this->assertSame('作品 00', $secondPage->json('items.4.anime.name'));
    }

    public function test_counts_are_loaded_from_a_separate_endpoint_and_status_is_filtered_before_pagination(): void
    {
        [$token, $userId] = $this->login();

        for ($index = 0; $index < 60; $index++) {
            $anime = Anime::query()->create([
                'name' => "統計作品 {$index}",
                'description' => '測試簡介',
                'image_url' => 'https://example.com/anime.jpg',
                'source' => 'test',
                'tags' => [],
            ]);
            UserAnimeListItem::query()->create([
                'user_id' => $userId,
                'anime_id' => $anime->id,
                'watched' => $index < 35,
            ]);
        }

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list/counts')
            ->assertOk()
            ->assertExactJson([
                'counts' => [
                    'all' => 60,
                    'watched' => 35,
                    'unwatched' => 25,
                ],
            ]);

        $watched = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list?status=watched')
            ->assertOk()
            ->assertJsonPath('meta.total', 35);
        $this->assertCount(35, $watched->json('items'));
        $this->assertTrue(collect($watched->json('items'))->every(fn (array $item): bool => $item['watched']));

        $unwatched = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list?status=unwatched')
            ->assertOk()
            ->assertJsonPath('meta.total', 25);
        $this->assertTrue(collect($unwatched->json('items'))->every(fn (array $item): bool => ! $item['watched']));
    }

    public function test_rejects_invalid_pagination_status_and_sort_parameters(): void
    {
        [$token] = $this->login();

        foreach (['page=0', 'page=invalid', 'status=unknown', 'sort=unknown', 'collection_id=0'] as $query) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson("/my/anime-list?{$query}")
                ->assertUnprocessable();
        }
    }

    public function test_search_and_collection_filters_are_applied_before_pagination(): void
    {
        [$token, $userId] = $this->login();
        $targetAnime = Anime::query()->create([
            'name' => '目標作品',
            'description' => '測試簡介',
            'image_url' => 'https://example.com/target.jpg',
            'source' => 'test',
            'tags' => [],
        ]);
        $targetAnime->titles()->create([
            'locale' => 'ja',
            'title' => 'ターゲット作品',
            'is_primary' => true,
            'source' => 'test',
        ]);
        $targetItem = UserAnimeListItem::query()->create([
            'user_id' => $userId,
            'anime_id' => $targetAnime->id,
            'watched' => false,
        ]);

        $otherAnime = Anime::query()->create([
            'name' => '其他作品',
            'description' => '測試簡介',
            'image_url' => 'https://example.com/other.jpg',
            'source' => 'test',
            'tags' => [],
        ]);
        UserAnimeListItem::query()->create([
            'user_id' => $userId,
            'anime_id' => $otherAnime->id,
            'watched' => false,
        ]);

        $collection = UserCollection::query()->create([
            'user_id' => $userId,
            'name' => '精選收藏',
            'public_slug' => 'featured-list',
            'is_public' => false,
        ]);
        $collection->listItems()->attach($targetItem->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list?q=ターゲット')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('items.0.anime.name', '目標作品');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/my/anime-list?collection_id={$collection->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('items.0.anime.name', '目標作品');
    }

    /** @return array{string, int} */
    private function login(): array
    {
        $response = $this->postJson('/auth/google', ['idToken' => 'dev:list-pagination@example.com'])
            ->assertOk();

        return [(string) $response->json('token'), (int) $response->json('user.id')];
    }
}
