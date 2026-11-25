<?php

namespace App\Console\Commands;

use App\Models\Anime;
use App\Services\Seo\AnimeIndexability;
use App\Services\Seo\IndexNowClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

final class SubmitIndexNow extends Command
{
    protected $signature = 'anime:submit-indexnow {--since= : Submit anime updated at or after this time (default: 24 hours ago)}';

    protected $description = 'Notify IndexNow of anime detail and seasonal pages changed by the latest import.';

    private const SEASON_CODES = ['winter', 'spring', 'summer', 'fall'];

    public function handle(IndexNowClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->line('IndexNow: skipped (INDEXNOW_KEY not set)');

            return self::SUCCESS;
        }

        try {
            $since = $this->option('since') !== null
                ? Carbon::parse((string) $this->option('since'))
                : now()->subDay();
        } catch (Throwable) {
            $this->error('Invalid --since value: '.$this->option('since'));

            return self::FAILURE;
        }

        $urls = $this->changedUrls($client->siteUrl(), $since);
        if ($urls === []) {
            $this->line("IndexNow: no pages changed since {$since->toIso8601String()}");

            return self::SUCCESS;
        }

        try {
            $client->submit($urls);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('IndexNow: submitted '.count($urls).' URLs');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function changedUrls(string $siteUrl, Carbon $since): array
    {
        $animeUrls = [];
        $seasonUrls = [];

        Anime::query()
            ->where('updated_at', '>=', $since)
            ->select(['id', 'description', 'episode_count', 'season_year', 'season_code'])
            ->withCount(['streams', 'cast'])
            ->orderBy('id')
            ->each(function (Anime $anime) use ($siteUrl, &$animeUrls, &$seasonUrls): void {
                // Thin pages are noindex on the frontend; pushing them wastes crawl budget.
                if (AnimeIndexability::isIndexable(
                    $anime->description,
                    $anime->episode_count !== null ? (int) $anime->episode_count : null,
                    (int) $anime->streams_count,
                    (int) $anime->cast_count,
                )) {
                    $animeUrls[] = "{$siteUrl}/anime/{$anime->id}";
                }
                if ($anime->season_year && in_array($anime->season_code, self::SEASON_CODES, true)) {
                    $seasonUrls["{$anime->season_year}:{$anime->season_code}"] =
                        "{$siteUrl}/?year={$anime->season_year}&season={$anime->season_code}";
                }
            });

        if ($animeUrls === [] && $seasonUrls === []) {
            return [];
        }

        // The homepage renders the current season, so any catalog change may affect it.
        return ["{$siteUrl}/", ...array_values($seasonUrls), ...$animeUrls];
    }
}
