<?php

namespace App\Console\Commands;

use App\Models\Anime;
use App\Models\AnimeAlias;
use App\Models\AnimeExternalId;
use App\Models\AnimeStream;
use App\Models\AnimeTitle;
use App\Services\AnimeCatalog\AnimeImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ImportAcgSecrets extends Command
{
    protected $signature = 'anime:import-acgsecrets {--fresh}';

    protected $description = 'Import scraped acgsecrets JSON files into the anime catalog tables.';

    public function handle(AnimeImportService $service): int
    {
        if ($this->option('fresh')) {
            DB::transaction(function (): void {
                AnimeStream::query()->delete();
                AnimeExternalId::query()->delete();
                AnimeAlias::query()->delete();
                AnimeTitle::query()->delete();
                Anime::query()->delete();
            });
            $this->warn('Cleared existing anime data.');
        }

        $dir = database_path('seed/acgsecrets');
        $files = glob("{$dir}/*.json") ?: [];

        $totalImported = 0;
        $totalSkipped = 0;

        foreach ($files as $file) {
            $name = basename($file);
            if ($name === 'summary.json') {
                continue;
            }

            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new RuntimeException("Failed to read {$file}");
            }

            $records = json_decode($contents, true);
            if (! is_array($records)) {
                $this->error("{$name}: invalid JSON, skipping file");

                continue;
            }

            $result = $service->importSeason($records);
            $totalImported += $result['imported'];
            $totalSkipped += $result['skipped'];

            $this->line("{$name}: imported {$result['imported']}, skipped {$result['skipped']}");
        }

        $this->info("Total: imported {$totalImported}, skipped {$totalSkipped}");

        return self::SUCCESS;
    }
}
