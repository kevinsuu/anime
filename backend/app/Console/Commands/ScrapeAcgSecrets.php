<?php

namespace App\Console\Commands;

use App\Services\AnimeCatalog\AcgSecretsClient;
use App\Services\AnimeCatalog\AcgSecretsParser;
use App\Services\AnimeCatalog\SeasonResolver;
use DateTimeImmutable;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ScrapeAcgSecrets extends Command
{
    protected $signature = 'anime:scrape-acgsecrets {--all} {--season=}';

    protected $description = 'Scrape acgsecrets.hk seasonal anime into JSON files.';

    public function handle(AcgSecretsClient $client, AcgSecretsParser $parser): int
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
                $this->writeJson("{$dir}/{$yyyymm}.json", $records,
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

        $this->writeJson("{$dir}/summary.json", $summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return empty($summary['failed']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Encode data to JSON and write it, throwing if encoding or the write fails
     * so a silent filesystem error cannot be reported as success.
     */
    private function writeJson(string $path, mixed $data, int $flags): void
    {
        $json = json_encode($data, $flags);
        if ($json === false) {
            throw new RuntimeException("Failed to encode JSON for {$path}: ".json_last_error_msg());
        }
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException("Failed to write {$path}");
        }
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
        $now = new DateTimeImmutable('now');
        $current = SeasonResolver::current($now);
        $months = ['winter' => 1, 'spring' => 4, 'summer' => 7, 'fall' => 10];
        $curMonth = $months[$current['code']];
        $curYyyymm = sprintf('%04d%02d', $current['year'], $curMonth);
        $prevMonth = $curMonth - 3;
        $prevYear = $current['year'];
        if ($prevMonth < 1) {
            $prevMonth = 10;
            $prevYear--;
        }
        $prevYyyymm = sprintf('%04d%02d', $prevYear, $prevMonth);

        return [$prevYyyymm, $curYyyymm];
    }
}
