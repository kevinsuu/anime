<?php

namespace Tests\Unit;

use App\Services\AnimeCatalog\ThumbnailService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ThumbnailServiceTest extends TestCase
{
    /**
     * Imagick 自己畫一張圖當測試素材，避免依賴外部二進位 fixture 檔案。
     * 尺寸故意設大（2000x3000），驗證縮放邏輯真的有把寬度收到 400。
     */
    private function fakeJpegBytes(int $width = 2000, int $height = 3000): string
    {
        $img = new \Imagick();
        $img->newImage($width, $height, new \ImagickPixel('red'));
        $img->setImageFormat('jpeg');

        return $img->getImageBlob();
    }

    public function test_generate_downloads_resizes_and_stores_webp(): void
    {
        Storage::fake('public');
        Http::fake([
            'static.acgsecrets.hk/*' => Http::response($this->fakeJpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $service = app(ThumbnailService::class);
        $path = $service->generate('https://static.acgsecrets.hk/original.jpg', 123);

        $this->assertSame('covers/123.webp', $path);
        Storage::disk('public')->assertExists('covers/123.webp');

        $stored = Storage::disk('public')->get('covers/123.webp');
        $im = new \Imagick();
        $im->readImageBlob($stored);
        $this->assertSame(400, $im->getImageWidth());
        $this->assertSame('WEBP', $im->getImageFormat());
    }

    public function test_generate_returns_null_when_download_fails(): void
    {
        Storage::fake('public');
        Http::fake([
            'static.acgsecrets.hk/*' => Http::response('not found', 404),
        ]);

        $service = app(ThumbnailService::class);
        $path = $service->generate('https://static.acgsecrets.hk/missing.jpg', 456);

        $this->assertNull($path);
        Storage::disk('public')->assertMissing('covers/456.webp');
    }

    public function test_generate_returns_null_when_response_is_not_a_valid_image(): void
    {
        Storage::fake('public');
        Http::fake([
            'static.acgsecrets.hk/*' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $service = app(ThumbnailService::class);
        $path = $service->generate('https://static.acgsecrets.hk/broken.jpg', 789);

        $this->assertNull($path);
    }
}
