<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserAnimeListItem;
use App\Services\Shared\DelimitedValues;
use App\Services\Shared\GenreTagStatistics;
use App\Services\Shared\SlugGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AnimeListController extends Controller
{
    private const PAGE_SIZE = 50;

    public function me(Request $request): JsonResponse
    {
        $user = User::query()->find((int) $request->attributes->get('auth_user_id'));
        if ($user === null) {
            throw new ApiException(404, 'user_not_found', '找不到使用者');
        }

        return response()->json(['user' => $user]);
    }

    public function index(Request $request): JsonResponse
    {
        $tags = DelimitedValues::parse((string) $request->query('tags', ''));
        $page = $this->positiveQueryInteger($request, 'page', 1);
        $status = $this->queryString($request, 'status', 'all');
        $sort = $this->queryString($request, 'sort', 'airDate');
        $search = trim($this->queryString($request, 'q', ''));
        $collectionId = $request->query->has('collection_id')
            ? $this->positiveQueryInteger($request, 'collection_id')
            : null;

        if (! in_array($status, ['all', 'watched', 'unwatched'], true)) {
            throw new ApiException(422, 'validation_failed', '觀看狀態格式錯誤');
        }
        if (! in_array($sort, ['airDate', 'year', 'added'], true)) {
            throw new ApiException(422, 'validation_failed', '排序方式格式錯誤');
        }
        if (mb_strlen($search) > 200) {
            throw new ApiException(422, 'validation_failed', '搜尋文字不可超過 200 字');
        }

        $query = UserAnimeListItem::query()
            ->select('user_anime_list_items.*')
            ->join('anime', 'anime.id', '=', 'user_anime_list_items.anime_id')
            ->with($this->itemRelations())
            ->where('user_anime_list_items.user_id', (int) $request->attributes->get('auth_user_id'))
            ->when($status === 'watched', fn ($builder) => $builder->where('user_anime_list_items.watched', true))
            ->when($status === 'unwatched', fn ($builder) => $builder->where('user_anime_list_items.watched', false))
            ->when($collectionId !== null, function ($builder) use ($collectionId): void {
                $builder->whereHas('collections', fn ($collection) => $collection
                    ->where('user_collections.id', $collectionId));
            })
            ->when($tags !== [], function ($builder) use ($tags): void {
                $builder->where(function ($tagQuery) use ($tags): void {
                    foreach ($tags as $tag) {
                        $tagQuery->orWhereJsonContains('anime.tags', $tag);
                    }
                });
            })
            ->when($search !== '', function ($builder) use ($search): void {
                $builder->where(function ($searchQuery) use ($search): void {
                    $searchQuery
                        ->where('anime.name', 'like', "%{$search}%")
                        ->orWhereHas('anime.aliases', fn ($aliases) => $aliases->where('alias', 'like', "%{$search}%"))
                        ->orWhereHas('anime.titles', fn ($titles) => $titles->where('title', 'like', "%{$search}%"));
                });
            });

        $total = (clone $query)->count('user_anime_list_items.id');
        $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE));
        $this->applySort($query, $sort);
        $items = $page > $lastPage
            ? collect()
            : $query
                ->forPage($page, self::PAGE_SIZE)
                ->get()
                ->map(fn (UserAnimeListItem $item): array => $this->formatItem($item));

        return response()->json([
            'items' => $items->all(),
            'meta' => [
                'page' => $page,
                'per_page' => self::PAGE_SIZE,
                'total' => $total,
                'last_page' => $lastPage,
                'has_more' => $page < $lastPage,
            ],
        ]);
    }

    public function counts(Request $request): JsonResponse
    {
        $totals = UserAnimeListItem::query()
            ->where('user_id', (int) $request->attributes->get('auth_user_id'))
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN watched = 1 THEN 1 ELSE 0 END), 0) AS watched_count')
            ->first();
        $all = (int) ($totals?->total ?? 0);
        $watched = (int) ($totals?->watched_count ?? 0);

        return response()->json([
            'counts' => [
                'all' => $all,
                'watched' => $watched,
                'unwatched' => $all - $watched,
            ],
        ]);
    }

    public function tags(Request $request, GenreTagStatistics $statistics): JsonResponse
    {
        $userId = (int) $request->attributes->get('auth_user_id');

        $tagSets = UserAnimeListItem::query()
            ->where('user_id', $userId)
            ->with('anime:id,tags')
            ->get()
            ->map(fn (UserAnimeListItem $item): array => $item->anime->tags ?? []);

        return response()->json(['tags' => $statistics->summarize($tagSets)]);
    }

    public function store(Request $request): JsonResponse
    {
        $animeId = (int) $request->input('animeId', 0);
        if ($animeId <= 0) {
            throw new ApiException(422, 'validation_failed', '缺少動漫 ID', ['animeId' => 'required']);
        }

        try {
            $item = UserAnimeListItem::query()->create([
                'user_id' => (int) $request->attributes->get('auth_user_id'),
                'anime_id' => $animeId,
                'watched' => false,
            ]);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23000') {
                throw new ApiException(409, 'already_in_list', '此動漫已在你的清單中');
            }

            throw $exception;
        }

        return response()->json(['item' => $this->formatItem($item->load($this->itemRelations()))], 201);
    }

    public function update(Request $request, int $item): JsonResponse
    {
        $listItem = UserAnimeListItem::query()
            ->where('id', $item)
            ->where('user_id', (int) $request->attributes->get('auth_user_id'))
            ->first();

        if ($listItem === null) {
            throw new ApiException(404, 'list_item_not_found', '找不到清單項目');
        }

        $updates = [];
        if ($request->has('watched')) {
            $updates['watched'] = filter_var($request->input('watched'), FILTER_VALIDATE_BOOL);
        }

        if ($request->has('rating')) {
            $rating = $request->input('rating');
            if ($rating !== null && (! is_int($rating) || $rating < 1 || $rating > 10)) {
                throw new ApiException(422, 'validation_failed', '評價必須是 1 到 10 或空值', ['rating' => 'range']);
            }
            $updates['rating'] = $rating;
        }

        if ($request->has('note')) {
            $updates['note'] = trim((string) $request->input('note'));
        }

        if ($updates === []) {
            throw new ApiException(422, 'validation_failed', '沒有可更新的欄位');
        }

        $listItem->update($updates);

        return response()->json(['item' => $this->formatItem($listItem->fresh()->load($this->itemRelations()))]);
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $deleted = UserAnimeListItem::query()
            ->where('id', $item)
            ->where('user_id', (int) $request->attributes->get('auth_user_id'))
            ->delete();

        if ($deleted === 0) {
            throw new ApiException(404, 'list_item_not_found', '找不到清單項目');
        }

        return response()->json(['ok' => true]);
    }

    public function publicList(string $slug): JsonResponse
    {
        $user = User::query()
            ->select(['id', 'display_name', 'avatar_url', 'public_slug'])
            ->where('public_slug', $slug)
            ->first();

        if ($user === null) {
            throw new ApiException(404, 'public_list_not_found', '找不到公開清單');
        }

        return response()->json([
            'user' => $user,
            'items' => $this->listForUser((int) $user->id),
        ]);
    }

    public function regenerateSlug(Request $request, SlugGenerator $slugs): JsonResponse
    {
        $user = User::query()->find((int) $request->attributes->get('auth_user_id'));
        if ($user === null) {
            throw new ApiException(404, 'user_not_found', '找不到使用者');
        }

        $user->update(['public_slug' => $slugs->uniqueUserSlug()]);

        return response()->json(['user' => $user->fresh()]);
    }

    private function listForUser(int $userId, array $tags = []): array
    {
        return UserAnimeListItem::query()
            ->with($this->itemRelations())
            ->where('user_id', $userId)
            ->when($tags !== [], function ($query) use ($tags): void {
                $query->whereHas('anime', function ($q) use ($tags): void {
                    $q->where(function ($q2) use ($tags): void {
                        foreach ($tags as $tag) {
                            $q2->orWhereJsonContains('tags', $tag);
                        }
                    });
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (UserAnimeListItem $item): array => $this->formatItem($item))
            ->all();
    }

    private function applySort(Builder $query, string $sort): void
    {
        if ($sort === 'added') {
            $query->orderByDesc('user_anime_list_items.created_at');
        } elseif ($sort === 'year') {
            $query->orderByDesc('anime.season_year')
                ->orderByDesc('anime.air_date');
        } else {
            $query->orderByDesc('anime.air_date');
        }

        $query->orderByDesc('user_anime_list_items.id');
    }

    private function positiveQueryInteger(Request $request, string $key, int $default = 0): int
    {
        $value = $request->query($key, (string) $default);
        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
            throw new ApiException(422, 'validation_failed', "{$key} 格式錯誤");
        }

        return (int) $value;
    }

    private function queryString(Request $request, string $key, string $default): string
    {
        $value = $request->query($key, $default);
        if (! is_string($value)) {
            throw new ApiException(422, 'validation_failed', "{$key} 格式錯誤");
        }

        return $value;
    }

    private function formatItem(UserAnimeListItem $item): array
    {
        return [
            'id' => $item->id,
            'watched' => (bool) $item->watched,
            'rating' => $item->rating === null ? null : (int) $item->rating,
            'note' => $item->note,
            'createdAt' => $item->created_at?->toDateTimeString(),
            'updatedAt' => $item->updated_at?->toDateTimeString(),
            'collections' => $item->relationLoaded('collections')
                ? $item->collections->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all()
                : [],
            'anime' => [
                'id' => $item->anime->id,
                'name' => $item->anime->name,
                'description' => $item->anime->description,
                'imageUrl' => $item->anime->image_url,
                'tags' => $item->anime->tags ?? [],
                'season_year' => $item->anime->season_year,
                'air_date' => $item->anime->air_date,
                'aliases' => $item->anime->aliases->pluck('alias')->all(),
                'titles' => $item->anime->titles->map(fn ($title): array => [
                    'locale' => $title->locale,
                    'title' => $title->title,
                    'is_primary' => (bool) $title->is_primary,
                ])->all(),
            ],
        ];
    }

    /** @return array<int, string> */
    private function itemRelations(): array
    {
        return [
            'anime:id,name,description,image_url,cover_image_path,tags,season_year,air_date',
            'anime.aliases:anime_id,alias',
            'anime.titles:anime_id,locale,title,is_primary',
            'collections:id,name',
        ];
    }
}
