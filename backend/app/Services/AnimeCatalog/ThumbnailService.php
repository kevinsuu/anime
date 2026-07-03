<?php

namespace App\Services\AnimeCatalog;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickException;
use Throwable;

final class ThumbnailService
{
    private const TARGET_WIDTH = 400;

    private const MAX_HEIGHT = 9999;

    /**
     * 下載 $imageUrl 指向的原圖，等比縮放到寬度 400px 並輸出 WebP，
     * 存到 public disk 的 covers/{animeId}.webp。全站共用同一份檔案，
     * 只在 import 有變動時產生一次，不是每次請求都重新處理。
     *
     * 任何失敗（下載失敗、逾時、非圖片內容、Imagick 解析失敗）都在
     * 這裡吞掉並記錄警告，回傳 null 讓呼叫端 fallback 回原圖 URL，
     * 不中斷呼叫端（AnimeImportService）的 import 流程。
     */
    public function generate(string $imageUrl, int $animeId): ?string
    {
        try {
            $response = Http::timeout((int) config('services.http.timeout_seconds'))
                ->get($imageUrl);

            if (! $response->successful()) {
                Log::warning("ThumbnailService: download failed [{$response->status()}] {$imageUrl}");

                return null;
            }

            $imagick = new Imagick();
            $imagick->readImageBlob($response->body());
            $imagick->resizeImage(self::TARGET_WIDTH, self::MAX_HEIGHT, Imagick::FILTER_LANCZOS, 1, true);
            $imagick->setImageFormat('webp');

            $path = "covers/{$animeId}.webp";
            Storage::disk('public')->put($path, $imagick->getImageBlob());
            $imagick->destroy();

            return $path;
        } catch (ImagickException $exception) {
            Log::warning("ThumbnailService: image processing failed for anime {$animeId}: {$exception->getMessage()}");

            return null;
        } catch (Throwable $exception) {
            Log::warning("ThumbnailService: unexpected error for anime {$animeId}: {$exception->getMessage()}");

            return null;
        }
    }
}
