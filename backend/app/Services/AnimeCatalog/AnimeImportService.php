<?php

namespace App\Services\AnimeCatalog;

use App\Models\Anime;
use App\Models\AnimeAlias;
use App\Models\AnimeExternalId;
use App\Models\AnimeStream;
use App\Models\AnimeTitle;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AnimeImportService
{
    /**
     * Provider URL templates keyed by provider name.
     *
     * @var array<string, string>
     */
    private const PROVIDER_URLS = [
        'mal' => 'https://myanimelist.net/anime/%s',
        'bangumi' => 'https://bgm.tv/subject/%s',
    ];

    /**
     * Upsert one acgsecrets record into anime + related tables.
     *
     * @param array<string, mixed> $record
     */
    public function importRecord(array $record): Anime
    {
        $payloadHash = hash('sha256', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($record, $payloadHash): Anime {
            $anime = $this->resolveAnime($record);

            $anime->fill([
                'name' => (string) $record['title_zh'],
                'description' => $record['summary'] ?? null,
                'image_url' => $record['cover_image'] ?? null,
                'source' => 'acgsecrets',
                'season_year' => $record['season_year'] ?? null,
                'season_code' => $record['season_code'] ?? null,
                'air_date' => $record['air_date'] ?? null,
            ]);
            $anime->save();

            $this->syncTitles($anime, $record);
            $this->syncAliases($anime, $record);
            $this->syncExternalIds($anime, $record, $payloadHash);
            $this->syncStreams($anime, $record);

            return $anime->refresh();
        });
    }

    /**
     * Import a batch of records.
     *
     * @param array<int, array<string, mixed>> $records
     * @return array{imported: int, skipped: int, errors: array<int, array<string, mixed>>}
     */
    public function importSeason(array $records): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($records as $record) {
            if (! is_array($record) || trim((string) ($record['title_zh'] ?? '')) === '') {
                $skipped++;
                $errors[] = [
                    'title_zh' => $record['title_zh'] ?? null,
                    'message' => 'empty title_zh',
                ];

                continue;
            }

            try {
                $this->importRecord($record);
                $imported++;
            } catch (Throwable $exception) {
                $skipped++;
                $errors[] = [
                    'title_zh' => $record['title_zh'] ?? null,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function resolveAnime(array $record): Anime
    {
        $externalIds = $record['external_ids'] ?? [];

        foreach (['bangumi', 'mal'] as $provider) {
            $externalId = $externalIds[$provider] ?? null;
            if ($externalId === null || $externalId === '') {
                continue;
            }

            $existing = AnimeExternalId::query()
                ->where('provider', $provider)
                ->where('external_id', (string) $externalId)
                ->first();

            if ($existing?->anime !== null) {
                return $existing->anime;
            }
        }

        $matched = Anime::query()
            ->where('season_year', $record['season_year'] ?? null)
            ->where('season_code', $record['season_code'] ?? null)
            ->where('name', (string) $record['title_zh'])
            ->first();

        return $matched ?? new Anime();
    }

    /**
     * @param array<string, mixed> $record
     */
    private function syncTitles(Anime $anime, array $record): void
    {
        AnimeTitle::query()
            ->where('anime_id', $anime->id)
            ->update(['is_primary' => false]);

        $titles = [
            'zh-Hant' => [trim((string) ($record['title_zh'] ?? '')), true],
            'ja' => [trim((string) ($record['title_ja'] ?? '')), false],
        ];

        foreach ($titles as $locale => [$title, $isPrimary]) {
            if ($title === '') {
                continue;
            }

            AnimeTitle::query()->updateOrCreate([
                'anime_id' => $anime->id,
                'locale' => $locale,
                'title' => $title,
            ], [
                'is_primary' => $isPrimary,
                'source' => 'acgsecrets',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function syncAliases(Anime $anime, array $record): void
    {
        AnimeAlias::query()->where('anime_id', $anime->id)->delete();

        foreach ($record['aliases'] ?? [] as $alias) {
            $alias = trim((string) $alias);
            if ($alias === '') {
                continue;
            }

            AnimeAlias::query()->create([
                'anime_id' => $anime->id,
                'alias' => $alias,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function syncExternalIds(Anime $anime, array $record, string $payloadHash): void
    {
        $externalIds = $record['external_ids'] ?? [];

        foreach (['mal', 'bangumi'] as $provider) {
            $externalId = $externalIds[$provider] ?? null;
            if ($externalId === null || $externalId === '') {
                continue;
            }

            AnimeExternalId::query()->updateOrCreate([
                'provider' => $provider,
                'external_id' => (string) $externalId,
            ], [
                'anime_id' => $anime->id,
                'url' => sprintf(self::PROVIDER_URLS[$provider], $externalId),
                'last_synced_at' => now(),
                'payload_hash' => $payloadHash,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function syncStreams(Anime $anime, array $record): void
    {
        AnimeStream::query()->where('anime_id', $anime->id)->delete();

        foreach ($record['streams'] ?? [] as $stream) {
            $platform = trim((string) ($stream['platform'] ?? ''));
            if ($platform === '') {
                continue;
            }

            AnimeStream::query()->updateOrCreate([
                'anime_id' => $anime->id,
                'region' => (string) ($stream['region'] ?? ''),
                'platform' => $platform,
            ], [
                'url' => $stream['url'] ?? null,
            ]);
        }
    }
}
