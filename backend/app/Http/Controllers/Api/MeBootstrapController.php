<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserAnimeListItem;
use App\Models\UserCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeBootstrapController extends Controller
{
    private const MAX_ANIME_IDS = 100;

    public function __invoke(Request $request): JsonResponse
    {
        $animeIds = $this->animeIds($request);
        $userId = (int) $request->attributes->get('auth_user_id');

        $user = User::query()
            ->select(['id', 'email', 'display_name', 'avatar_url', 'public_slug'])
            ->find($userId);

        if ($user === null) {
            throw new ApiException(404, 'user_not_found', '找不到使用者');
        }

        $collections = UserCollection::query()
            ->select(['id', 'user_id', 'name', 'is_public', 'public_slug'])
            ->where('user_id', $userId)
            ->withCount([
                'listItems' => fn ($query) => $query->where('user_anime_list_items.user_id', $userId),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $itemsByAnimeId = $animeIds === []
            ? collect()
            : UserAnimeListItem::query()
                ->select(['id', 'user_id', 'anime_id', 'watched'])
                ->where('user_id', $userId)
                ->whereIn('anime_id', $animeIds)
                ->with([
                    'collections' => fn ($query) => $query
                        ->select(['user_collections.id'])
                        ->where('user_collections.user_id', $userId)
                        ->orderBy('user_collections.id'),
                ])
                ->get()
                ->keyBy('anime_id');

        $statuses = $itemsByAnimeId
            ->sortBy(fn (UserAnimeListItem $item): int => (int) $item->anime_id)
            ->values()
            ->map(fn (UserAnimeListItem $item): array => [
                'anime_id' => (int) $item->anime_id,
                'list_item_id' => (int) $item->id,
                'watched' => (bool) $item->watched,
                'collection_ids' => $item->collections
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->sort()
                    ->values()
                    ->all(),
            ])
            ->all();

        $response = response()->json([
            'user' => $user,
            'statuses' => $statuses,
            'collections' => $collections
                ->map(fn (UserCollection $collection): array => [
                    'id' => (int) $collection->id,
                    'name' => $collection->name,
                    'is_public' => (bool) $collection->is_public,
                    'public_slug' => $collection->public_slug,
                    'count' => (int) $collection->list_items_count,
                ])
                ->all(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** @return array<int, int> */
    private function animeIds(Request $request): array
    {
        if (! $request->query->has('anime_ids')) {
            return [];
        }

        $value = $request->query('anime_ids');
        if (! is_string($value) || trim($value) === '') {
            throw $this->invalidAnimeIds();
        }

        $ids = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            $id = filter_var($part, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);

            if ($id === false) {
                throw $this->invalidAnimeIds();
            }

            $ids[(int) $id] = (int) $id;
        }

        if (count($ids) > self::MAX_ANIME_IDS) {
            throw new ApiException(
                422,
                'validation_failed',
                'anime_ids 最多可指定 100 個',
                ['anime_ids' => 'max:100'],
            );
        }

        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function invalidAnimeIds(): ApiException
    {
        return new ApiException(
            422,
            'validation_failed',
            'anime_ids 必須是以逗號分隔的正整數',
            ['anime_ids' => 'comma_separated_positive_integers'],
        );
    }
}
