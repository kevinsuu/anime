<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\UserAnimeListItem;
use App\Models\UserCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CollectionController extends Controller
{
    /** List all collections for the authed user. */
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->attributes->get('auth_user_id');

        $collections = UserCollection::query()
            ->where('user_id', $userId)
            ->withCount('listItems')
            ->orderBy('name')
            ->get();

        return response()->json([
            'items' => $collections->map(fn (UserCollection $c) => $this->format($c)),
        ]);
    }

    /** Create a new collection. */
    public function store(Request $request): JsonResponse
    {
        $userId = (int) $request->attributes->get('auth_user_id');
        $name = trim((string) $request->input('name', ''));

        if ($name === '' || mb_strlen($name) > 80) {
            throw new ApiException(422, 'validation_failed', '清單名稱不可為空且不超過 80 字');
        }

        $exists = UserCollection::query()
            ->where('user_id', $userId)
            ->where('name', $name)
            ->exists();

        if ($exists) {
            throw new ApiException(409, 'duplicate', '已有同名清單');
        }

        $collection = UserCollection::query()->create([
            'user_id' => $userId,
            'name' => $name,
            'is_public' => (bool) $request->input('is_public', false),
            'public_slug' => Str::random(12),
        ]);

        $collection->loadCount('listItems');

        return response()->json(['item' => $this->format($collection)], 201);
    }

    /** Rename or toggle public. */
    public function update(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->attributes->get('auth_user_id');
        $collection = $this->findOwned($userId, $id);

        if ($request->has('name')) {
            $name = trim((string) $request->input('name'));
            if ($name === '' || mb_strlen($name) > 80) {
                throw new ApiException(422, 'validation_failed', '清單名稱不可為空且不超過 80 字');
            }
            $collection->name = $name;
        }

        if ($request->has('is_public')) {
            $collection->is_public = (bool) $request->input('is_public');
        }

        $collection->save();
        $collection->loadCount('listItems');

        return response()->json(['item' => $this->format($collection)]);
    }

    /** Delete a collection. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->attributes->get('auth_user_id');
        $this->findOwned($userId, $id)->delete();

        return response()->json(['ok' => true]);
    }

    /** Add a list item to a collection. */
    public function addItem(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->attributes->get('auth_user_id');
        $collection = $this->findOwned($userId, $id);

        $listItemId = (int) $request->input('list_item_id');
        $listItem = UserAnimeListItem::query()
            ->where('id', $listItemId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $collection->listItems()->syncWithoutDetaching([$listItem->id]);
        $collection->loadCount('listItems');

        return response()->json(['item' => $this->format($collection)]);
    }

    /** Remove a list item from a collection. */
    public function removeItem(Request $request, int $id, int $listItemId): JsonResponse
    {
        $userId = (int) $request->attributes->get('auth_user_id');
        $collection = $this->findOwned($userId, $id);

        $collection->listItems()->detach($listItemId);
        $collection->loadCount('listItems');

        return response()->json(['item' => $this->format($collection)]);
    }

    /** Public view of a collection by slug. */
    public function publicShow(string $slug): JsonResponse
    {
        $collection = UserCollection::query()
            ->where('public_slug', $slug)
            ->where('is_public', true)
            ->with(['listItems.anime'])
            ->withCount('listItems')
            ->firstOrFail();

        return response()->json([
            'item' => [
                ...$this->format($collection),
                'list_items' => $collection->listItems->map(fn ($li) => [
                    'id' => $li->id,
                    'watched' => $li->watched,
                    'rating' => $li->rating,
                    'anime' => [
                        'id' => $li->anime->id,
                        'name' => $li->anime->name,
                        'image_url' => $li->anime->image_url,
                        'season_year' => $li->anime->season_year,
                        'season_code' => $li->anime->season_code,
                    ],
                ])->all(),
            ],
        ]);
    }

    private function findOwned(int $userId, int $id): UserCollection
    {
        $collection = UserCollection::query()->find($id);
        if (! $collection || $collection->user_id !== $userId) {
            throw new ApiException(404, 'not_found', '找不到清單');
        }

        return $collection;
    }

    private function format(UserCollection $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'is_public' => $c->is_public,
            'public_slug' => $c->public_slug,
            'count' => $c->list_items_count ?? 0,
        ];
    }
}
