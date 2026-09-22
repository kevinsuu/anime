<?php

namespace App\Console\Commands;

use App\Services\AnimeCatalog\AcgSecretsClient;
use App\Services\AnimeCatalog\AcgSecretsParser;
use App\Services\AnimeCatalog\SeasonResolver;
use App\Services\Shared\JsonFileWriter;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Throwable;

final class ScrapeAcgSecrets extends Command
{
    protected $signature = 'anime:scrape-acgsecrets {--all} {--season=}';

    protected $description = 'Scrape acgsecrets.hk seasonal anime into JSON files.';

    public function handle(AcgSecretsClient $client, AcgSecretsParser $parser, JsonFileWriter $jsonWriter): int
    {
        $dir = database_path('seed/acgsecrets');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Cannot create directory: {$dir}");

            return self::FAILURE;
        }

        $seasons = $this->resolveSeasons($client, $parser);
        $summary = ['generated_at' => date('c'), 'seasons' => [], 'failed' => []];

        foreach ($seasons as $yyyymm) {
            try {
                $html = $client->fetchSeason($yyyymm);
                $records = $parser->parseSeasonPage($html, $yyyymm);
                $jsonWriter->write("{$dir}/{$yyyymm}.json", $records,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                $summary['seasons'][$yyyymm] = [
                    'count' => count($records),
                    'missing_title_zh' => count(array_filter($records, fn ($r) => $r['title_zh'] === '')),
                    'missing_summary' => count(array_filter($records, fn ($r) => $r['summary'] === '')),
                    'missing_cover' => count(array_filter($records, fn ($r) => $r['cover_image'] === '')),
                ];
                $this->info("{$yyyymm}: ".count($records).' records');
            } catch (Throwable $e) {
                $summary['failed'][] = ['season' => $yyyymm, 'error' => $e->getMessage()];
                $this->error("{$yyyymm}: {$e->getMessage()}");
            }
        }

        $jsonWriter->write("{$dir}/summary.json", $summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return empty($summary['failed']) ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<int, string> */
    private function resolveSeasons(AcgSecretsClient $client, AcgSecretsParser $parser): array
    {
        if ($season = (string) $this->option('season')) {
            return [$season];
        }
        if ($this->option('all')) {
            return $parser->parseSeasonIndex($client->fetchIndex());
        }

        // Default: scrape the past two years plus a season that starts within
        // the next week. ACG Secrets commonly publishes an upcoming season's
        // page before its first day, so fetch it on the preceding weekly run.
        // Seasons start in Jan(01), Apr(04), Jul(07), Oct(10).
        $now = new DateTimeImmutable('now');
        $currentYear = (int) $now->format('Y');
        $latestSeasonStart = $now->modify('+7 days');
        $latestSeasonYear = (int) $latestSeasonStart->format('Y');
        $seasonMonths = [1, 4, 7, 10];

        $seasons = [];
        for ($year = $currentYear - 1; $year <= $latestSeasonYear; $year++) {
            foreach ($seasonMonths as $month) {
                $seasonStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
                if ($seasonStart > $latestSeasonStart) {
                    continue;
                }
                $seasons[] = sprintf('%04d%02d', $year, $month);
            }
        }

        return $seasons;
    }
}
