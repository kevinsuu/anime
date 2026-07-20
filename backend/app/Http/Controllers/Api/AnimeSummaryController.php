<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AnimeSummaryResource;
use App\Models\Anime;
use App\Services\AnimeCatalog\SeasonResolver;
use App\Services\Shared\DelimitedValues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class AnimeSummaryController extends Controller
{
    private const DEFAULT_PER_PAGE = 40;

    private const MAX_PER_PAGE = 100;

    private const RECENT_LIMIT = 50;

    private const MAX_QUERY_LENGTH = 100;

    private const MAX_TAG_COUNT = 10;

    private const MAX_TAG_LENGTH = 50;

    public function index(Request $request): JsonResponse
    {
        $query = trim($this->queryString($request, 'q', ''));
        $yearInput = $this->queryString($request, 'year');
        $season = trim($this->queryString($request, 'season', ''));
        $pageInput = $this->queryString($request, 'page', '1');
        $perPageInput = $this->queryString($request, 'per_page', (string) self::DEFAULT_PER_PAGE);
        $tagsInput = $this->queryString($request, 'tags', '');

        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            throw new ApiException(422, 'validation_failed', '搜尋文字不可超過 100 個字元');
        }

        if ($yearInput !== null && (! ctype_digit((string) $yearInput) || (int) $yearInput < 1900 || (int) $yearInput > 2100)) {
            throw new ApiException(422, 'validation_failed', '年份格式錯誤');
        }

        if ($season !== '') {
            try {
                SeasonResolver::months($season);
            } catch (InvalidArgumentException) {
                throw new ApiException(422, 'validation_failed', '季度格式錯誤');
            }
        }

        if (! ctype_digit((string) $pageInput) || (int) $pageInput < 1) {
            throw new ApiException(422, 'validation_failed', '頁碼格式錯誤');
        }

        if (! ctype_digit((string) $perPageInput)
            || (int) $perPageInput < 1
            || (int) $perPageInput > self::MAX_PER_PAGE) {
            throw new ApiException(422, 'validation_failed', '每頁筆數必須介於 1 到 100');
        }

        $year = $yearInput === null ? null : (int) $yearInput;
        $page = (int) $pageInput;
        $perPage = (int) $perPageInput;
        $tags = array_values(array_unique(DelimitedValues::parse($tagsInput)));
        if (count($tags) > self::MAX_TAG_COUNT
            || collect($tags)->contains(fn (string $tag): bool => mb_strlen($tag) > self::MAX_TAG_LENGTH)) {
            throw new ApiException(422, 'validation_failed', '分類最多 10 個，且每個不可超過 50 個字元');
        }
        sort($tags, SORT_STRING);

        $cacheKey = $this->cacheKey($query, $year, $season, $tags, $page, $perPage);
        $resolve = fn (): array => Cache::flexible(
            $cacheKey,
            [240, 300],
            fn (): array => $this->summaries($query, $year, $season, $tags, $page, $perPage),
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
     * @param  array<int, string>  $tags
     * @return array{items: array<int, mixed>, meta: array{page: int, per_page: int, total: int, last_page: int, has_more: bool}}
     */
    private function summaries(
        string $query,
        ?int $year,
        string $season,
        array $tags,
        int $page,
        int $perPage,
    ): array {
        $term = "%{$query}%";
        $isRecentMode = $year === null && $season === '' && $query === '' && $tags === [];

        $builder = Anime::query()
            ->when($query !== '', function (Builder $builder) use ($term): void {
                $builder->where(function (Builder $where) use ($term): void {
                    $where->where('name', 'like', $term)
                        ->orWhereHas('aliases', fn (Builder $aliasQuery) => $aliasQuery->where('alias', 'like', $term))
                        ->orWhereHas('titles', fn (Builder $titleQuery) => $titleQuery->where('title', 'like', $term));
                });
            })
            ->when($year !== null, fn (Builder $builder) => $builder->where('season_year', $year))
            ->when($season !== '', fn (Builder $builder) => $builder->where('season_code', $season))
            ->when($tags !== [], function (Builder $builder) use ($tags): void {
                $builder->where(function (Builder $where) use ($tags): void {
                    foreach ($tags as $tag) {
                        $where->orWhereJsonContains('tags', $tag);
                    }
                });
            })
            ->orderByRaw('air_date is null')
            ->when(
                $isRecentMode,
                fn (Builder $builder) => $builder->orderByDesc('air_date'),
                fn (Builder $builder) => $builder->orderBy('air_date'),
            )
            ->orderBy('name')
            ->orderBy('id');

        $total = (clone $builder)->count();
        if ($isRecentMode) {
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

    /** @param array<int, string> $tags */
    private function cacheKey(
        string $query,
        ?int $year,
        string $season,
        array $tags,
        int $page,
        int $perPage,
    ): string {
        $parameters = [
            'q' => $query,
            'year' => $year,
            'season' => $season,
            'tags' => $tags,
            'page' => $page,
            'per_page' => $perPage,
        ];

        return 'anime:summaries:v1:'.hash(
            'sha256',
            (string) json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
