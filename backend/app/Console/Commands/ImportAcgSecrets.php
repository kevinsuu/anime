<?php

namespace App\Console\Commands;

use App\Models\Anime;
use App\Models\AnimeAlias;
use App\Models\AnimeExternalId;
use App\Models\AnimeStream;
use App\Models\AnimeTitle;
use App\Services\AnimeCatalog\AnimeImportService;
use App\Services\AnimeCatalog\WatchedManifestImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ImportAcgSecrets extends Command
{
    protected $signature = 'anime:import-acgsecrets {--fresh}';

    protected $description = 'Import scraped acgsecrets JSON files (and personal mylist seeds) into the anime catalog tables.';

    public function handle(AnimeImportService $service, WatchedManifestImporter $watched): int
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

        // mylist 為個人補充的種子資料(結構與 acgsecrets 相同),acgsecrets 先匯入,
        // mylist 靠 external_ids.bangumi 與既有紀錄去重。
        $sources = [
            'acgsecrets' => database_path('seed/acgsecrets'),
            'mylist' => database_path('seed/mylist'),
        ];

        $totalImported = 0;
        $totalUnchanged = 0;
        $totalSkipped = 0;

        foreach ($sources as $source => $dir) {
            foreach (glob("{$dir}/*.json") ?: [] as $file) {
                $name = basename($file);
                if (in_array($name, ['summary.json', 'watched.json'], true)) {
                    continue;
                }

                $contents = file_get_contents($file);
                if ($contents === false) {
                    throw new RuntimeException("Failed to read {$file}");
                }

                $records = json_decode($contents, true);
                if (! is_array($records)) {
                    $this->error("{$source}/{$name}: invalid JSON, skipping file");

                    continue;
                }

                $result = $service->importSeason($records, $source);
                $totalImported += $result['imported'];
                $totalUnchanged += $result['unchanged'];
                $totalSkipped += $result['skipped'];

                $this->line("{$source}/{$name}: imported {$result['imported']}, unchanged {$result['unchanged']}, skipped {$result['skipped']}");
            }
        }

        $this->info("Total: imported {$totalImported}, unchanged {$totalUnchanged}, skipped {$totalSkipped}");

        $result = $watched->sync();
        if ($result['skipped']) {
            $this->line('watched manifest: skipped (MYLIST_OWNER_EMAIL not set or manifest missing)');
        } else {
            $this->info("watched manifest: marked {$result['marked']}, already in list {$result['existing']}, unresolved ".count($result['missing']));
            foreach ($result['missing'] as $title) {
                $this->warn("  unresolved: {$title}");
            }
        }

        return self::SUCCESS;
    }
}
