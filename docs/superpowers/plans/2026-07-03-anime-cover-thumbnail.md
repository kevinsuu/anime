# 動畫封面縮圖 Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 後端在 import 動畫資料時自動產生 400px 寬的 WebP 縮圖並持久化儲存，讓卡片列表載入的圖片體積從原圖 ~125KB 降到 ~15-25KB，解決快速滾動時圖片下載跟不上滾動速度造成的空白卡片問題。

**Architecture:** 新增 `ThumbnailService`（用 Laravel `Http` facade 下載原圖、`Imagick` 等比縮放輸出 WebP、存進 `public` filesystem disk 的 `covers/` 目錄）。`AnimeImportService::importRecord` 在寫入 anime 資料時呼叫它，結果存進新欄位 `cover_image_path`。`Anime` model 對 `image_url` 加 accessor，優先回傳縮圖 URL、否則 fallback 回原圖 URL——兩個 Controller 端點完全不用改程式碼，只需要把新欄位加進既有的 `select()`/`get()` 欄位清單。新增一次性 Artisan 命令為現有 2664 筆資料 backfill 縮圖。`backend`、`scheduler` 兩個 production 容器共用同一個 named volume,否則 scheduler 產生的檔案 backend API 讀不到。

**Tech Stack:** Laravel 13 / PHP 8.4,`Illuminate\Support\Facades\Http`（既有慣例）,PECL `imagick` 擴充（PHP 8.4 相容,已在容器內實測安裝成功）,Laravel `public` filesystem disk（已設定,對應 `storage_path('app/public')`）。

---

## 已驗證的技術細節（供各 Task 直接引用,不需再摸索）

- **Imagick 安裝**（已在執行中的 `anime-backend-1` 容器內實測跑過,順序不可顛倒）:
  ```
  apt-get install -y --no-install-recommends libmagickwand-dev
  pecl install imagick
  docker-php-ext-enable imagick
  ```
- **等比縮放 API**（已實測,`resizeImage` 的兩個尺寸參數都必須非零,用 `bestfit=true` 讓實際輸出寬度優先受第一個參數限制）:
  ```php
  $imagick->resizeImage(400, 9999, Imagick::FILTER_LANCZOS, 1, true);
  // 2560x1440 原圖 → 實測輸出 400x225,等比正確
  ```
- **`public` disk 設定**（[filesystems.php](../../../backend/config/filesystems.php),已存在,不需新增)：`Storage::disk('public')->put('covers/123.webp', $blob)` 會存到 `storage/app/public/covers/123.webp`；`Storage::disk('public')->url('covers/123.webp')` 組出 `{APP_URL}/storage/covers/123.webp`。
- **`AnimeController::index` 的 `get([...])` 目前沒有 select `cover_image_path`**（[AnimeController.php:59-62](../../../backend/app/Http/Controllers/Api/AnimeController.php#L59-L62)）——若不修正,accessor 讀到的欄位值永遠是 null,縮圖形同虛設。Task 6 會處理。
- **`entrypoint.sh` 目前沒有 `php artisan storage:link`**（[entrypoint.sh](../../../backend/docker/entrypoint.sh)）——縮圖靠 `public` disk 直接組 URL（`Storage::disk('public')->url()`）,不透過 `public/storage` symlink 存取,所以不需要新增 `storage:link`。
- **`deploy/docker-compose.yml` 的 `backend` 和 `scheduler` 是兩個獨立容器,共跑同一份 image**——`scheduler` 執行每週排程 import（會產生新縮圖檔案),`backend` 提供 API（讀縮圖檔案)。兩者必須掛載**同一個** named volume,否則 scheduler 寫入的檔案 backend 讀不到。Task 9 會兩邊都加。

---

## Task 1: Migration — 新增 `cover_image_path` 欄位

**Files:**
- Create: `backend/database/migrations/2026_07_03_000000_add_cover_image_path_to_anime.php`

- [ ] **Step 1: 建立 migration 檔案**

參考既有欄位新增 migration 的寫法（[2026_07_02_000000_add_import_hash_to_anime.php](../../../backend/database/migrations/2026_07_02_000000_add_import_hash_to_anime.php)）：

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anime', function (Blueprint $table): void {
            $table->string('cover_image_path')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('anime', function (Blueprint $table): void {
            $table->dropColumn('cover_image_path');
        });
    }
};
```

- [ ] **Step 2: 跑 migration**

Run: `docker compose exec backend php artisan migrate`
Expected: 輸出包含 `2026_07_03_000000_add_cover_image_path_to_anime ... DONE`

- [ ] **Step 3: 確認欄位已建立**

Run: `docker compose exec backend php artisan tinker --execute="echo Schema::hasColumn('anime', 'cover_image_path') ? 'yes' : 'no';"`
Expected: `yes`

- [ ] **Step 4: Commit**

```bash
git add backend/database/migrations/2026_07_03_000000_add_cover_image_path_to_anime.php
git commit -m "$(cat <<'EOF'
feat: 新增 anime.cover_image_path 欄位

為後續的封面縮圖功能準備資料表欄位，儲存縮圖檔案的相對路徑。
EOF
)"
```

---

## Task 2: `Anime` model 加 `fillable` 與 `image_url` accessor

**Files:**
- Modify: `backend/app/Models/Anime.php`
- Test: `backend/tests/Unit/AnimeImageUrlAccessorTest.php`

**背景**：`AnimeController::index`/`show` 兩處都用 `$anime->image_url` 讀值後手動組進回傳陣列（[AnimeController.php:69,113](../../../backend/app/Http/Controllers/Api/AnimeController.php#L69)）。在 model 對 `image_url` 屬性加 accessor，兩個 Controller 完全不用改。

- [ ] **Step 1: 寫失敗測試**

`Anime` 目前沒有專屬的 model 測試檔，建立一個新的：

```php
<?php

namespace Tests\Unit;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnimeImageUrlAccessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_image_url_falls_back_to_original_when_no_cover_path(): void
    {
        $anime = Anime::create([
            'name' => '測試動畫',
            'image_url' => 'https://static.acgsecrets.hk/original.jpg',
            'cover_image_path' => null,
        ]);

        $this->assertSame('https://static.acgsecrets.hk/original.jpg', $anime->image_url);
    }

    public function test_image_url_prefers_cover_image_path_when_present(): void
    {
        $anime = Anime::create([
            'name' => '測試動畫',
            'image_url' => 'https://static.acgsecrets.hk/original.jpg',
            'cover_image_path' => 'covers/123.webp',
        ]);

        $this->assertSame(
            rtrim((string) config('app.url'), '/') . '/storage/covers/123.webp',
            $anime->image_url,
        );
    }
}
```

- [ ] **Step 2: 執行測試，確認因欄位不在 fillable 而失敗**

Run: `docker compose exec backend php artisan test --filter=AnimeImageUrlAccessorTest`
Expected: FAIL（`cover_image_path` 不在 `$fillable`，`Anime::create` 不會寫入該欄位，導致第二個測試的斷言失敗）

- [ ] **Step 3: 在 `Anime` model 加 `fillable` 與 accessor**

修改 [Anime.php](../../../backend/app/Models/Anime.php)：

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

final class Anime extends Model
{
    protected $table = 'anime';

    protected $fillable = [
        'name',
        'description',
        'image_url',
        'cover_image_path',
        'source',
        'created_by_user_id',
        'season_year',
        'season_code',
        'air_date',
        'air_date_text',
        'episode_count',
        'status',
        'tags',
        'import_hash',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }

    /**
     * cover_image_path 有值時優先回傳縮圖 URL（全站共用的靜態檔案，
     * 由 import 時的 ThumbnailService 產生），否則 fallback 回原始
     * acgsecrets 圖片網址 —— 縮圖產生失敗或尚未 backfill 的舊資料
     * 都會走這個 fallback，圖片顯示絕不會比現況更差。
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes) => $attributes['cover_image_path'] !== null
                ? Storage::disk('public')->url($attributes['cover_image_path'])
                : $value,
        );
    }

    public function titles(): HasMany
    {
        return $this->hasMany(AnimeTitle::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(AnimeAlias::class);
    }

    public function externalIds(): HasMany
    {
        return $this->hasMany(AnimeExternalId::class);
    }

    public function streams(): HasMany
    {
        return $this->hasMany(AnimeStream::class);
    }

    public function themes(): HasMany
    {
        return $this->hasMany(AnimeTheme::class)->orderBy('sort_order');
    }

    public function trailers(): HasMany
    {
        return $this->hasMany(AnimeTrailer::class)->orderBy('sort_order');
    }

    public function cast(): HasMany
    {
        return $this->hasMany(AnimeCast::class)->orderBy('sort_order');
    }

    public function staffMembers(): HasMany
    {
        return $this->hasMany(AnimeStaff::class)->orderBy('sort_order');
    }

    public function links(): HasMany
    {
        return $this->hasMany(AnimeLink::class);
    }
}
```

**注意**：`Attribute::make` 的 `get` callback 第二參數 `$attributes` 是目前 model 的原始屬性陣列，讀 `$attributes['cover_image_path']` 而不是 `$this->cover_image_path`——因為在 accessor 內呼叫 `$this->cover_image_path` 不會遞迴（該欄位沒有自己的 accessor），但用 `$attributes` 更直接明確，且不受屬性尚未從資料庫載入完成的時序影響。

- [ ] **Step 4: 執行測試，確認通過**

Run: `docker compose exec backend php artisan test --filter=AnimeImageUrlAccessorTest`
Expected: `2 passed`

- [ ] **Step 5: Commit**

```bash
git add backend/app/Models/Anime.php backend/tests/Unit/AnimeImageUrlAccessorTest.php
git commit -m "$(cat <<'EOF'
feat: Anime.image_url 優先回傳縮圖網址，無縮圖時退回原圖

image_url 改為 accessor：cover_image_path 有值時組出 public disk
的縮圖網址，否則 fallback 回原始 acgsecrets 圖片網址。Controller
端不需要修改，讀取 $anime->image_url 的地方自動套用新邏輯。
EOF
)"
```

---

## Task 3: `ThumbnailService` — 下載原圖並產生縮圖

**Files:**
- Create: `backend/app/Services/AnimeCatalog/ThumbnailService.php`
- Test: `backend/tests/Unit/ThumbnailServiceTest.php`

**背景**：單一公開方法 `generate()`，下載原圖 → Imagick 縮放 → 存進 `public` disk → 回傳相對路徑。任何失敗都吞掉並回傳 `null`，呼叫端不需要 try/catch。用 `Http` facade 下載（沿用 [AcgSecretsClient](../../../backend/app/Services/AnimeCatalog/AcgSecretsClient.php) 的既有模式），測試用 `Http::fake()` 攔截。

- [ ] **Step 1: 寫失敗測試**

```php
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
```

- [ ] **Step 2: 執行測試，確認因類別不存在而失敗**

Run: `docker compose exec backend php artisan test --filter=ThumbnailServiceTest`
Expected: FAIL（`Class "App\Services\AnimeCatalog\ThumbnailService" not found`）

- [ ] **Step 3: 實作 `ThumbnailService`**

```php
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
```

- [ ] **Step 4: 執行測試，確認通過**

Run: `docker compose exec backend php artisan test --filter=ThumbnailServiceTest`
Expected: `3 passed`

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/AnimeCatalog/ThumbnailService.php backend/tests/Unit/ThumbnailServiceTest.php
git commit -m "$(cat <<'EOF'
feat: 新增 ThumbnailService，下載原圖並產生 400px WebP 縮圖

下載失敗或圖片格式無法解析時回傳 null，呼叫端據此 fallback 回
原圖網址，不中斷 import 流程。
EOF
)"
```

---

## Task 4: Dockerfile 加裝 Imagick 擴充

**Files:**
- Modify: `backend/Dockerfile`
- Modify: `backend/Dockerfile.production`

**背景**：已在執行中的容器內實測驗證安裝順序（見計畫開頭「已驗證的技術細節」）。兩個 Dockerfile 都要改，dev 用的 `Dockerfile` 和 production 用的 `Dockerfile.production`。

- [ ] **Step 1: 修改 `backend/Dockerfile`**

```dockerfile
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip git libmagickwand-dev \
    && docker-php-ext-install pdo_mysql \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --optimize-autoloader

COPY . .

EXPOSE 8080

CMD ["sh", "-c", "composer install --no-interaction --prefer-dist && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8080"]
```

- [ ] **Step 2: 修改 `backend/Dockerfile.production`**

```dockerfile
FROM php:8.4-fpm AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip git nginx curl libmagickwand-dev \
    && docker-php-ext-install pdo_mysql opcache \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && rm -rf /var/lib/apt/lists/*

RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache-prod.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-dev --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-dev

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN rm -f /etc/nginx/sites-enabled/default \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /app/storage/framework/cache/data \
        /app/storage/framework/sessions \
        /app/storage/framework/testing \
        /app/storage/framework/views \
        /app/storage/logs \
        /app/storage/app/public/covers \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
```

（唯一新增：`libmagickwand-dev` 系統套件、`pecl install imagick` + `docker-php-ext-enable imagick`、以及 production 版多建立 `storage/app/public/covers` 目錄並確保 `www-data` 有寫入權限。）

- [ ] **Step 3: 重新 build 並啟動 dev 容器，確認擴充已生效**

Run:
```bash
docker compose up --build -d backend
docker compose exec backend php -m | grep imagick
```
Expected: 輸出 `imagick`

- [ ] **Step 4: 確認既有測試在新 image 上仍全部通過（沒有因擴充安裝破壞既有環境）**

Run: `docker compose exec backend php artisan test`
Expected: 全部 PASS（含 Task 1-3 新增的測試）

- [ ] **Step 5: Commit**

```bash
git add backend/Dockerfile backend/Dockerfile.production
git commit -m "$(cat <<'EOF'
feat: Dockerfile 加裝 Imagick PHP 擴充

封面縮圖功能需要 Imagick 做圖片縮放/格式轉換，兩個 Dockerfile
(dev 用的 serve 版本、production 用的 php-fpm 版本) 都需要安裝。
EOF
)"
```

---

## Task 5: `AnimeImportService` 整合縮圖產生

**Files:**
- Modify: `backend/app/Services/AnimeCatalog/AnimeImportService.php:40-78`
- Modify: `backend/tests/Unit/AnimeImportServiceTest.php`

**背景**：`importRecord` 目前把 `cover_image` 寫進 `image_url` 欄位（[AnimeImportService.php:54](../../../backend/app/Services/AnimeCatalog/AnimeImportService.php#L54)）。在同一個流程加一步：呼叫 `ThumbnailService::generate()`，把結果寫入 `cover_image_path`。`import_hash` 相同（`wasUnchanged`）的分支本來就直接 return，不做任何寫入，維持不變——沒有變動的資料不需要重新產生縮圖。

**關鍵時序限制**：`ThumbnailService::generate()` 需要 `$animeId` 來命名檔案（`covers/{id}.webp`），但全新建立的 `Anime`（`resolveAnime` 對找不到既有記錄的情況回傳 `new Anime()`）在 `save()` 之前 `id` 是 null。因此縮圖產生必須發生在第一次 `save()` 之後（此時 `$anime->id` 已由資料庫指派），再二次寫入 `cover_image_path` 並存一次。

- [ ] **Step 1: 在既有測試檔加新測試（先寫失敗版本）**

在 [AnimeImportServiceTest.php](../../../backend/tests/Unit/AnimeImportServiceTest.php) 加 `use Illuminate\Support\Facades\Http;`、`use Illuminate\Support\Facades\Storage;`，並新增測試方法：

```php
    public function test_import_record_generates_cover_thumbnail(): void
    {
        Storage::fake('public');
        Http::fake([
            'static.acgsecrets.hk/*' => Http::response($this->fakeJpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $service = app(AnimeImportService::class);
        $outcome = $service->importRecord($this->record());

        $this->assertNotNull($outcome->anime->cover_image_path);
        Storage::disk('public')->assertExists($outcome->anime->cover_image_path);
    }

    public function test_import_record_leaves_cover_image_path_null_when_thumbnail_generation_fails(): void
    {
        Storage::fake('public');
        Http::fake([
            'static.acgsecrets.hk/*' => Http::response('not found', 404),
        ]);

        $service = app(AnimeImportService::class);
        $outcome = $service->importRecord($this->record());

        $this->assertFalse($outcome->wasUnchanged);
        $this->assertNull($outcome->anime->cover_image_path);
        $this->assertSame('https://static.acgsecrets.hk/x.jpg', $outcome->anime->getRawOriginal('image_url'));
    }

    private function fakeJpegBytes(): string
    {
        $img = new \Imagick();
        $img->newImage(2000, 3000, new \ImagickPixel('red'));
        $img->setImageFormat('jpeg');

        return $img->getImageBlob();
    }
```

注意第二個測試用 `getRawOriginal('image_url')` 而非 `image_url`——因為 Task 2 加了 accessor，`$anime->image_url` 會回傳處理過的值（cover_image_path 為 null 時等於原值，這裡剛好一致，但用 `getRawOriginal` 更精確地測「資料庫實際寫入值」，避免跟 accessor 行為混淆）。

- [ ] **Step 2: 執行測試，確認新增的兩個測試失敗**

Run: `docker compose exec backend php artisan test --filter=AnimeImportServiceTest`
Expected: 舊有 3 個測試 PASS，新增 2 個測試 FAIL（`cover_image_path` 目前不會被寫入，恆為 null，第一個測試斷言 `assertNotNull` 失敗）

- [ ] **Step 3: 修改 `AnimeImportService::importRecord`**

在 [AnimeImportService.php](../../../backend/app/Services/AnimeCatalog/AnimeImportService.php) 加 constructor 注入 `ThumbnailService`，並調整 `importRecord` 內容。完整的 class 開頭與 `importRecord` 方法如下（其餘方法 `importSeason`、`resolveAnime`、`syncTitles` 等維持檔案原本內容不變）：

```php
<?php

namespace App\Services\AnimeCatalog;

use App\Models\Anime;
use App\Models\AnimeAlias;
use App\Models\AnimeCast;
use App\Models\AnimeExternalId;
use App\Models\AnimeLink;
use App\Models\AnimeStaff;
use App\Models\AnimeStream;
use App\Models\AnimeTheme;
use App\Models\AnimeTitle;
use App\Models\AnimeTrailer;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AnimeImportService
{
    private const PROVIDER_URLS = [
        'mal' => 'https://myanimelist.net/anime/%s',
        'bangumi' => 'https://bgm.tv/subject/%s',
    ];

    public function __construct(private readonly ThumbnailService $thumbnails)
    {
    }

    public function importRecord(array $record, string $source = 'acgsecrets'): ImportOutcome
    {
        $payloadHash = hash('sha256', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($record, $payloadHash, $source): ImportOutcome {
            $anime = $this->resolveAnime($record);

            if ($anime->exists && $anime->import_hash === $payloadHash) {
                return new ImportOutcome($anime, wasUnchanged: true);
            }

            $coverImage = $record['cover_image'] ?? null;

            $anime->fill([
                'name' => (string) $record['title_zh'],
                'description' => $record['summary'] ?? null,
                'image_url' => $coverImage,
                'source' => $source,
                'season_year' => $record['season_year'] ?? null,
                'season_code' => $record['season_code'] ?? null,
                'air_date' => $record['air_date'] ?? null,
                'air_date_text' => $record['air_date_text'] ?? null,
                'episode_count' => $record['episode_count'] ?? null,
                'tags' => $record['tags'] ?? [],
                'import_hash' => $payloadHash,
            ]);
            $anime->save();

            // 縮圖檔名需要 anime->id，必須在第一次 save() 之後（id 已確定）
            // 才產生，因此這裡二次寫入 cover_image_path 並再 save 一次。
            if ($coverImage !== null) {
                $anime->cover_image_path = $this->thumbnails->generate($coverImage, $anime->id);
                $anime->save();
            }

            $this->syncTitles($anime, $record, $source);
            $this->syncAliases($anime, $record);
            $this->syncExternalIds($anime, $record, $payloadHash);
            $this->syncStreams($anime, $record);
            $this->syncThemes($anime, $record);
            $this->syncTrailers($anime, $record);
            $this->syncCast($anime, $record);
            $this->syncStaff($anime, $record);
            $this->syncLinks($anime, $record);

            return new ImportOutcome($anime->refresh(), wasUnchanged: false);
        });
    }

    // importSeason(), resolveAnime(), syncTitles(), syncAliases(),
    // syncExternalIds(), syncThemes(), syncTrailers(), syncCast(),
    // syncStaff(), syncLinks(), syncStreams() 全部維持檔案原本內容，
    // 不需要修改。
}
```

- [ ] **Step 4: 執行測試，確認全部通過**

Run: `docker compose exec backend php artisan test --filter=AnimeImportServiceTest`
Expected: `5 passed`（原本 3 個 + 新增 2 個）

- [ ] **Step 5: 執行完整後端測試,確認沒有破壞其他既有測試（例如會呼叫 importRecord/importSeason 的其他測試,若其 fixture 帶有 cover_image 但沒有對應的 `Http::fake()` 規則,可能因為真的嘗試對外連線而變慢或報錯)**

Run: `docker compose exec backend php artisan test`
Expected: 全部 PASS。若有測試因為 `cover_image` 存在但沒有對應的 `Http::fake()` 規則而報錯,依 Laravel 慣例補上該測試的 `Http::fake()` 規則（無參數的 `Http::fake()` 會讓所有請求回傳空的 200 響應,但空內容無法被 Imagick 解析,`ThumbnailService` 會捕捉例外並回傳 null,不影響該測試原本要驗證的邏輯)。

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/AnimeCatalog/AnimeImportService.php backend/tests/Unit/AnimeImportServiceTest.php
git commit -m "$(cat <<'EOF'
feat: import 時同步產生封面縮圖

AnimeImportService::importRecord 在寫入基本欄位並確定 anime id 後，
對有 cover_image 的記錄呼叫 ThumbnailService 產生縮圖並寫入
cover_image_path。縮圖產生失敗不影響 import 其餘流程完成（欄位
維持 null，前端 fallback 回原圖）。
EOF
)"
```

---

## Task 6: `AnimeController` 補上欄位選取

**Files:**
- Modify: `backend/app/Http/Controllers/Api/AnimeController.php:59-62`
- Test: `backend/tests/Feature/AnimeControllerImageUrlTest.php`

**背景**：`index()` 用明確的 `get([...])` 欄位清單、`show()` 用 `findOrFail`（拿全部欄位，不受影響）。`index()` 的欄位清單沒有 `cover_image_path`，accessor 讀不到值,永遠 fallback，縮圖對列表頁（`/seasonal`、`/catalog` 用的正是 `index()`）完全不生效——這正是本次要解決的頁面，必須修正。

- [ ] **Step 1: 寫失敗測試**

```php
<?php

namespace Tests\Feature;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnimeControllerImageUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_thumbnail_url_when_cover_image_path_present(): void
    {
        Anime::create([
            'name' => '測試動畫',
            'image_url' => 'https://static.acgsecrets.hk/original.jpg',
            'cover_image_path' => 'covers/999.webp',
            'season_year' => 2026,
            'season_code' => 'spring',
        ]);

        $response = $this->getJson('/anime?year=2026&season=spring');

        $response->assertSuccessful();
        $this->assertSame(
            rtrim((string) config('app.url'), '/') . '/storage/covers/999.webp',
            $response->json('items.0.image_url'),
        );
    }
}
```

- [ ] **Step 2: 執行測試，確認失敗**

Run: `docker compose exec backend php artisan test --filter=AnimeControllerImageUrlTest`
Expected: FAIL（`image_url` 回傳原圖網址而非縮圖網址，因為 `get([...])` 沒 select `cover_image_path`，accessor 讀到的 `$attributes['cover_image_path']` 不存在）

- [ ] **Step 3: 修改 `AnimeController::index` 的欄位清單**

修改 [AnimeController.php:59-62](../../../backend/app/Http/Controllers/Api/AnimeController.php#L59-L62)：

```php
            ->get([
                'id', 'name', 'description', 'image_url', 'cover_image_path', 'source',
                'season_year', 'season_code', 'air_date', 'air_date_text', 'episode_count', 'status', 'tags',
            ]);
```

（只新增 `'cover_image_path'` 到陣列中，其餘不變。`show()` 用 `findOrFail` 沒有 select 限制，不需要修改。）

- [ ] **Step 4: 執行測試，確認通過**

Run: `docker compose exec backend php artisan test --filter=AnimeControllerImageUrlTest`
Expected: `1 passed`

- [ ] **Step 5: 執行完整後端測試**

Run: `docker compose exec backend php artisan test`
Expected: 全部 PASS

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Api/AnimeController.php backend/tests/Feature/AnimeControllerImageUrlTest.php
git commit -m "$(cat <<'EOF'
fix: AnimeController::index 補上 cover_image_path 欄位選取

index() 用明確欄位清單 select，原本沒包含 cover_image_path，導致
image_url accessor 讀不到值、永遠 fallback 回原圖，縮圖對 /seasonal
與 /catalog 兩個主要列表頁完全不生效。
EOF
)"
```

---

## Task 7: 新 Artisan 命令 `anime:generate-thumbnails`（一次性 backfill）

**Files:**
- Create: `backend/app/Console/Commands/GenerateThumbnails.php`
- Test: `backend/tests/Feature/GenerateThumbnailsTest.php`

**背景**：掃描 `cover_image_path IS NULL AND image_url IS NOT NULL` 的既有資料，逐筆呼叫 `ThumbnailService::generate()`，chunk 處理避免一次載入全部到記憶體，每筆間隔節流避免對 acgsecrets 造成壓力。

- [ ] **Step 1: 寫失敗測試**

```php
<?php

namespace Tests\Feature;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class GenerateThumbnailsTest extends TestCase
{
    use RefreshDatabase;

    private function fakeJpegBytes(): string
    {
        $img = new \Imagick();
        $img->newImage(2000, 3000, new \ImagickPixel('red'));
        $img->setImageFormat('jpeg');

        return $img->getImageBlob();
    }

    public function test_backfills_thumbnails_for_anime_missing_cover_image_path(): void
    {
        Storage::fake('public');
        Http::fake([
            'static.acgsecrets.hk/*' => Http::response($this->fakeJpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $needsThumbnail = Anime::create([
            'name' => '需要補縮圖',
            'image_url' => 'https://static.acgsecrets.hk/a.jpg',
            'cover_image_path' => null,
        ]);
        $alreadyHasThumbnail = Anime::create([
            'name' => '已有縮圖',
            'image_url' => 'https://static.acgsecrets.hk/b.jpg',
            'cover_image_path' => 'covers/existing.webp',
        ]);
        $noImageUrl = Anime::create([
            'name' => '沒有原圖網址',
            'image_url' => null,
            'cover_image_path' => null,
        ]);

        $this->artisan('anime:generate-thumbnails')->assertSuccessful();

        $this->assertNotNull($needsThumbnail->fresh()->cover_image_path);
        Storage::disk('public')->assertExists($needsThumbnail->fresh()->cover_image_path);

        // 已有縮圖的不應被覆蓋觸發重新下載
        $this->assertSame('covers/existing.webp', $alreadyHasThumbnail->fresh()->cover_image_path);

        $this->assertNull($noImageUrl->fresh()->cover_image_path);

        Http::assertSentCount(1);
    }
}
```

- [ ] **Step 2: 執行測試，確認因命令不存在而失敗**

Run: `docker compose exec backend php artisan test --filter=GenerateThumbnailsTest`
Expected: FAIL（command `anime:generate-thumbnails` not defined）

- [ ] **Step 3: 實作命令**

```php
<?php

namespace App\Console\Commands;

use App\Models\Anime;
use App\Services\AnimeCatalog\ThumbnailService;
use Illuminate\Console\Command;

final class GenerateThumbnails extends Command
{
    protected $signature = 'anime:generate-thumbnails';

    protected $description = 'One-time backfill: generate cover thumbnails for existing anime rows that don\'t have one yet.';

    /**
     * 每筆之間節流的毫秒數，避免對 acgsecrets 瞬間發出大量並發請求。
     */
    private const THROTTLE_MS = 150;

    public function handle(ThumbnailService $thumbnails): int
    {
        $total = Anime::query()
            ->whereNull('cover_image_path')
            ->whereNotNull('image_url')
            ->count();

        if ($total === 0) {
            $this->info('No anime rows need a thumbnail backfill.');

            return self::SUCCESS;
        }

        $this->info("Backfilling thumbnails for {$total} anime row(s)...");

        $processed = 0;
        $succeeded = 0;
        $bar = $this->output->createProgressBar($total);

        Anime::query()
            ->whereNull('cover_image_path')
            ->whereNotNull('image_url')
            ->chunkById(50, function ($batch) use ($thumbnails, &$processed, &$succeeded, $bar): void {
                foreach ($batch as $anime) {
                    $path = $thumbnails->generate($anime->getRawOriginal('image_url'), $anime->id);
                    if ($path !== null) {
                        $anime->cover_image_path = $path;
                        $anime->save();
                        $succeeded++;
                    }
                    $processed++;
                    $bar->advance();
                    usleep(self::THROTTLE_MS * 1000);
                }
            });

        $bar->finish();
        $this->newLine();
        $this->info("Done: {$succeeded}/{$processed} thumbnail(s) generated successfully.");

        return self::SUCCESS;
    }
}
```

**重要細節**：`$anime->getRawOriginal('image_url')` 而不是 `$anime->image_url`——因為 Task 2 的 accessor 已經讓 `image_url` 變成「優先回傳縮圖網址」。這裡篩選條件已經是 `cover_image_path IS NULL`，理論上 accessor 會照樣 fallback 回原值，兩者結果相同；但用 `getRawOriginal` 更明確表達「這裡要的是資料庫原始欄位值」，避免未來 accessor 邏輯變動時產生混淆。

- [ ] **Step 4: 執行測試，確認通過**

Run: `docker compose exec backend php artisan test --filter=GenerateThumbnailsTest`
Expected: `1 passed`

- [ ] **Step 5: 執行完整後端測試**

Run: `docker compose exec backend php artisan test`
Expected: 全部 PASS

- [ ] **Step 6: Commit**

```bash
git add backend/app/Console/Commands/GenerateThumbnails.php backend/tests/Feature/GenerateThumbnailsTest.php
git commit -m "$(cat <<'EOF'
feat: 新增 anime:generate-thumbnails 一次性 backfill 命令

掃描現有 cover_image_path 為 null 但有 image_url 的 anime 資料，
逐筆補產生縮圖。每筆間隔 150ms 節流，避免對 acgsecrets 造成壓力。
EOF
)"
```

---

## Task 8: 本地 `docker-compose.yml` 加 volume（dev 環境驗證用）

**Files:**
- Modify: `docker-compose.yml`

**背景**：本地開發驗證 backfill 與縮圖持久化行為，也應該有對應 volume，讓 `docker compose down`/`up` 之間縮圖不會消失,行為與 production 一致方便驗證。

- [ ] **Step 1: 查看現有 `backend` 服務定義段落，確認實際結構後再決定加在哪裡**

Run: `grep -n "backend:" -A 30 /Users/sumingkai/Documents/anime/docker-compose.yml | head -40`

實際核對輸出，確認現有是否已有 `volumes:` 區塊（例如掛載原始碼做熱重載），以及檔案底部既有的頂層 `volumes:` 宣告內容，不要依賴記憶或先前讀取結果——直接照這次指令的實際輸出決定下一步怎麼編輯。

- [ ] **Step 2: 在 `backend` 服務加一個 named volume 掛載 `storage/app/public`**

在既有 `volumes:` 清單（若已有原始碼掛載）追加一行，或新增 `volumes:` 區塊，追加：

```yaml
      - backend-storage-public:/app/storage/app/public
```

不要移除既有掛載項目。

在檔案底部的頂層 `volumes:` 區塊追加宣告：

```yaml
  backend-storage-public:
```

若頂層已有其他 volume 宣告（如 `mysql-data`），追加即可，不要重複宣告 `volumes:` 這個 key 本身。

- [ ] **Step 3: 重啟 backend 容器，確認 volume 掛載成功**

Run:
```bash
docker compose up -d backend
docker compose exec backend ls -la /app/storage/app/public
```
Expected: 目錄存在且可寫入（沒有 permission denied）

- [ ] **Step 4: 手動驗證持久化：跑一次 backfill 針對單筆資料,重啟容器後檔案仍在**

Run:
```bash
docker compose exec backend php artisan tinker --execute="App\Models\Anime::query()->limit(1)->update(['cover_image_path' => null]);"
docker compose exec backend php artisan anime:generate-thumbnails
docker compose restart backend
docker compose exec backend ls /app/storage/app/public/covers | head -3
```
Expected: 重啟後 `covers/` 目錄下的檔案仍然存在（volume 生效的證明）

- [ ] **Step 5: Commit**

```bash
git add docker-compose.yml
git commit -m "$(cat <<'EOF'
chore: 本地 docker-compose 為 backend 加縮圖持久化 volume

避免 dev 環境重啟容器時已產生的縮圖遺失，行為與 production 部署
的持久化設計保持一致，方便本機驗證。
EOF
)"
```

---

## Task 9: Production 部署 volume 持久化（`backend` 與 `scheduler` 共用）

**Files:**
- Modify: `deploy/docker-compose.yml`

**背景**：production 環境 `backend`（提供 API）與 `scheduler`（跑每週排程 import，實際產生新縮圖的地方）是兩個獨立容器、共用同一份 image。縮圖檔案必須讓兩者讀寫同一份儲存，否則 scheduler 產生的縮圖 backend API 永遠讀不到。容器重建（`docker compose pull && up -d`）時,沒有 volume 的檔案系統會被整個換新,因此需要 named volume 跨重建保留。

- [ ] **Step 1: 讀取目前 `deploy/docker-compose.yml` 的實際完整內容，確認 `backend`、`scheduler`、頂層 `volumes:` 的現況**

Run: `cat /Users/sumingkai/Documents/anime/deploy/docker-compose.yml`

以這次指令的實際輸出為準決定怎麼編輯，不要依賴先前任何一次的記憶版本——避免因為檔案內容跟預期不一致而編輯錯位置。

- [ ] **Step 2: 在 `backend` 服務加 volume**

在 `backend:` 區塊內，`environment:` 之後、`ports:` 之前，加入：

```yaml
    volumes:
      - backend-storage-public:/app/storage/app/public
```

- [ ] **Step 3: 在 `scheduler` 服務加**同一個** volume**

在 `scheduler:` 區塊內，`environment:` 之後、`depends_on:` 之前，加入：

```yaml
    volumes:
      - backend-storage-public:/app/storage/app/public
```

- [ ] **Step 4: 在檔案底部既有的頂層 `volumes:` 區塊追加宣告**

現有內容包含 `mysql-data:`，在同一個 `volumes:` 區塊下追加一行：

```yaml
  backend-storage-public:
```

- [ ] **Step 5: 確認整份 YAML 語法正確**

Run: `docker compose -f /Users/sumingkai/Documents/anime/deploy/docker-compose.yml config --quiet && echo "YAML OK"`
Expected: `YAML OK`（無錯誤輸出即代表語法正確；此指令只解析設定檔，不會啟動任何容器，不影響 production）

- [ ] **Step 6: Commit**

```bash
git add deploy/docker-compose.yml
git commit -m "$(cat <<'EOF'
feat: production 部署為 backend/scheduler 加縮圖持久化 volume

backend 與 scheduler 是兩個獨立容器但共用同一份 image：scheduler
執行排程 import 時產生新縮圖，backend 提供 API 讀取縮圖，兩者必須
掛載同一個 named volume，否則 scheduler 寫入的檔案 backend 讀不到。
容器重建（docker compose pull && up -d）時 volume 內容不受影響。
EOF
)"
```

---

## Task 10: 前端清理暫時除錯程式碼

**Files:**
- Modify: `frontend/app/components/AnimeGridCard.vue`

**背景**：診斷白卡問題的過程中，在元件裡加了一整段 `imgDebug`（`?imgdebug=1` 觸發的除錯 log、`__cardDebug`/`__dumpCards` 全域掛載）。根因已確認並修正（縮圖 pipeline），這段診斷用程式碼不再需要，應移除避免留在生產程式碼裡。骨架佔位層（`data-imgph` 灰底 div）與 `revealImage`（`decode()` 後才淡入）是有效的體感改善，繼續保留。

- [ ] **Step 1: 確認目前檔案內 `imgDebug` 區塊的實際範圍**

Run: `grep -n "imgDebug\|dbgSnapshot\|dumpBlanks\|__cardDebug\|__dumpCards" /Users/sumingkai/Documents/anime/frontend/app/components/AnimeGridCard.vue`

以這次指令的實際行號為準，確認要刪除的範圍是從 `// --- TEMP image-loading debug` 註解開始，到對應的結尾註解結束的整個區塊，以及 `revealImage` 函式內含 `imgDebug` 的那一行 log。

- [ ] **Step 2: 移除整段 `imgDebug` 區塊**

刪除從這行開始：
```js
// --- TEMP image-loading debug (enable with ?imgdebug=1) -----------------
```
到對應結尾分隔註解（`// ---...---`）結束（含）的整個區塊，包含 `imgDebug` 變數宣告、`dbgSnapshot`、`dumpBlanks`、`onMounted`/`onBeforeUnmount`/`watch` 內對應的 debug 邏輯。

- [ ] **Step 3: 移除 `revealImage` 內的 debug log 那一行**

在 `revealImage` 函式中，刪除含 `if (imgDebug) console.debug(...)` 的那一行。

刪除後 `revealImage` 應為：
```js
function revealImage() {
  const el = imgEl.value
  if (!el) return
  if (el.decode) {
    el.decode().catch(() => {}).finally(() => { imageLoaded.value = true })
  } else {
    imageLoaded.value = true
  }
}
```

- [ ] **Step 4: 確認 `data-imgph` 屬性與骨架 div 仍保留（不要動）**

Run: `grep -n "data-imgph" /Users/sumingkai/Documents/anime/frontend/app/components/AnimeGridCard.vue`
Expected: 仍存在於模板中的灰底佔位 div

- [ ] **Step 5: 型別檢查確認沒有殘留引用**

Run: `cd /Users/sumingkai/Documents/anime/frontend && npx vue-tsc --noEmit -p tsconfig.json`
Expected: 無輸出（通過)

- [ ] **Step 6: 確認 debug 相關字串已完全移除**

Run: `grep -n "imgDebug\|imgdebug\|__cardDebug\|__dumpCards" /Users/sumingkai/Documents/anime/frontend/app/components/AnimeGridCard.vue`
Expected: 無輸出（完全清除)

- [ ] **Step 7: Commit**

```bash
git add frontend/app/components/AnimeGridCard.vue
git commit -m "$(cat <<'EOF'
chore: 移除卡片圖片載入問題診斷用的暫時除錯程式碼

白卡根因（原圖過大導致下載跟不上快速滾動）已透過後端縮圖 pipeline
解決，診斷過程加入的 ?imgdebug=1 除錯機制不再需要。骨架佔位層與
decode 後才淡入的邏輯保留，仍是有效的體感改善。
EOF
)"
```

---

## Task 11: 端對端驗證（backfill + 實際瀏覽器驗證）

**Files:** 無新檔案，純驗證步驟。

- [ ] **Step 1: 確認所有服務都用最新 image 啟動**

Run:
```bash
cd /Users/sumingkai/Documents/anime
docker compose up --build -d
docker compose ps
```
Expected: `backend`、`frontend`、`mysql`、`scheduler` 皆為 running

- [ ] **Step 2: 執行完整後端測試套件**

Run: `docker compose exec backend php artisan test`
Expected: 全部 PASS（涵蓋 Task 1-7 的新測試 + 既有測試）

- [ ] **Step 3: 對現有 2664 筆資料跑一次真正的 backfill（會真的對 acgsecrets 發請求，非 fake）**

Run: `docker compose exec backend php artisan anime:generate-thumbnails`
Expected: 進度條跑完，結尾顯示類似 `Done: 2664/2664 thumbnail(s) generated successfully.`（若有少量因原圖失效等原因失敗屬正常，比對成功數是否接近總數即可）

- [ ] **Step 4: 確認縮圖檔案已產生且體積遠小於原圖**

Run:
```bash
docker compose exec backend sh -c "ls /app/storage/app/public/covers | wc -l"
docker compose exec backend sh -c "du -sh /app/storage/app/public/covers"
```
Expected: 檔案數接近 2664；總體積在數十 MB 範圍內（遠小於 2664 × 125KB ≈ 333MB 的原圖總量）

- [ ] **Step 5: 確認 API 回傳的是縮圖網址**

Run: `curl -s "http://localhost:8080/anime?year=2026&season=summer" | head -c 2000`
Expected: `image_url` 欄位值包含 `/storage/covers/` 而非 `static.acgsecrets.hk`

- [ ] **Step 6: 瀏覽器實測快速滾動，確認白卡消失**

開啟 `http://localhost:3000/seasonal`，快速滾動數次。
Expected: 不再出現「有徽章、無圖」的空白卡片；縮圖載入速度明顯比原圖快。

- [ ] **Step 7: 執行前端既有測試，確認沒有回歸**

Run: `cd /Users/sumingkai/Documents/anime/frontend && npm run test`
Expected: 全部 PASS

（此 Task 不需要 commit——純驗證，若驗證中發現問題，回到對應 Task 修正並補 commit。）
