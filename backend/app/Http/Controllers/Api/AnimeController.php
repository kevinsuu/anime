<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Anime;
use App\Services\AnimeCatalog\AnimeImportService;
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
            ->select([
                'anime.id',
                'anime.name',
                'anime.description',
                'anime.image_url',
                'anime.source',
                'anime.season_year',
                'anime.season_code',
                'anime.air_date',
                'anime.episode_count',
                'anime.status',
            ])
            ->leftJoin('anime_aliases', 'anime_aliases.anime_id', '=', 'anime.id')
            ->leftJoin('anime_titles', 'anime_titles.anime_id', '=', 'anime.id')
            ->when($query !== '', function ($builder) use ($term): void {
                $builder->where(function ($where) use ($term): void {
                    $where->where('anime.name', 'like', $term)
                        ->orWhere('anime_aliases.alias', 'like', $term)
                        ->orWhere('anime_titles.title', 'like', $term);
                });
            })
            ->when($year !== null, fn ($builder) => $builder->where('anime.season_year', (int) $year))
            ->when($season !== '', fn ($builder) => $builder->where('anime.season_code', $season))
            ->distinct()
            ->orderByRaw('anime.air_date is null')
            ->orderBy('anime.air_date')
            ->orderBy('anime.name')
            ->limit(50)
            ->get();

        return response()->json(['items' => $items]);
    }

    public function syncSeasonal(Request $request, AnimeImportService $service): JsonResponse
    {
        $currentSeason = SeasonResolver::current(new \DateTimeImmutable('now'));

        $validator = Validator::make($request->all(), [
            'year' => ['nullable', 'integer', 'between:1900,2100'],
            'season' => ['nullable', 'string', 'in:winter,spring,summer,fall'],
        ], [
            'year.integer' => '年份格式錯誤',
            'year.between' => '年份必須介於 1900 到 2100',
            'season.in' => '季度格式錯誤',
        ]);

        if ($validator->fails()) {
            throw new ApiException(422, 'validation_failed', '同步參數驗證失敗', $validator->errors()->toArray());
        }

        $year = (int) ($request->input('year') ?: $currentSeason['year']);
        $season = (string) ($request->input('season') ?: $currentSeason['code']);

        try {
            $result = $service->syncBangumiSeason($year, $season);
        } catch (\Throwable $exception) {
            throw new ApiException(502, 'anime_sync_failed', $exception->getMessage());
        }

        return response()->json(['result' => $result]);
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
