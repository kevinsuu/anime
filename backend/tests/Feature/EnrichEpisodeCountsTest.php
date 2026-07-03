<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EnrichEpisodeCountsTest extends TestCase
{
    private string $path;

    private string $mylistPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = database_path('seed/acgsecrets/_test_enrich.json');
        $this->mylistPath = database_path('seed/mylist/_test_enrich.json');
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        if (is_file($this->mylistPath)) {
            unlink($this->mylistPath);
        }
        parent::tearDown();
    }

    public function test_backfills_episode_count_from_bangumi_when_missing(): void
    {
        file_put_contents($this->path, json_encode([
            [
                'title_zh' => '有集數的作品',
                'episode_count' => null,
                'external_ids' => ['bangumi' => '424883'],
            ],
            [
                'title_zh' => '已經有集數，不應被覆蓋',
                'episode_count' => 99,
                'external_ids' => ['bangumi' => '568572'],
            ],
            [
                'title_zh' => '沒有 bangumi id，應被略過',
                'episode_count' => null,
                'external_ids' => [],
            ],
        ], JSON_UNESCAPED_UNICODE));

        Http::fake([
            'api.bgm.tv/v0/subjects/424883' => Http::response(['eps' => 12, 'total_episodes' => 12]),
        ]);

        $this->artisan('anime:enrich-episode-counts', ['--season' => '_test_enrich'])
            ->assertSuccessful();

        $records = json_decode((string) file_get_contents($this->path), true);

        $this->assertSame(12, $records[0]['episode_count']);
        $this->assertSame(99, $records[1]['episode_count']);
        $this->assertNull($records[2]['episode_count']);

        Http::assertSentCount(1);
    }

    public function test_dry_run_does_not_write_file(): void
    {
        file_put_contents($this->path, json_encode([
            [
                'title_zh' => '有集數的作品',
                'episode_count' => null,
                'external_ids' => ['bangumi' => '424883'],
            ],
        ], JSON_UNESCAPED_UNICODE));

        Http::fake([
            'api.bgm.tv/*' => Http::response(['eps' => 12]),
        ]);

        $this->artisan('anime:enrich-episode-counts', ['--season' => '_test_enrich', '--dry-run' => true])
            ->assertSuccessful();

        $records = json_decode((string) file_get_contents($this->path), true);

        $this->assertNull($records[0]['episode_count']);
    }

    public function test_skips_subject_when_bangumi_returns_zero_episodes(): void
    {
        file_put_contents($this->path, json_encode([
            [
                'title_zh' => '尚未播出',
                'episode_count' => null,
                'external_ids' => ['bangumi' => '999999'],
            ],
        ], JSON_UNESCAPED_UNICODE));

        Http::fake([
            'api.bgm.tv/*' => Http::response(['eps' => 0]),
        ]);

        $this->artisan('anime:enrich-episode-counts', ['--season' => '_test_enrich'])
            ->assertSuccessful();

        $records = json_decode((string) file_get_contents($this->path), true);

        $this->assertNull($records[0]['episode_count']);
    }

    public function test_falls_back_to_title_search_when_no_bangumi_id_and_writes_resolved_id_back(): void
    {
        file_put_contents($this->path, json_encode([
            [
                'title_zh' => '春&夏推理事件簿',
                'title_ja' => 'ハルチカ ～ハルタとチカは青春する～',
                'episode_count' => null,
                'external_ids' => [],
            ],
        ], JSON_UNESCAPED_UNICODE));

        Http::fake([
            'api.bgm.tv/search/subject/*' => Http::response([
                'results' => 1,
                'list' => [
                    ['id' => 136213, 'name' => 'ハルチカ ～ハルタとチカは青春する～', 'name_cn' => '春&夏推理事件簿'],
                ],
            ]),
            'api.bgm.tv/v0/subjects/136213' => Http::response(['total_episodes' => 12]),
        ]);

        $this->artisan('anime:enrich-episode-counts', ['--season' => '_test_enrich'])
            ->assertSuccessful();

        $records = json_decode((string) file_get_contents($this->path), true);

        $this->assertSame(12, $records[0]['episode_count']);
        $this->assertSame('136213', $records[0]['external_ids']['bangumi']);
    }

    public function test_does_not_write_anything_when_title_search_is_ambiguous(): void
    {
        file_put_contents($this->path, json_encode([
            [
                'title_zh' => '同名作品',
                'title_ja' => 'タイトル',
                'episode_count' => null,
                'external_ids' => [],
            ],
        ], JSON_UNESCAPED_UNICODE));

        Http::fake([
            'api.bgm.tv/search/subject/*' => Http::response([
                'results' => 2,
                'list' => [
                    ['id' => 1, 'name' => 'タイトル', 'name_cn' => ''],
                    ['id' => 2, 'name' => 'タイトル', 'name_cn' => ''],
                ],
            ]),
        ]);

        $this->artisan('anime:enrich-episode-counts', ['--season' => '_test_enrich'])
            ->assertSuccessful();

        $records = json_decode((string) file_get_contents($this->path), true);

        $this->assertNull($records[0]['episode_count']);
        $this->assertArrayNotHasKey('bangumi', $records[0]['external_ids']);
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '/v0/subjects/'));
    }

    public function test_skips_search_when_title_ja_is_missing(): void
    {
        file_put_contents($this->path, json_encode([
            [
                'title_zh' => '沒有日文原名',
                'title_ja' => '',
                'episode_count' => null,
                'external_ids' => [],
            ],
        ], JSON_UNESCAPED_UNICODE));

        Http::fake();

        $this->artisan('anime:enrich-episode-counts', ['--season' => '_test_enrich'])
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_source_option_targets_the_mylist_directory(): void
    {
        file_put_contents($this->mylistPath, json_encode([
            [
                'title_zh' => 'mylist 條目',
                'episode_count' => null,
                'external_ids' => ['bangumi' => '380448'],
            ],
        ], JSON_UNESCAPED_UNICODE));

        Http::fake([
            'api.bgm.tv/v0/subjects/380448' => Http::response(['total_episodes' => 13]),
        ]);

        $this->artisan('anime:enrich-episode-counts', ['--source' => 'mylist', '--season' => '_test_enrich'])
            ->assertSuccessful();

        $records = json_decode((string) file_get_contents($this->mylistPath), true);

        $this->assertSame(13, $records[0]['episode_count']);
        // The acgsecrets fixture must not have been touched.
        $this->assertFileDoesNotExist($this->path);
    }

    public function test_rejects_invalid_source_option(): void
    {
        $this->artisan('anime:enrich-episode-counts', ['--source' => 'bogus'])
            ->assertFailed();
    }
}
