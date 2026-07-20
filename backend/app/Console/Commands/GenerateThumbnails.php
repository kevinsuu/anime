<?php

namespace App\Console\Commands;

use App\Models\Anime;
use App\Services\AnimeCatalog\ThumbnailService;
use Illuminate\Console\Command;

final class GenerateThumbnails extends Command
{
    protected $signature = 'anime:generate-thumbnails
        {--year= : Only backfill anime from this season year}
        {--season= : Only backfill winter, spring, summer, or fall}
        {--force : Regenerate thumbnails that already exist within the selected scope}';

    protected $description = 'Generate optimized cover thumbnails for missing or explicitly selected anime rows.';

    /**
     * 每筆之間節流的毫秒數，避免對 acgsecrets 瞬間發出大量並發請求。
     */
    private const THROTTLE_MS = 150;

    public function handle(ThumbnailService $thumbnails): int
    {
        $year = $this->option('year');
        $season = $this->option('season');
        $force = (bool) $this->option('force');

        if ($year !== null && (! ctype_digit((string) $year) || (int) $year < 1900 || (int) $year > 2100)) {
            $this->error('The --year option must be an integer between 1900 and 2100.');

            return self::FAILURE;
        }

        if ($season !== null && ! in_array($season, ['winter', 'spring', 'summer', 'fall'], true)) {
            $this->error('The --season option must be winter, spring, summer, or fall.');

            return self::FAILURE;
        }

        $candidates = fn () => Anime::query()
            ->whereNotNull('image_url')
            ->when(! $force, fn ($query) => $query->whereNull('cover_image_path'))
            ->when($year !== null, fn ($query) => $query->where('season_year', (int) $year))
            ->when($season !== null, fn ($query) => $query->where('season_code', $season));

        $total = $candidates()->count();

        if ($total === 0) {
            $this->info('No anime rows need a thumbnail backfill.');

            return self::SUCCESS;
        }

        $this->info("Backfilling thumbnails for {$total} anime row(s)...");

        $processed = 0;
        $succeeded = 0;
        $bar = $this->output->createProgressBar($total);

        $candidates()->chunkById(50, function ($batch) use ($thumbnails, &$processed, &$succeeded, $bar): void {
            foreach ($batch as $anime) {
                $path = $thumbnails->generate($anime->getRawOriginal('image_url'), $anime->id);
                if ($path !== null) {
                    $anime->cover_image_path = $path;
                    $anime->save();
                    $succeeded++;
                }
                $processed++;
                $bar->advance();
                usleep(self::THROTTLE_MS * 1000);
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done: {$succeeded}/{$processed} thumbnail(s) generated successfully.");

        return self::SUCCESS;
    }
}
