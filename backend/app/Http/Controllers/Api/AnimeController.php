<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Anime;
use App\Services\AnimeCatalog\SeasonResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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

        $items = Anime::query()
            ->with([
                'streams:id,anime_id,region,platform,url',
                'aliases:id,anime_id,alias',
                'titles:id,anime_id,locale,title,is_primary',
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
            ->limit(50)
            ->get([
                'id', 'name', 'description', 'image_url', 'source',
                'season_year', 'season_code', 'air_date', 'episode_count', 'status',
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
                'episode_count' => $anime->episode_count,
                'status' => $anime->status,
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

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'imageUrl' => ['nullable', 'url', 'starts_with:http://,https://'],
        ], [
            'name.required' => '名稱必填且不可超過 160 字',
            'name.max' => '名稱必填且不可超過 160 字',
            'imageUrl.url' => '圖片 URL 格式錯誤',
            'imageUrl.starts_with' => '圖片 URL 必須使用 HTTP 或 HTTPS',
        ]);

        if ($validator->fails()) {
            throw new ApiException(422, 'validation_failed', '動漫資料驗證失敗', $validator->errors()->toArray());
        }

        $anime = Anime::query()->create([
            'name' => trim((string) $request->input('name')),
            'description' => trim((string) $request->input('description', '')),
            'image_url' => trim((string) $request->input('imageUrl', '')),
            'source' => 'manual',
            'created_by_user_id' => (int) $request->attributes->get('auth_user_id'),
        ]);

        return response()->json(['item' => $anime->fresh()], 201);
    }
}
