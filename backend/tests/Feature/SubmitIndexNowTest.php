<?php

namespace Tests\Feature;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SubmitIndexNowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.indexnow.key' => 'test-indexnow-key',
            'services.indexnow.site_url' => 'https://example.test',
            'services.indexnow.endpoint' => 'https://indexnow.test/indexnow',
        ]);
    }

    private function createAnime(string $name, string $description, string $updatedAt, string $season = 'fall'): Anime
    {
        $anime = Anime::create([
            'name' => $name,
            'description' => $description,
            'season_year' => 2026,
            'season_code' => $season,
        ]);
        $anime->forceFill(['updated_at' => Carbon::parse($updatedAt)])->saveQuietly();

        return $anime;
    }

    public function test_submits_changed_indexable_anime_and_their_season_pages(): void
    {
        Http::fake(['indexnow.test/*' => Http::response('', 202)]);

        $longSynopsis = str_repeat('作品介紹', 40);
        $changed = $this->createAnime('新作', $longSynopsis, '2026-11-25 05:10:00');
        $thin = $this->createAnime('薄內容', '一句話', '2026-11-25 05:10:00', 'summer');
        $stale = $this->createAnime('舊作', $longSynopsis, '2026-11-01 00:00:00', 'spring');

        $this->artisan('anime:submit-indexnow', ['--since' => '2026-11-25 05:00:00'])
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($changed, $thin, $stale): bool {
            $urls = $request['urlList'];

            return $request->url() === 'https://indexnow.test/indexnow'
                && $request['host'] === 'example.test'
                && $request['key'] === 'test-indexnow-key'
                && $request['keyLocation'] === 'https://example.test/indexnow-key.txt'
                && in_array('https://example.test/', $urls, true)
                && in_array("https://example.test/anime/{$changed->id}", $urls, true)
                && in_array('https://example.test/?year=2026&season=fall', $urls, true)
                // A thin page is noindex, but its season page still changed.
                && ! in_array("https://example.test/anime/{$thin->id}", $urls, true)
                && in_array('https://example.test/?year=2026&season=summer', $urls, true)
                && ! in_array("https://example.test/anime/{$stale->id}", $urls, true)
                && ! in_array('https://example.test/?year=2026&season=spring', $urls, true);
        });
    }

    public function test_submits_a_shorter_synopsis_backed_by_streams_and_cast(): void
    {
        Http::fake(['indexnow.test/*' => Http::response('', 202)]);

        $supported = $this->createAnime('有資料的新作', str_repeat('介', 80), '2026-11-25 05:10:00');
        $supported->streams()->create(['region' => 'tw', 'platform' => '巴哈姆特動畫瘋', 'url' => 'https://example.test/s']);
        foreach (['甲', '乙', '丙'] as $order => $actor) {
            $supported->cast()->create(['character' => "角色{$actor}", 'actor' => $actor, 'sort_order' => $order]);
        }
        $supported->forceFill(['updated_at' => Carbon::parse('2026-11-25 05:10:00')])->saveQuietly();
        $unsupported = $this->createAnime('只有短介紹', str_repeat('介', 80), '2026-11-25 05:10:00');

        $this->artisan('anime:submit-indexnow', ['--since' => '2026-11-25 05:00:00'])
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => in_array("https://example.test/anime/{$supported->id}", $request['urlList'], true)
            && ! in_array("https://example.test/anime/{$unsupported->id}", $request['urlList'], true));
    }

    public function test_skips_without_request_when_nothing_changed(): void
    {
        Http::fake();
        $this->createAnime('舊作', str_repeat('作品介紹', 40), '2026-11-01 00:00:00');

        $this->artisan('anime:submit-indexnow', ['--since' => '2026-11-25 05:00:00'])
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_skips_when_key_is_not_configured(): void
    {
        config(['services.indexnow.key' => '']);
        Http::fake();
        $this->createAnime('新作', str_repeat('作品介紹', 40), '2026-11-25 05:10:00');

        $this->artisan('anime:submit-indexnow', ['--since' => '2026-11-25 05:00:00'])
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_fails_when_indexnow_rejects_the_submission(): void
    {
        Http::fake(['indexnow.test/*' => Http::response('', 403)]);
        $this->createAnime('新作', str_repeat('作品介紹', 40), '2026-11-25 05:10:00');

        $this->artisan('anime:submit-indexnow', ['--since' => '2026-11-25 05:00:00'])
            ->assertFailed();
    }

    public function test_rejects_an_invalid_since_option(): void
    {
        Http::fake();

        $this->artisan('anime:submit-indexnow', ['--since' => 'not-a-date'])
            ->assertFailed();

        Http::assertNothingSent();
    }
}
