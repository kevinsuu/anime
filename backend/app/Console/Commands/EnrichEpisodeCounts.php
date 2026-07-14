<?php

namespace App\Console\Commands;

use App\Services\AnimeCatalog\BangumiClient;
use App\Services\AnimeCatalog\BangumiTitleMatcher;
use App\Services\Shared\JsonFileWriter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfills episode_count on existing acgsecrets/mylist JSON snapshots via the
 * Bangumi API, for records acgsecrets itself never published a count for.
 * Updates the JSON files in place — it does not re-scrape or touch any other
 * field. --source selects which seed directory to process (default acgsecrets);
 * mylist uses the same record shape and is processed identically.
 *
 * Two lookup paths, tried in order per record:
 *  1. external_ids.bangumi already present -> fetch directly by id.
 *  2. no bangumi id (common on pre-2021 seasons whose acgsecrets page never
 *     linked one) -> search Bangumi by title_ja and accept only an exact,
 *     unambiguous match (see BangumiTitleMatcher); the resolved id is written
 *     back to external_ids.bangumi so future runs use path 1 directly.
 * Anything that doesn't resolve via either path is left null for manual review.
 */
final class EnrichEpisodeCounts extends Command
{
    protected $signature = 'anime:enrich-episode-counts {--source=acgsecrets} {--season=} {--dry-run}';

    protected $description = 'Backfill missing episode_count in acgsecrets/mylist JSON snapshots via the Bangumi API.';

    public function handle(BangumiClient $client, BangumiTitleMatcher $matcher, JsonFileWriter $jsonWriter): int
    {
        $source = (string) $this->option('source');
        if (! in_array($source, ['acgsecrets', 'mylist'], true)) {
            $this->error("Invalid --source: {$source} (expected acgsecrets or mylist)");

            return self::FAILURE;
        }

        $dir = database_path("seed/{$source}");
        $files = $this->resolveFiles($dir);

        if ($files === []) {
            $this->error('No season JSON files found.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $totalUpdated = 0;
        $totalMatched = 0;
        $totalFailed = 0;
        $totalUnresolved = 0;

        foreach ($files as $path) {
            $records = json_decode((string) file_get_contents($path), true);
            if (! is_array($records)) {
                $this->error(basename($path).': invalid JSON, skipped');

                continue;
            }

            $updated = 0;
            $matched = 0;
            $failed = 0;
            $unresolved = 0;

            foreach ($records as $i => $record) {
                if (! is_array($record) || ! empty($record['episode_count'])) {
                    continue;
                }

                $bangumiId = $record['external_ids']['bangumi'] ?? null;

                if ($bangumiId === null || $bangumiId === '') {
                    $titleJa = trim((string) ($record['title_ja'] ?? ''));
                    if ($titleJa === '') {
                        continue;
                    }

                    try {
                        $candidates = $client->searchSubjects($titleJa);
                        $client->throttle();
                    } catch (Throwable $e) {
                        $failed++;
                        $this->warn(basename($path).": search \"{$titleJa}\" failed: {$e->getMessage()}");

                        continue;
                    }

                    $match = $matcher->matchExact($titleJa, $candidates);
                    if ($match === null) {
                        $unresolved++;

                        continue;
                    }

                    $bangumiId = (string) $match['id'];
                    $records[$i]['external_ids']['bangumi'] = $bangumiId;
                    $matched++;
                }

                try {
                    $episodeCount = $client->fetchEpisodeCount((string) $bangumiId);
                    $client->throttle();
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn(basename($path).": bangumi/{$bangumiId} failed: {$e->getMessage()}");

                    continue;
                }

                if ($episodeCount === null) {
                    continue;
                }

                $records[$i]['episode_count'] = $episodeCount;
                $updated++;
            }

            if (($updated > 0 || $matched > 0) && ! $dryRun) {
                $jsonWriter->write(
                    $path,
                    $records,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
                );
            }

            if ($updated > 0 || $matched > 0 || $failed > 0 || $unresolved > 0) {
                $this->info(basename($path).": updated {$updated}, matched-by-title {$matched}, failed {$failed}, unresolved {$unresolved}");
            }

            $totalUpdated += $updated;
            $totalMatched += $matched;
            $totalFailed += $failed;
            $totalUnresolved += $unresolved;
        }

        $this->info(
            "Total: updated {$totalUpdated}, matched-by-title {$totalMatched}, failed {$totalFailed}, unresolved {$totalUnresolved}"
            .($dryRun ? ' (dry run, files not written)' : '')
        );

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function resolveFiles(string $dir): array
    {
        if ($season = (string) $this->option('season')) {
            $path = "{$dir}/{$season}.json";

            return is_file($path) ? [$path] : [];
        }

        $files = glob("{$dir}/*.json") ?: [];

        return array_values(array_filter(
            $files,
            fn ($f) => ! in_array(basename($f), ['summary.json', 'watched.json'], true)
        ));
    }

}
