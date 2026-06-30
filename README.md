# 動漫庫

依照 `docs/superpowers/specs/2026-05-25-anime-tracker-mvp-design.md` 建立的動漫追番網站 MVP。

## 功能範圍

- Nuxt 4（SPA 模式）+ Nuxt UI 前端，論壇風格 RWD 介面。
- Laravel REST API 後端。
- MySQL 資料庫。
- Google OAuth ID token 登入，後端簽發短效 JWT。
- 個人動漫清單、是否看過、評價、備註。
- 動漫搜尋與手動建立。
- Bangumi 新番資料匯入，支援季度同步與繁中優先標題。
- 公開分享清單頁。
- 手機版響應式介面。
- Docker Compose 本機開發環境。

## 本機啟動動漫庫

複製環境變數範例：

```bash
cp .env.example .env
```

啟動服務：

```bash
docker compose up --build
```

後端容器會把本機 `./backend` 掛到容器 `/app`，並使用 Laravel 13 的 `php artisan serve` 啟動。修改一般後端程式碼時，容器會直接讀到本機最新檔案；需要時才重啟或重建 Docker。

Docker 更新規則：

- 改 `backend/app`、`backend/routes`：通常下一次 HTTP request 就會生效，不需要重啟。
- 改 `frontend/app`（pages、components、composables、utils）、`frontend/nuxt.config.ts`：Nuxt dev server 會自動熱更新瀏覽器，不需要重啟。
- 改 `backend/config`：檔案會同步進容器；如果 Laravel config cache 已開啟，需清 cache 或重啟 backend。
- 改 `backend/database/migrations`：檔案會同步進容器，但還要執行 `docker compose exec backend php artisan migrate`。
- 改 `.env` 或 `docker-compose.yml` 的 `environment`：需要重啟相關 container，例如 `docker compose restart backend`。
- 改 `frontend/package.json`、`frontend/package-lock.json` 或前端套件：需要重新安裝依賴，通常用 `docker compose up --build frontend` 或 `docker compose restart frontend` 讓 container 重新跑 `npm install`。
- 改 `backend/composer.json`、`backend/composer.lock` 或 PHP 套件：需要重新安裝依賴，通常用 `docker compose up --build backend` 較乾淨。
- 改 `backend/Dockerfile` 或 `frontend/Dockerfile`：需要重建 image，使用 `docker compose up --build backend` 或 `docker compose up --build frontend`。

前端容器也會把本機 `./frontend` 掛到容器 `/app`。`docker-compose.yml` 另有 `frontend-node-modules` volume，讓 container 自己管理 Linux 版依賴，避免本機 `node_modules` 和 container 互相污染。Compose 也開啟 `CHOKIDAR_USEPOLLING`/`WATCHPACK_POLLING`，讓 Docker Desktop 裡的 Nuxt dev server 比較穩定偵測檔案變更。

後續如果任務包含上述必要改動，應同步重啟或重建對應 Docker 服務，再做驗證。

後端啟動時會自動執行：

```bash
composer install
php artisan migrate --force
php artisan db:seed --force
```

服務位置：

- 前端：http://localhost:3000
- 後端：http://localhost:8080
- phpMyAdmin：http://localhost:8081

本機 `docker-compose.yml` 預設開啟 `DEV_AUTH_BYPASS=true` 與前端開發登入按鈕，方便不設定 Google OAuth 時測試流程。正式環境必須關閉。

## 新番資料同步

網站上可從「本季新番」頁選擇年份與季度，按下「同步新番資料」後由後端呼叫 Bangumi API，匯入完成後頁面會刷新該季海報牆。

這個按鈕會呼叫受保護 API：

```http
POST /anime/sync-seasonal
Authorization: Bearer <jwt>
Content-Type: application/json

{
  "year": 2026,
  "season": "spring"
}
```

查看指定季度資料：

```http
GET /anime?year=2026&season=spring
```

同步指定季度的新番資料：

```bash
docker compose exec backend php artisan anime:sync-seasonal --year=2026 --season=spring
```

`season` 可用：`winter`、`spring`、`summer`、`fall`。未帶參數時會同步目前日期所在季度。

## 後端架構

後端已重構為 Laravel 專案架構，並保留 feature-based 的命名邊界：

```text
backend/
├── app/
│   ├── Console/Commands/
│   ├── Exceptions/
│   ├── Http/
│   │   ├── Controllers/Api/
│   │   └── Middleware/
│   ├── Models/
│   └── Services/
│       ├── AnimeCatalog/
│       ├── Auth/
│       └── Shared/
├── bootstrap/
├── config/
├── database/
│   ├── migrations/
│   └── seeders/
├── public/
├── routes/
└── tests/
```

- `app/`: 專案自己的後端程式碼。`Http/Controllers/Api` 是 API controller，`Http/Middleware` 是 JWT middleware，`Models` 是 Eloquent model，`Services` 是外部 API、JWT、Google token 驗證與季度規則。
- `routes/`: API 路由入口。現在主要看 `routes/api.php`，維持 `/anime`、`/auth/google` 等既有路徑。
- `database/`: 資料表 migration 與 seed 測試資料。`migrations` 定義 schema，`seeders` 放本機範例資料。
- `config/`: Laravel 設定檔，讀取 `.env` 後提供 DB、CORS、JWT、Google、Bangumi 等設定。
- `bootstrap/`: Laravel 啟動設定，不是前端 Bootstrap CSS。`bootstrap/app.php` 會註冊路由、middleware、exception handler。
- `public/`: 後端 HTTP 對外入口，只保留 `index.php` 與 web server 需要的公開檔；不是前端專案目錄。
- `storage/`: Laravel runtime 產生的檔案，例如 logs、cache、framework 暫存。目錄要存在，但內容通常不提交，也不用手動改。
- `vendor/`: Composer 安裝的第三方 PHP 套件，等同 Node 的 `node_modules`。本機會存在，但由 `.gitignore` 排除，不應提交，也不用手動改。
- `tests/`: PHPUnit 測試，分成 Feature API 測試與 Unit 測試。

## 前端架構

前端為 Nuxt 4（`ssr: false`，純 SPA 模式）搭配 Nuxt UI（Tailwind CSS）：

```text
frontend/
├── app/
│   ├── assets/css/        # Tailwind / Nuxt UI 進入點
│   ├── components/        # AppHeader、AppMobileNav、AnimeGridCard 等共用元件
│   ├── composables/       # useApi、useSession、useSeasonalCatalog
│   ├── middleware/        # auth 路由保護
│   ├── pages/             # 檔案式路由（seasonal、catalog、list、settings、login、public/[slug]）
│   └── utils/             # normalize.ts（資料正規化、亂碼修復）
├── public/
├── nuxt.config.ts
└── package.json
```

- `app/pages/`：Nuxt 檔案式路由，`index.vue` 會直接導向 `/seasonal`（新番表為首頁）。
- `app/middleware/auth.ts`：搭配 `definePageMeta({ middleware: 'auth' })` 保護 `/list`、`/settings`。
- `app/composables/useApi.ts`：包裝 Laravel API 呼叫，從 `localStorage` 讀取 JWT。
- 環境變數一律用 `NUXT_PUBLIC_*` 前綴（會被打進前端 bundle，不能放真正的密鑰）。

## API

主要端點：

- `POST /auth/google`
- `GET /me`
- `GET /anime`
- `GET /anime?year=<year>&season=<winter|spring|summer|fall>`
- `POST /anime`
- `POST /anime/sync-seasonal`
- `GET /my/anime-list`
- `POST /my/anime-list`
- `PATCH /my/anime-list/{itemId}`
- `DELETE /my/anime-list/{itemId}`
- `GET /public/lists/{slug}`
- `POST /me/share-slug/regenerate`

受保護端點需帶：

```http
Authorization: Bearer <jwt>
```

## 部署提醒

前端部署時，只能放公開設定（會被打進前端 bundle，公開可見）：

- `NUXT_PUBLIC_API_BASE_URL`
- `NUXT_PUBLIC_GOOGLE_CLIENT_ID`
- `NUXT_PUBLIC_ENABLE_DEV_LOGIN`（正式環境必須為 `false` 或不設定）

後端部署到 GCP Cloud Run 時，以下值只能放在 Cloud Run 環境變數或 Secret Manager：

- `DB_PASSWORD`
- `JWT_SECRET`
- Cloud SQL 連線設定
- 允許來源 `ALLOWED_ORIGINS`

不要提交 `.env`。repository 只保留 `.env.example`。
