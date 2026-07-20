<?php

namespace Tests\Feature;

use App\Models\Anime;
use App\Models\User;
use App\Models\UserAnimeListItem;
use App\Models\UserCollection;
use App\Services\Auth\JwtService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class MeBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_scoped_user_statuses_and_collections_in_stable_order(): void
    {
        $user = $this->makeUser('owner@example.com');
        $otherUser = $this->makeUser('other@example.com');
        $animeA = $this->makeAnime('作品 A');
        $animeB = $this->makeAnime('作品 B');
        $animeC = $this->makeAnime('作品 C');

        $laterCollection = $this->makeCollection($user, '稍後觀看');
        $favoriteCollection = $this->makeCollection($user, '最愛');
        $otherCollection = $this->makeCollection($otherUser, '其他人的清單');

        $itemA = $this->makeListItem($user, $animeA, true);
        $itemA->collections()->attach([$laterCollection->id, $favoriteCollection->id]);
        $itemC = $this->makeListItem($user, $animeC, false);
        $otherItem = $this->makeListItem($otherUser, $animeB, true);
        $otherItem->collections()->attach($otherCollection->id);

        $response = $this->authorizedAs($this->tokenFor($user))
            ->getJson("/me/bootstrap?anime_ids={$animeC->id},{$animeA->id},{$animeB->id},{$animeA->id}")
            ->assertOk();

        $this->assertCacheIsPrivateAndNotStored($response->headers->get('Cache-Control'));
        $response
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissing(['email' => $otherUser->email])
            ->assertJsonPath('statuses', [
                [
                    'anime_id' => $animeA->id,
                    'list_item_id' => $itemA->id,
                    'watched' => true,
                    'collection_ids' => collect([$laterCollection->id, $favoriteCollection->id])->sort()->values()->all(),
                ],
                [
                    'anime_id' => $animeC->id,
                    'list_item_id' => $itemC->id,
                    'watched' => false,
                    'collection_ids' => [],
                ],
            ]);

        $collectionIds = collect($response->json('collections'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing(
            [$laterCollection->id, $favoriteCollection->id],
            $collectionIds,
        );
        $this->assertNotContains($otherCollection->id, $collectionIds);
    }

    public function test_omitted_anime_ids_returns_empty_statuses(): void
    {
        $user = $this->makeUser('empty-statuses@example.com');
        $anime = $this->makeAnime('已收藏作品');
        $this->makeListItem($user, $anime, false);

        $this->authorizedAs($this->tokenFor($user))
            ->getJson('/me/bootstrap')
            ->assertOk()
            ->assertJsonPath('statuses', []);
    }

    public function test_rejects_invalid_or_more_than_one_hundred_unique_anime_ids(): void
    {
        $user = $this->makeUser('validation@example.com');
        $token = $this->tokenFor($user);
        $tooMany = implode(',', range(1, 101));

        foreach ([
            '/me/bootstrap?anime_ids=',
            '/me/bootstrap?anime_ids=1,,2',
            '/me/bootstrap?anime_ids=0',
            '/me/bootstrap?anime_ids=-1',
            '/me/bootstrap?anime_ids=1.5',
            '/me/bootstrap?anime_ids=invalid',
            '/me/bootstrap?anime_ids=999999999999999999999999',
            '/me/bootstrap?anime_ids%5B%5D=1',
            "/me/bootstrap?anime_ids={$tooMany}",
        ] as $url) {
            $response = $this->authorizedAs($token)->getJson($url);
            $this->assertSame(422, $response->getStatusCode(), $url);
            $response->assertJsonPath('code', 'validation_failed');
        }
    }

    public function test_rejects_missing_invalid_and_expired_tokens(): void
    {
        $this->getJson('/me/bootstrap')
            ->assertStatus(401)
            ->assertJsonPath('code', 'missing_token');

        $this->authorizedAs('invalid-token')
            ->getJson('/me/bootstrap')
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_token');

        $user = $this->makeUser('expired@example.com');
        config(['services.jwt.ttl_seconds' => -1]);

        $this->authorizedAs($this->tokenFor($user))
            ->getJson('/me/bootstrap')
            ->assertStatus(401)
            ->assertJsonPath('code', 'token_expired');
    }

    public function test_token_for_deleted_user_returns_not_found(): void
    {
        $user = $this->makeUser('deleted@example.com');
        $token = $this->tokenFor($user);
        $user->delete();

        $this->authorizedAs($token)
            ->getJson('/me/bootstrap')
            ->assertStatus(404)
            ->assertJsonPath('code', 'user_not_found');
    }

    public function test_one_hundred_ids_stays_below_query_and_gzip_budgets(): void
    {
        $user = $this->makeUser('budget@example.com');
        $collections = collect(range(1, 3))
            ->map(fn (int $index): UserCollection => $this->makeCollection($user, "清單 {$index}"));
        $animeIds = [];

        foreach (range(1, 100) as $index) {
            $anime = $this->makeAnime("效能作品 {$index}");
            $animeIds[] = $anime->id;
            $item = $this->makeListItem($user, $anime, $index % 2 === 0);
            $item->collections()->attach($collections[$index % $collections->count()]->id);
        }

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->authorizedAs($this->tokenFor($user))
            ->getJson('/me/bootstrap?anime_ids='.implode(',', array_reverse($animeIds)))
            ->assertOk()
            ->assertJsonCount(100, 'statuses');

        $this->assertLessThanOrEqual(4, count($queries));

        $compressed = gzencode((string) $response->getContent(), 9);
        $this->assertNotFalse($compressed);
        $this->assertLessThan(10 * 1024, strlen($compressed));
    }

    public function test_database_failure_returns_json_server_error(): void
    {
        $user = $this->makeUser('database-error@example.com');
        $token = $this->tokenFor($user);
        DB::connection()->beforeExecuting(function (): never {
            throw new RuntimeException('simulated database failure');
        });

        $this->authorizedAs($token)
            ->getJson('/me/bootstrap')
            ->assertStatus(500);
    }

    private function makeUser(string $email): User
    {
        return User::query()->create([
            'google_sub' => "test:{$email}",
            'email' => $email,
            'display_name' => $email,
            'avatar_url' => null,
            'public_slug' => 'user-'.sha1($email),
        ]);
    }

    private function makeAnime(string $name): Anime
    {
        return Anime::query()->create([
            'name' => $name,
            'description' => '測試簡介',
            'image_url' => 'https://example.com/anime.jpg',
            'source' => 'test',
            'tags' => [],
        ]);
    }

    private function makeListItem(User $user, Anime $anime, bool $watched): UserAnimeListItem
    {
        return UserAnimeListItem::query()->create([
            'user_id' => $user->id,
            'anime_id' => $anime->id,
            'watched' => $watched,
        ]);
    }

    private function makeCollection(User $user, string $name): UserCollection
    {
        return UserCollection::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'public_slug' => sha1("{$user->id}:{$name}"),
            'is_public' => false,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return app(JwtService::class)->issue([
            'sub' => (string) $user->id,
            'email' => $user->email,
        ]);
    }

    private function authorizedAs(string $token): static
    {
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function assertCacheIsPrivateAndNotStored(?string $cacheControl): void
    {
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }
}
