<?php

namespace App\Services\AnimeCatalog;

use App\Models\Anime;
use App\Models\AnimeExternalId;
use App\Models\User;
use App\Models\UserAnimeListItem;
use App\Services\Shared\SlugGenerator;

/**
 * 讀取 database/seed/mylist/watched.json,把清單中的動畫標記為
 * MYLIST_OWNER_EMAIL 使用者的「已看過」。冪等:已存在的清單項目
 * 不會被覆寫(保留使用者手動調整的評分/備註/狀態)。
 *
 * 使用者尚未用 Google 登入過時,會以 `seed:<email>` 佔位 google_sub
 * 預先建立帳號;首次 Google 登入時由 AuthController 依 email 認領。
 */
final class WatchedManifestImporter
{
    private const SEASON_CODES = [
        '01' => 'winter',
        '04' => 'spring',
        '07' => 'summer',
        '10' => 'fall',
    ];

    public function __construct(private readonly SlugGenerator $slugs)
    {
    }

    /**
     * @return array{skipped: bool, marked: int, existing: int, missing: array<int, string>}
     */
    public function sync(): array
    {
        $email = trim((string) config('services.mylist.owner_email'));
        $path = database_path('seed/mylist/watched.json');

        if ($email === '' || ! is_file($path)) {
            return ['skipped' => true, 'marked' => 0, 'existing' => 0, 'missing' => []];
        }

        $entries = json_decode((string) file_get_contents($path), true);
        if (! is_array($entries)) {
            return ['skipped' => true, 'marked' => 0, 'existing' => 0, 'missing' => []];
        }

        $user = $this->resolveOwner($email);

        $marked = 0;
        $existing = 0;
        $missing = [];

        foreach ($entries as $entry) {
            $animeId = $this->resolveAnimeId($entry);
            if ($animeId === null) {
                $missing[] = (string) ($entry['name'] ?? $entry['bangumi'] ?? 'unknown');

                continue;
            }

            $item = UserAnimeListItem::query()->firstOrCreate([
                'user_id' => $user->id,
                'anime_id' => $animeId,
            ], [
                'watched' => true,
            ]);

            $item->wasRecentlyCreated ? $marked++ : $existing++;
        }

        return ['skipped' => false, 'marked' => $marked, 'existing' => $existing, 'missing' => $missing];
    }

    private function resolveOwner(string $email): User
    {
        $user = User::query()->where('email', $email)->first();
        if ($user !== null) {
            return $user;
        }

        return User::query()->create([
            'google_sub' => 'seed:'.$email,
            'email' => $email,
            'display_name' => null,
            'avatar_url' => null,
            'public_slug' => $this->slugs->uniqueUserSlug(),
        ]);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function resolveAnimeId(array $entry): ?int
    {
        $bangumi = $entry['bangumi'] ?? null;
        if ($bangumi !== null && $bangumi !== '') {
            $external = AnimeExternalId::query()
                ->where('provider', 'bangumi')
                ->where('external_id', (string) $bangumi)
                ->first();

            if ($external !== null) {
                return (int) $external->anime_id;
            }
        }

        $season = (string) ($entry['season'] ?? '');
        $name = (string) ($entry['name'] ?? '');
        if (strlen($season) !== 6 || $name === '') {
            return null;
        }

        $code = self::SEASON_CODES[substr($season, 4, 2)] ?? null;
        if ($code === null) {
            return null;
        }

        $anime = Anime::query()
            ->where('season_year', (int) substr($season, 0, 4))
            ->where('season_code', $code)
            ->where('name', $name)
            ->first();

        if ($anime !== null) {
            return (int) $anime->id;
        }

        // 跨季度連播的作品在最後一個季度檔覆蓋 season 欄位,
        // 與 manifest 記錄的首播季不符 — 名稱全站唯一時仍可安全命中。
        $byName = Anime::query()->where('name', $name)->limit(2)->get();

        return $byName->count() === 1 ? (int) $byName->first()->id : null;
    }
}
