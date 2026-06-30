<?php

namespace App\Console\Commands;

use App\Services\AnimeCatalog\AnimeImportService;
use App\Services\AnimeCatalog\SeasonResolver;
use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class SyncSeasonalAnime extends Command
{
    protected $signature = 'anime:sync-seasonal {--year=} {--season=}';

    protected $description = 'Sync seasonal anime catalog from Bangumi.';

    public function handle(AnimeImportService $service): int
    {
        $currentSeason = SeasonResolver::current(new DateTimeImmutable('now'));
        $year = (int) ($this->option('year') ?: $currentSeason['year']);
        $season = (string) ($this->option('season') ?: $currentSeason['code']);

        if ($year < 1900 || $year > 2100) {
            throw new InvalidArgumentException('year must be between 1900 and 2100');
        }

        SeasonResolver::months($season);

        $result = $service->syncBangumiSeason($year, $season);
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $result['skipped'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
