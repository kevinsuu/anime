<?php

namespace App\Services\AnimeCatalog;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class BangumiClient
{
    /**
     * Fetch total episode count for a bangumi subject id.
     *
     * Returns null when the subject has none published yet (e.g. eps is 0),
     * rather than treating that as a fetch failure.
     */
    public function fetchEpisodeCount(string $subjectId): ?int
    {
        $url = (string) config('services.bangumi.base_url')."/v0/subjects/{$subjectId}";
        $retries = (int) config('services.bangumi.retries');
        $retryDelayMs = (int) config('services.bangumi.retry_delay_ms');

        $response = Http::timeout((int) config('services.http.timeout_seconds'))
            ->withUserAgent((string) config('services.bangumi.user_agent'))
            ->retry($retries + 1, $retryDelayMs, throw: false)
            ->get($url);

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException("bangumi fetch failed [{$response->status()}]: {$url}");
        }

        // total_episodes is the planned/final count; eps is "aired so far" and is
        // 0 for shows that haven't started, so it must never be preferred over
        // total_episodes when both are present.
        $total = $response->json('total_episodes') ?? $response->json('eps');

        return is_numeric($total) && (int) $total > 0 ? (int) $total : null;
    }

    /**
     * Search for a subject by title. Returns candidate {id, name, name_cn} pairs
     * (anime only, type=2) for the caller to match against — this endpoint's
     * ranking is not reliable enough to trust the first result blindly.
     *
     * @return list<array{id: int, name: string, name_cn: string}>
     */
    public function searchSubjects(string $keyword): array
    {
        $url = (string) config('services.bangumi.base_url').'/search/subject/'.rawurlencode($keyword);
        $retries = (int) config('services.bangumi.retries');
        $retryDelayMs = (int) config('services.bangumi.retry_delay_ms');

        $response = Http::timeout((int) config('services.http.timeout_seconds'))
            ->withUserAgent((string) config('services.bangumi.user_agent'))
            ->retry($retries + 1, $retryDelayMs, throw: false)
            ->get($url, ['type' => 2]);

        if ($response->status() === 404 || $response->json('code') === 404) {
            return [];
        }

        if (! $response->successful()) {
            throw new RuntimeException("bangumi search failed [{$response->status()}]: {$url}");
        }

        $list = $response->json('list') ?? [];

        return array_values(array_map(fn (array $item) => [
            'id' => (int) $item['id'],
            'name' => (string) ($item['name'] ?? ''),
            'name_cn' => (string) ($item['name_cn'] ?? ''),
        ], $list));
    }

    public function throttle(): void
    {
        $min = (int) config('services.bangumi.min_delay_ms');
        $max = (int) config('services.bangumi.max_delay_ms');
        usleep(random_int($min, max($min, $max)) * 1000);
    }
}
