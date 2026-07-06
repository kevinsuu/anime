<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Anime;
use App\Services\AnimeCatalog\SeasonResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class AnimeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $year = $request->query('year');
        $season = trim((string) $request->query('season', ''));
        $term = "%{$query}%";

        if ($year !== null && (! ctype_digit((string) $year) || (int) $year < 1900 || (int) $year > 2100)) {
            throw new ApiException(422, 'validation_failed', '年份格式錯誤');
        }

        if ($season !== '') {
            try {
                SeasonResolver::months($season);
            } catch (InvalidArgumentException) {
                throw new ApiException(422, 'validation_failed', '季度格式錯誤');
            }
        }

        // Year-scoped queries (catalog year browsing, seasonal pages) load
        // the full year/season without a cap. Unscoped keyword search across
        // the whole catalog is capped to avoid an excessive payload.
        $isYearScoped = $year !== null;

        $items = Anime::query()
            ->with([
                'streams:id,anime_id,region,platform,url',
                'aliases:id,anime_id,alias',
                'titles:id,anime_id,locale,title,is_primary',
                'cast:id,anime_id,character,actor,sort_order',
            ])
            ->when($query !== '', function ($builder) use ($term): void {
                $builder->where(function ($where) use ($term): void {
                    $where->where('name', 'like', $term)
                        ->orWhereHas('aliases', fn ($q) => $q->where('alias', 'like', $term))
                        ->orWhereHas('titles', fn ($q) => $q->where('title', 'like', $term));
                });
            })
            ->when($year !== null, fn ($builder) => $builder->where('season_year', (int) $year))
            ->when($season !== '', fn ($builder) => $builder->where('season_code', $season))
            ->orderByRaw('air_date is null')
            ->orderBy('air_date')
            ->orderBy('name')
            ->when(! $isYearScoped, fn ($builder) => $builder->limit(200))
            ->get([
                'id', 'name', 'description', 'image_url', 'cover_image_path', 'source',
                'season_year', 'season_code', 'air_date', 'air_date_text', 'episode_count', 'status', 'tags',
            ]);

        return response()->json([
            'items' => $items->map(fn (Anime $anime) => [
                'id' => $anime->id,
                'name' => $anime->name,
                'description' => $anime->description,
                'image_url' => $anime->image_url,
                'source' => $anime->source,
                'season_year' => $anime->season_year,
                'season_code' => $anime->season_code,
                'air_date' => $anime->air_date,
                'air_date_text' => $anime->air_date_text,
                'episode_count' => $anime->episode_count,
                'status' => $anime->status,
                'tags' => $anime->tags ?? [],
                'cast' => $anime->cast->map(fn ($c) => [
                    'character' => $c->character, 'actor' => $c->actor,
                ])->all(),
                'aliases' => $anime->aliases->pluck('alias')->all(),
                'streams' => $anime->streams->map(fn ($s) => [
                    'region' => $s->region, 'platform' => $s->platform, 'url' => $s->url,
                ])->all(),
                'titles' => $anime->titles->map(fn ($t) => [
                    'locale' => $t->locale, 'title' => $t->title, 'is_primary' => (bool) $t->is_primary,
                ])->all(),
            ]),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $anime = Anime::query()
            ->with([
                'streams:id,anime_id,region,platform,url',
                'aliases:id,anime_id,alias',
                'titles:id,anime_id,locale,title,is_primary',
                'externalIds:id,anime_id,provider,external_id,url',
                'themes:id,anime_id,type,title,artist,sort_order',
                'trailers:id,anime_id,url,thumbnail,sort_order',
                'cast:id,anime_id,character,actor,sort_order',
                'staffMembers:id,anime_id,role,name,sort_order',
                'links:id,anime_id,category,label,url',
            ])
            ->findOrFail($id);

        return response()->json([
            'item' => [
                'id' => $anime->id,
                'name' => $anime->name,
                'description' => $anime->description,
                'image_url' => $anime->image_url,
                'source' => $anime->source,
                'season_year' => $anime->season_year,
                'season_code' => $anime->season_code,
                'air_date' => $anime->air_date,
                'air_date_text' => $anime->air_date_text,
                'episode_count' => $anime->episode_count,
                'status' => $anime->status,
                'tags' => $anime->tags ?? [],
                'aliases' => $anime->aliases->pluck('alias')->all(),
                'streams' => $anime->streams->map(fn ($s) => [
                    'region' => $s->region, 'platform' => $s->platform, 'url' => $s->url,
                ])->all(),
                'titles' => $anime->titles->map(fn ($t) => [
                    'locale' => $t->locale, 'title' => $t->title, 'is_primary' => (bool) $t->is_primary,
                ])->all(),
                'external_ids' => $anime->externalIds->map(fn ($e) => [
                    'provider' => $e->provider, 'external_id' => $e->external_id, 'url' => $e->url,
                ])->all(),
                'themes' => $anime->themes->map(fn ($t) => [
                    'type' => $t->type, 'title' => $t->title, 'artist' => $t->artist,
                ])->all(),
                'trailers' => $anime->trailers->map(fn ($t) => [
                    'url' => $t->url, 'thumbnail' => $t->thumbnail,
                ])->all(),
                'cast' => $anime->cast->map(fn ($c) => [
                    'character' => $c->character, 'actor' => $c->actor,
                ])->all(),
                'staff' => $anime->staffMembers->map(fn ($s) => [
                    'role' => $s->role, 'name' => $s->name,
                ])->all(),
                'links' => $anime->links->map(fn ($l) => [
                    'category' => $l->category, 'label' => $l->label, 'url' => $l->url,
                ])->all(),
            ],
        ]);
    }

}
