<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnimeResource;
use App\Models\Anime;
use App\Services\AnimeCatalog\AnimeSearchCriteriaParser;
use App\Services\AnimeCatalog\AnimeSearchQuery;
use App\Services\Shared\GenreTagStatistics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class AnimeController extends Controller
{
    public function __construct(
        private readonly AnimeSearchCriteriaParser $searchCriteria,
        private readonly AnimeSearchQuery $searchQuery,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $criteria = $this->searchCriteria->parse($request);

        // Year-scoped queries (catalog year browsing, seasonal pages) load
        // the full year/season without a cap. Unscoped keyword search across
        // the whole catalog is capped to avoid an excessive payload.
        $isYearScoped = $criteria->year !== null;
        // Preserve the legacy catalog behavior: tags narrow the current recent
        // catalog but do not switch it to oldest-first ordering or the 200 cap.
        $isRecentMode = $criteria->year === null
            && $criteria->season === ''
            && $criteria->query === '';

        $items = $this->searchQuery->build($criteria, $isRecentMode)
            ->with([
                'streams:id,anime_id,region,platform,url',
                'aliases:id,anime_id,alias',
                'titles:id,anime_id,locale,title,is_primary',
                'cast:id,anime_id,character,actor,sort_order',
            ])
            ->when($isRecentMode, fn ($builder) => $builder->limit(50))
            ->when(! $isRecentMode && ! $isYearScoped, fn ($builder) => $builder->limit(200))
            ->get([
                'id', 'name', 'description', 'image_url', 'cover_image_path', 'source',
                'season_year', 'season_code', 'air_date', 'air_date_text', 'episode_count', 'status', 'tags',
            ]);

        return response()->json([
            'items' => AnimeResource::collection($items)->resolve(),
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
            'item' => (new AnimeResource($anime))->resolve(),
        ]);
    }

    public function tags(GenreTagStatistics $statistics): JsonResponse
    {
        $cacheKey = 'anime:tags:v1';
        $resolve = fn (): array => Cache::flexible($cacheKey, [240, 300], function () use ($statistics): array {
            $tagSets = Anime::query()
                ->select(['id', 'tags'])
                ->get()
                ->map(fn (Anime $anime): array => $anime->tags ?? []);

            return ['tags' => $statistics->summarize($tagSets)];
        }, ['seconds' => 15]);
        $payload = Cache::has($cacheKey)
            ? $resolve()
            : Cache::lock("anime:tags:cold:{$cacheKey}", 15)->block(15, $resolve);

        return response()->json($payload);
    }
}
