<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CollectionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_and_rename_reject_duplicate_names_but_allow_unchanged_name(): void
    {
        [$token] = $this->login('collections@example.com');

        $first = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/my/collections', ['name' => '必看'])
            ->assertCreated();
        $second = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/my/collections', ['name' => '待補'])
            ->assertCreated();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/my/collections', ['name' => ' 必看 '])
            ->assertStatus(409)
            ->assertJsonPath('code', 'duplicate');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/my/collections/'.$second->json('item.id'), ['name' => '必看'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'duplicate');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/my/collections/'.$first->json('item.id'), ['name' => ' 必看 '])
            ->assertOk()
            ->assertJsonPath('item.name', '必看');
    }

    public function test_same_collection_name_is_scoped_to_user(): void
    {
        [$firstToken] = $this->login('first-collections@example.com');
        [$secondToken] = $this->login('second-collections@example.com');

        $this->withHeader('Authorization', "Bearer {$firstToken}")
            ->postJson('/my/collections', ['name' => '本季'])
            ->assertCreated();

        $this->withHeader('Authorization', "Bearer {$secondToken}")
            ->postJson('/my/collections', ['name' => '本季'])
            ->assertCreated();
    }

    public function test_database_unique_race_returns_duplicate_conflict(): void
    {
        [$token, $userId] = $this->login('collection-race@example.com');
        $injected = false;

        DB::listen(function (QueryExecuted $query) use (&$injected, $userId): void {
            if ($injected
                || ! str_contains(strtolower($query->sql), 'select exists')
                || ! in_array('競合清單', $query->bindings, true)) {
                return;
            }

            $injected = true;
            DB::table('user_collections')->insert([
                'user_id' => $userId,
                'name' => '競合清單',
                'public_slug' => 'race-winner',
                'is_public' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/my/collections', ['name' => '競合清單'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'duplicate');

        $this->assertTrue($injected);
        $this->assertDatabaseCount('user_collections', 1);
    }

    /** @return array{string, int} */
    private function login(string $email): array
    {
        $login = $this->postJson('/auth/google', ['idToken' => "dev:{$email}"])->assertOk();

        return [$login->json('token'), (int) $login->json('user.id')];
    }
}
