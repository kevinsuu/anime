# 動畫封面縮圖 Pipeline 設計

## 背景與問題

`/seasonal`、`/catalog` 頁面的卡片（[AnimeGridCard.vue](../../../frontend/app/components/AnimeGridCard.vue)）顯示寬度約 180~300px（`aspect-3/4`），但圖片來源 `anime.image_url` 直接指向 acgsecrets CDN 的原圖，實測寬度 2000~2560px、單張約 125KB。

`useProgressiveReveal`（rootMargin 1500px）在快速滾動時一次揭露 20~30 張卡片，`useLazyLoad` 幾乎同時把這些卡片的 `<img src>` 全部設定好，瀏覽器需同時下載數十張 125KB 原圖。下載速度跟不上滾動速度，造成卡片在圖片下載完成前呈現「有徽章、無圖」的空白/灰底狀態。已用瀏覽器實測（`imgdebug` 埋樁）確認：空白卡片的 `complete=false`、`naturalWidth` 為原圖尺寸（2000+），排除了先前懷疑的 CSS 疊層/decode 時序等問題——根因是下載體積過大。

Cloudflare Image Resizing 在來源網域（`static.acgsecrets.hk`）未開啟（實測 404），無法透過對方 CDN 縮圖；使用第三方免費代理服務（如 wsrv.nl）雖可行，但引入不可控的外部依賴。故採用後端自行縮圖並持有檔案的方案。

## 目標

- 卡片圖片改用後端產生的縮圖（WebP，寬度 400px），單張體積降至原圖的 15~20%（約 15~25KB）。
- 縮圖是**全站共用的靜態資源**：每筆 anime 資料只在 import 時產生一次，之後所有使用者請求到的都是同一份檔案，靠一般 HTTP 快取即可，不會重複產生。
- 縮圖產生失敗不能中斷既有的 import 流程，且不能讓卡片圖片「更差」——最壞情況退回目前的原圖 URL。
- 現有近千筆已入庫資料需要一次性補齊縮圖。
- 部署（Docker 容器重建）後縮圖檔案不能遺失。

## 架構

```
ScrapeAcgSecrets → JSON snapshot（不變）
ImportAcgSecrets → AnimeImportService::importRecord()
                      ├─ 既有欄位寫入（不變）
                      └─ ThumbnailService::generate($imageUrl, $animeId)
                            ├─ 下載原圖（HTTP GET，逾時保護）
                            ├─ Imagick：等比縮放至 width=400px，輸出 WebP
                            ├─ 存到 storage/app/public/covers/{animeId}.webp
                            └─ 回傳相對路徑 或 null（任何步驟失敗）
                      └─ anime.cover_image_path = 上面回傳值（可為 null）

AnimeController (index/show) / Anime Model accessor
  → 對外回傳的圖片 URL：
      cover_image_path 有值 → asset('storage/covers/{id}.webp')
      cover_image_path 為 null → 原始 image_url（fallback，即目前行為）

anime:generate-thumbnails（新 Artisan 命令）
  → 一次性掃描 cover_image_path IS NULL AND image_url IS NOT NULL 的既有資料，補產生縮圖
```

## 元件改動

### 1. Dockerfile（`backend/Dockerfile`、`backend/Dockerfile.production`）

加裝 Imagick PHP extension 與其系統依賴（`libmagickwand-dev` 等），兩個 Dockerfile 都要改，改動後兩邊都需要 `docker compose up --build backend`／CI 的 docker build 驗證。

### 2. Migration

`animes` 表新增欄位：

```php
$table->string('cover_image_path')->nullable()->after('image_url');
```

### 3. `ThumbnailService`（新檔案，`app/Services/AnimeCatalog/ThumbnailService.php`）

- 單一公開方法：`generate(string $imageUrl, int $animeId): ?string`
- 內部：下載（帶逾時，例如 10s）→ Imagick 讀取 → resize width=400（等比高度）→ `setImageFormat('webp')` → 寫入 `storage_path('app/public/covers/{animeId}.webp')`
- 任何例外（下載失敗、Imagick 解析失敗、非圖片內容）一律 catch，記錄 `Log::warning`，回傳 `null`——呼叫端不需要 try/catch。
- 回傳值是相對路徑（例如 `covers/123.webp`），由呼叫端或 accessor 組完整 URL。

### 4. `AnimeImportService::importRecord`

在寫入 `image_url` 欄位的同一個 fill 區塊之後，若 `record['cover_image']` 存在，呼叫 `ThumbnailService::generate()` 並將結果寫入 `cover_image_path`。若 anime 是既有記錄且 `import_hash` 未變（`wasUnchanged` 分支），跳過縮圖（該分支本來就不做任何寫入，維持不變）。

### 5. API 層圖片 URL 組裝

專案沒有用 API Resource class；`AnimeController::index`/`show` 都是手動組陣列、直接讀 `$anime->image_url`（[AnimeController.php:69,113](../../../backend/app/Http/Controllers/Api/AnimeController.php)）。因此改為 Eloquent attribute cast/accessor 最省改動：在 `Anime` model 對 `image_url` 欄位加一個 accessor（Laravel 11+ 的 `Attribute::make(get: ...)` 寫法），邏輯是 `cover_image_path` 有值時回傳 `asset('storage/' . cover_image_path)`，否則回傳原始 `image_url` 欄位值。兩個 Controller 呼叫點（index、show）完全不需要修改，`$anime->image_url` 自動變成解析後的值。前端 `normalizeAnime`（[normalize.ts](../../../frontend/app/utils/normalize.ts)）也不需改動——它只是讀取 API 回傳的 `imageUrl` 欄位。

### 6. 新 Artisan 命令 `anime:generate-thumbnails`

- 掃描 `cover_image_path IS NULL AND image_url IS NOT NULL` 的 anime，逐筆呼叫 `ThumbnailService::generate()` 並更新欄位。
- 分批處理（chunk，例如每批 50 筆）避免一次載入全部資料到記憶體。
- 輸出處理進度與失敗筆數摘要（沿用專案內其他 Artisan 命令的輸出風格）。

### 7. 部署持久化（`deploy/docker-compose.yml`）

為 backend 服務新增 named volume，掛載到 `/app/storage/app/public`，確保 `docker compose pull && up -d` 重建容器時縮圖檔案不遺失。需確認 `public/storage` symlink（`php artisan storage:link`）在容器啟動流程（entrypoint）中已建立或需要補上。本地 `docker-compose.yml` 視情況加對應 volume 以保持 dev/prod 行為一致。

### 8. 前端清理

移除本次診斷過程中加入 [AnimeGridCard.vue](../../../frontend/app/components/AnimeGridCard.vue) 的暫時除錯程式碼（`imgDebug`、`dbgSnapshot`、`dumpBlanks`、`__cardDebug`／`__dumpCards` 全域掛載、以及對應的 `console.debug` 呼叫）。骨架佔位層（`data-imgph` 的灰底 div）與 `revealImage`（decode 後才淡入）保留，兩者仍是有效的體感改善，只是不再是主要解法。

## 失敗處理策略

| 情境 | 行為 |
|---|---|
| 原圖下載失敗（逾時/404/非圖片） | `ThumbnailService::generate` 回傳 null，`cover_image_path` 維持/寫入 null，import 正常完成 |
| Imagick 縮圖過程拋例外 | 同上 |
| `cover_image_path` 為 null（含尚未 backfill 的舊資料） | API 回傳 fallback 到原始 `image_url`，前端行為等同目前現況 |
| 縮圖檔案已存在（重複 import 同一 anime） | 直接覆寫（檔名以 `anime_id` 命名，具冪等性） |

## 測試計畫

- `ThumbnailServiceTest`：成功產生縮圖（可用 fixture 小圖或 fake HTTP response）；下載失敗回傳 null；Imagick 解析失敗回傳 null。
- `AnimeImportServiceTest`：補一筆斷言，import 後 `cover_image_path` 有值（沿用現有測試的 fixture 慣例）。
- 手動驗證：`docker compose exec backend php artisan anime:generate-thumbnails` 跑一次 backfill，確認 `storage/app/public/covers/` 產生檔案且 API 回傳的 image_url 已切換。
- 前端不需新增測試（沒有邏輯改動，只是圖片來源 URL 變小），但建議跑一次 `/seasonal` 快速滾動的人工驗證確認白卡消失。

## 範圍外（Out of scope）

- 不處理縮圖的響應式多尺寸（srcset/2x），僅單一 400px WebP 版本。
- 不做 CDN 前置或圖片服務外包，直接由 nginx/Laravel 提供靜態檔案。
- 不修改 `ScrapeAcgSecrets`／`AcgSecretsParser`，原始 JSON snapshot 內容不變。
