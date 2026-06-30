<?php

namespace App\Services\AnimeCatalog;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class BangumiClient
{
    public function fetchSeason(int $year, string $seasonCode): array
    {
        $months = SeasonResolver::months($seasonCode);
        $itemsById = [];

        foreach ($months as $month) {
            $offset = 0;

            do {
                $response = Http::timeout((int) config('services.http.timeout_seconds'))
                    ->acceptJson()
                    ->withUserAgent((string) config('services.bangumi.user_agent'))
                    ->get(rtrim((string) config('services.bangumi.base_url'), '/').'/v0/subjects', [
                        'type' => 2,
                        'sort' => 'date',
                        'year' => $year,
                        'month' => $month,
                        'limit' => 50,
                        'offset' => $offset,
                    ]);

                if (! $response->successful()) {
                    throw new RuntimeException("Bangumi API failed with status {$response->status()}");
                }

                $body = $response->json();
                $items = $body['data'] ?? $body;
                if (! is_array($items)) {
                    throw new RuntimeException('Bangumi subjects response is missing data');
                }

                foreach ($items as $item) {
                    if (! is_array($item) || empty($item['id'])) {
                        continue;
                    }

                    $itemsById[(string) $item['id']] = $item;
                }

                $count = count($items);
                $offset += $count;
            } while ($count === 50);
        }

        return array_values($itemsById);
    }
}
