<?php

namespace App\Console\Commands;

use App\Models\Anime;
use App\Services\AnimeCatalog\ThumbnailService;
use Illuminate\Console\Command;

final class GenerateThumbnails extends Command
{
    protected $signature = 'anime:generate-thumbnails';

    protected $description = 'One-time backfill: generate cover thumbnails for existing anime rows that don\'t have one yet.';

    /**
     * 每筆之間節流的毫秒數，避免對 acgsecrets 瞬間發出大量並發請求。
     */
    private const THROTTLE_MS = 150;

    public function handle(ThumbnailService $thumbnails): int
    {
        $total = Anime::query()
            ->whereNull('cover_image_path')
            ->whereNotNull('image_url')
            ->count();

        if ($total === 0) {
            $this->info('No anime rows need a thumbnail backfill.');

            return self::SUCCESS;
        }

        $this->info("Backfilling thumbnails for {$total} anime row(s)...");

        $processed = 0;
        $succeeded = 0;
        $bar = $this->output->createProgressBar($total);

        Anime::query()
            ->whereNull('cover_image_path')
            ->whereNotNull('image_url')
            ->chunkById(50, function ($batch) use ($thumbnails, &$processed, &$succeeded, $bar): void {
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
