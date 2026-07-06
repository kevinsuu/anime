<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserAnimeListItem;
use App\Services\Shared\GenreTags;
use App\Services\Shared\SlugGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AnimeListController extends Controller
{
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
        $tags = array_values(array_filter(
            array_map('trim', explode(',', (string) $request->query('tags', ''))),
            fn (string $t): bool => $t !== ''
        ));

        return response()->json([
            'items' => $this->listForUser((int) $request->attributes->get('auth_user_id'), $tags),
        ]);
    }

    public function tags(Request $request): JsonResponse
    {
        $userId = (int) $request->attributes->get('auth_user_id');

        $counts = [];
        UserAnimeListItem::query()
            ->where('user_id', $userId)
            ->with('anime:id,tags')
            ->get()
            ->each(function (UserAnimeListItem $item) use (&$counts): void {
                foreach ($item->anime->tags ?? [] as $tag) {
                    if (! GenreTags::isGenreTag($tag)) {
                        continue;
                    }
                    $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                }
            });

        $tags = collect($counts)
            ->map(fn (int $count, string $tag) => ['tag' => $tag, 'count' => $count])
            ->values()
            ->sortBy([['count', 'desc'], ['tag', 'asc']])
            ->values()
            ->all();

        return response()->json(['tags' => $tags]);
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

        return response()->json(['item' => $this->formatItem($item->load('anime'))], 201);
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

        return response()->json(['item' => $this->formatItem($listItem->fresh()->load('anime'))]);
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
            ->with(['anime', 'collections:id,name'])
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
            ],
        ];
    }
}
