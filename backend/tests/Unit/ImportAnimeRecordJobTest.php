<?php

namespace Tests\Unit;

use App\Jobs\ImportAnimeRecordJob;
use App\Models\Anime;
use App\Services\AnimeCatalog\AnimeImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ImportAnimeRecordJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_imports_the_record_via_anime_import_service(): void
    {
        $record = [
            'season_year' => 2026, 'season_code' => 'spring',
            'title_zh' => '測試動畫', 'title_ja' => 'テストアニメ',
        ];

        $job = new ImportAnimeRecordJob($record, 'acgsecrets', 'test-batch-1');
        $job->handle(app(AnimeImportService::class));

        $this->assertSame(1, Anime::count());
        $this->assertSame('測試動畫', Anime::first()->name);
    }

    public function test_tries_is_three(): void
    {
        $job = new ImportAnimeRecordJob(['title_zh' => 'x'], 'acgsecrets', 'test-batch-2');

        $this->assertSame(3, $job->tries);
    }

    public function test_handle_increments_batch_success_counter(): void
    {
        $record = ['title_zh' => '測試動畫2', 'season_year' => 2026, 'season_code' => 'spring'];
        $job = new ImportAnimeRecordJob($record, 'acgsecrets', 'test-batch-3');

        $job->handle(app(AnimeImportService::class));

        $this->assertSame(1, Cache::get('import:test-batch-3:success'));
    }
}
