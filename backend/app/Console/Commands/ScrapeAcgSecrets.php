<?php

namespace App\Console\Commands;

use App\Services\AnimeCatalog\AcgSecretsClient;
use App\Services\AnimeCatalog\AcgSecretsParser;
use App\Services\AnimeCatalog\SeasonResolver;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Throwable;

final class ScrapeAcgSecrets extends Command
{
    protected $signature = 'anime:scrape-acgsecrets {--all} {--season=}';

    protected $description = 'Scrape acgsecrets.hk seasonal anime into JSON files.';

    public function handle(AcgSecretsClient $client, AcgSecretsParser $parser): int
    {
        $dir = database_path('seed/acgsecrets');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $seasons = $this->resolveSeasons($client, $parser);
        $summary = ['generated_at' => date('c'), 'seasons' => [], 'failed' => []];

        foreach ($seasons as $yyyymm) {
            try {
                $html = $client->fetchSeason($yyyymm);
                $records = $parser->parseSeasonPage($html, $yyyymm);
                file_put_contents("{$dir}/{$yyyymm}.json",
                    json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
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

        file_put_contents("{$dir}/summary.json",
            json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

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
