<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AnimeSummaryResource;
use App\Services\AnimeCatalog\AnimeSearchCriteria;
use App\Services\AnimeCatalog\AnimeSearchCriteriaParser;
use App\Services\AnimeCatalog\AnimeSearchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class AnimeSummaryController extends Controller
{
    private const DEFAULT_PER_PAGE = 40;

    private const MAX_PER_PAGE = 100;

    private const RECENT_LIMIT = 50;

    public function __construct(
        private readonly AnimeSearchCriteriaParser $searchCriteria,
        private readonly AnimeSearchQuery $searchQuery,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $criteria = $this->searchCriteria->parse($request, enforceSizeLimits: true);
        $pageInput = $this->queryString($request, 'page', '1');
        $perPageInput = $this->queryString($request, 'per_page', (string) self::DEFAULT_PER_PAGE);

        if (! ctype_digit((string) $pageInput) || (int) $pageInput < 1) {
            throw new ApiException(422, 'validation_failed', '頁碼格式錯誤');
        }

        if (! ctype_digit((string) $perPageInput)
            || (int) $perPageInput < 1
            || (int) $perPageInput > self::MAX_PER_PAGE) {
            throw new ApiException(422, 'validation_failed', '每頁筆數必須介於 1 到 100');
        }

        $page = (int) $pageInput;
        $perPage = (int) $perPageInput;

        $cacheKey = $this->cacheKey($criteria, $page, $perPage);
        $resolve = fn (): array => Cache::flexible(
            $cacheKey,
            [240, 300],
            fn (): array => $this->summaries($criteria, $page, $perPage),
            ['seconds' => 15],
        );
        $payload = Cache::has($cacheKey)
            ? $resolve()
            : Cache::lock("anime:summaries:cold:{$cacheKey}", 15)->block(15, $resolve);

        return response()->json(
            $payload,
            200,
            [],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function queryString(Request $request, string $key, ?string $default = null): ?string
    {
        $value = $request->query($key, $default);

        if ($value !== null && ! is_string($value)) {
            throw new ApiException(422, 'validation_failed', "{$key} 格式錯誤");
        }

        return $value;
    }

    /**
     * @return array{items: array<int, mixed>, meta: array{page: int, per_page: int, total: int, last_page: int, has_more: bool}}
     */
    private function summaries(
        AnimeSearchCriteria $criteria,
        int $page,
        int $perPage,
    ): array {
        $builder = $this->searchQuery->build($criteria);

        $total = (clone $builder)->count();
        if ($criteria->isRecentMode()) {
            $total = min($total, self::RECENT_LIMIT);
        }

        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = $page > $lastPage ? 0 : ($page - 1) * $perPage;
        $limit = $page > $lastPage ? 0 : min($perPage, max(0, $total - $offset));

        $items = $limit === 0
            ? collect()
            : (clone $builder)
                ->select([
                    'id',
                    'name',
                    'image_url',
                    'cover_image_path',
                    'season_year',
                    'season_code',
                    'air_date',
                    'air_date_text',
                    'episode_count',
                    'tags',
                ])
                ->withCount('streams')
                ->with(['cast' => fn ($castQuery) => $castQuery
                    ->select(['id', 'anime_id', 'actor', 'sort_order'])
                    ->orderBy('id')])
                ->skip($offset)
                ->take($limit)
                ->get();

        return [
            'items' => AnimeSummaryResource::collection($items)->resolve(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'has_more' => $page < $lastPage,
            ],
        ];
    }

    private function cacheKey(
        AnimeSearchCriteria $criteria,
        int $page,
        int $perPage,
    ): string {
        $parameters = [
            ...$criteria->toArray(),
            'page' => $page,
            'per_page' => $perPage,
        ];

        return 'anime:summaries:v1:'.hash(
            'sha256',
            (string) json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
