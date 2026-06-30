<?php

namespace App\Services\AnimeCatalog;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class AcgSecretsClient
{
    public function fetchIndex(): string
    {
        return $this->get('/bangumi/list/');
    }

    public function fetchSeason(string $yyyymm): string
    {
        $html = $this->get("/bangumi/{$yyyymm}/");
        $this->throttle();

        return $html;
    }

    private function get(string $path): string
    {
        $url = (string) config('services.acgsecrets.base_url').$path;
        $retries = (int) config('services.acgsecrets.retries');

        $response = Http::timeout((int) config('services.http.timeout_seconds'))
            ->withUserAgent((string) config('services.acgsecrets.user_agent'))
            ->retry($retries + 1, 1000, throw: false)
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("acgsecrets fetch failed [{$response->status()}]: {$url}");
        }

        return $response->body();
    }

    private function throttle(): void
    {
        $min = (int) config('services.acgsecrets.min_delay_ms');
        $max = (int) config('services.acgsecrets.max_delay_ms');
        usleep(random_int($min, max($min, $max)) * 1000);
    }
}
