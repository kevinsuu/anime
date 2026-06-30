<?php

namespace App\Services\AnimeCatalog;

use App\Models\Anime;
use App\Models\AnimeExternalId;
use App\Models\AnimeTitle;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AnimeImportService
{
    public function __construct(
        private readonly BangumiClient $bangumi,
        private readonly BangumiAnimeNormalizer $normalizer,
    ) {
    }

    public function syncBangumiSeason(int $year, string $seasonCode): array
    {
        $items = $this->bangumi->fetchSeason($year, $seasonCode);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($items as $item) {
            try {
                $record = $this->normalizer->normalize($item);
                $this->upsertImported($record);
                $imported++;
            } catch (Throwable $exception) {
                $skipped++;
                $errors[] = [
                    'id' => $item['id'] ?? null,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'provider' => 'bangumi',
            'year' => $year,
            'season' => $seasonCode,
            'fetched' => count($items),
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    public function upsertImported(array $record): Anime
    {
        $payloadHash = hash('sha256', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($record, $payloadHash): Anime {
            $external = AnimeExternalId::query()
                ->where('provider', $record['provider'])
                ->where('external_id', $record['externalId'])
                ->first();

            $anime = $external?->anime ?? new Anime();
            $anime->fill([
                'name' => $record['primaryTitle'],
                'description' => $record['description'],
                'image_url' => $record['imageUrl'],
                'source' => $record['provider'],
                'created_by_user_id' => null,
                'season_year' => $record['seasonYear'] ?? null,
                'season_code' => $record['seasonCode'] ?? null,
                'air_date' => $record['airDate'] ?? null,
                'episode_count' => $record['episodeCount'] ?? null,
                'status' => $record['status'] ?? null,
            ]);
            $anime->save();

            AnimeExternalId::query()->updateOrCreate([
                'provider' => $record['provider'],
                'external_id' => $record['externalId'],
            ], [
                'anime_id' => $anime->id,
                'url' => $record['providerUrl'] ?? null,
                'last_synced_at' => now(),
                'payload_hash' => $payloadHash,
            ]);

            AnimeTitle::query()
                ->where('anime_id', $anime->id)
                ->update(['is_primary' => false]);

            foreach ($record['titles'] as $locale => $title) {
                $title = trim((string) $title);
                if ($title === '') {
                    continue;
                }

                AnimeTitle::query()->updateOrCreate([
                    'anime_id' => $anime->id,
                    'locale' => $locale,
                    'title' => $title,
                ], [
                    'is_primary' => $title === $record['primaryTitle'],
                    'source' => $record['provider'],
                ]);
            }

            return $anime->refresh();
        });
    }
}
