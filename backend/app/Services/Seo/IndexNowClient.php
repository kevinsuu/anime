<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Pushes changed public URLs to IndexNow (Bing, Yandex, Naver, Seznam share
 * submissions). Bing's index backs ChatGPT Search and Copilot, so this
 * shortens the time before a new season's pages can be cited.
 */
final class IndexNowClient
{
    /** IndexNow accepts at most 10,000 URLs per request. */
    private const MAX_URLS_PER_REQUEST = 10000;

    public function isConfigured(): bool
    {
        return $this->key() !== '';
    }

    public function siteUrl(): string
    {
        return rtrim((string) config('services.indexnow.site_url'), '/');
    }

    /**
     * @param list<string> $urls absolute URLs on the configured site
     */
    public function submit(array $urls): void
    {
        $siteUrl = $this->siteUrl();
        $host = parse_url($siteUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new RuntimeException("Invalid INDEXNOW_SITE_URL: {$siteUrl}");
        }

        foreach (array_chunk($urls, self::MAX_URLS_PER_REQUEST) as $chunk) {
            $response = Http::timeout((int) config('services.http.timeout_seconds'))
                ->acceptJson()
                ->post((string) config('services.indexnow.endpoint'), [
                    'host' => $host,
                    'key' => $this->key(),
                    // Served by the Nuxt frontend (server/routes/indexnow-key.txt.ts).
                    // It must sit at the site root so every URL below it is accepted.
                    'keyLocation' => "{$siteUrl}/indexnow-key.txt",
                    'urlList' => $chunk,
                ]);

            // 200 = accepted and key verified, 202 = accepted, key check pending.
            if (! in_array($response->status(), [200, 202], true)) {
                throw new RuntimeException("IndexNow submission failed [{$response->status()}]: {$response->body()}");
            }
        }
    }

    private function key(): string
    {
        return trim((string) config('services.indexnow.key'));
    }
}
