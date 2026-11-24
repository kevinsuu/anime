<?php

namespace Tests\Feature;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Catalog data is identical for every visitor and only changes through the
 * weekly import, so it may be cached by Cloudflare and the browser. Personal
 * endpoints must never be marked shareable.
 */
final class PublicCatalogCacheHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function makeAnime(): Anime
    {
        return Anime::query()->create([
            'name' => '快取作品',
            'description' => '作品介紹',
            'image_url' => 'https://example.com/cover.webp',
            'source' => 'test',
            'season_year' => 2026,
            'season_code' => 'fall',
            'tags' => ['奇幻'],
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function publicCatalogPaths(): array
    {
        return [
            'detail' => ['/anime/{id}'],
            'search' => ['/anime?year=2026&season=fall'],
            'summaries' => ['/anime/summaries?year=2026&season=fall'],
            'tags' => ['/anime/tags'],
        ];
    }

    #[DataProvider('publicCatalogPaths')]
    public function test_public_catalog_responses_are_edge_cacheable(string $path): void
    {
        $anime = $this->makeAnime();

        $response = $this->getJson(str_replace('{id}', (string) $anime->id, $path))->assertOk();

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=60', $cacheControl);
        $this->assertStringContainsString('s-maxage=300', $cacheControl);
        $this->assertStringContainsString('stale-while-revalidate=600', $cacheControl);
        $this->assertStringNotContainsString('private', $cacheControl);
        $this->assertNotNull($response->headers->get('ETag'));
    }

    public function test_matching_etag_returns_not_modified(): void
    {
        $anime = $this->makeAnime();
        $etag = $this->getJson("/anime/{$anime->id}")->assertOk()->headers->get('ETag');

        $this->getJson("/anime/{$anime->id}", ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_missing_anime_is_not_cached_as_shareable(): void
    {
        $response = $this->getJson('/anime/999999')->assertNotFound();

        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    public function test_personal_endpoints_stay_private(): void
    {
        $token = $this->postJson('/auth/google', ['idToken' => 'dev:cache@example.com'])
            ->assertOk()
            ->json('token');

        $response = $this->withToken($token)->getJson('/my/anime-list')->assertOk();

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringNotContainsString('public', $cacheControl);
        $this->assertStringNotContainsString('s-maxage', $cacheControl);
    }
}
