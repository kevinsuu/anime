<?php

namespace App\Services\AnimeCatalog;

use App\Exceptions\ApiException;
use App\Services\Shared\DelimitedValues;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class AnimeSearchCriteriaParser
{
    private const MAX_QUERY_LENGTH = 100;

    private const MAX_TAG_COUNT = 10;

    private const MAX_TAG_LENGTH = 50;

    public function parse(Request $request, bool $enforceSizeLimits = false): AnimeSearchCriteria
    {
        $query = trim($this->queryString($request, 'q', ''));
        $yearInput = $this->queryString($request, 'year');
        $season = trim($this->queryString($request, 'season', ''));
        $tagsInput = $this->queryString($request, 'tags', '');

        if ($enforceSizeLimits && mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            throw new ApiException(422, 'validation_failed', '搜尋文字不可超過 100 個字元');
        }

        if ($yearInput !== null && (! ctype_digit($yearInput) || (int) $yearInput < 1900 || (int) $yearInput > 2100)) {
            throw new ApiException(422, 'validation_failed', '年份格式錯誤');
        }

        if ($season !== '') {
            try {
                SeasonResolver::months($season);
            } catch (InvalidArgumentException) {
                throw new ApiException(422, 'validation_failed', '季度格式錯誤');
            }
        }

        $tags = array_values(array_unique(DelimitedValues::parse($tagsInput)));
        if ($enforceSizeLimits && (count($tags) > self::MAX_TAG_COUNT
            || collect($tags)->contains(fn (string $tag): bool => mb_strlen($tag) > self::MAX_TAG_LENGTH))) {
            throw new ApiException(422, 'validation_failed', '分類最多 10 個，且每個不可超過 50 個字元');
        }
        sort($tags, SORT_STRING);

        return new AnimeSearchCriteria(
            query: $query,
            year: $yearInput === null ? null : (int) $yearInput,
            season: $season,
            tags: $tags,
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
}
