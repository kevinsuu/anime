# 動漫庫

動漫追番網站：新番瀏覽、個人追番清單、自訂收藏、公開分享清單頁。

## 功能範圍

- Nuxt 4（SPA 模式）+ Nuxt UI 前端，響應式 RWD 介面。
- Laravel REST API 後端。
- MySQL 資料庫。
- Google OAuth 登入，後端簽發短效 JWT + 可續期的 refresh token。
- 個人動漫清單、是否看過、評價、備註、自訂收藏。
- 動漫資料庫依年份瀏覽與關鍵字搜尋。
- 動漫詳情頁：角色、聲優、製作人員、主題曲、宣傳片、串流平台連結。
- 動漫資料透過 acgsecrets.hk 爬蟲每週自動同步（無手動新增介面，見〈資料匯入管線〉）。
- 公開分享清單頁與收藏頁。
- 手機版響應式介面。
- Docker Compose 本機開發環境；GitHub Actions + GHCR 正式部署。

## 本機啟動動漫庫

複製環境變數範例：

```bash
cp .env.example .env
```

啟動服務：

```bash
docker compose up --build
```

後端容器會把本機 `./backend` 掛到容器 `/app`，並使用 Laravel 的 `php artisan serve` 啟動（僅供本機開發；正式環境改用 PHP-FPM + nginx，見〈部署〉）。修改一般後端程式碼時，容器會直接讀到本機最新檔案；需要時才重啟或重建 Docker。

**所有後端 `php artisan`／`composer` 指令只能在容器內執行**，本機沒有安裝 PHP。

Docker 更新規則：

- 改 `backend/app`、`backend/routes`：通常下一次 HTTP request 就會生效，不需要重啟。
- 改 `frontend/app`（pages、components、composables、utils）、`frontend/nuxt.config.ts`：Nuxt dev server 會自動熱更新瀏覽器，不需要重啟。
- 改 `backend/config`：檔案會同步進容器；如果 Laravel config cache 已開啟，需清 cache 或重啟 backend。
- 改 `backend/database/migrations`：檔案會同步進容器，但還要執行 `docker compose exec backend php artisan migrate`。
- 改 `.env` 或 `docker-compose.yml` 的 `environment`：需要重啟相關 container，例如 `docker compose restart backend`。
- 改 `frontend/package.json`、`frontend/package-lock.json` 或前端套件：需要重新安裝依賴，通常用 `docker compose up --build frontend` 較乾淨。
- 改 `backend/composer.json`、`backend/composer.lock` 或 PHP 套件：需要重新安裝依賴，通常用 `docker compose up --build backend` 較乾淨。
- 改 `backend/Dockerfile` 或 `frontend/Dockerfile`：需要重建 image，使用 `docker compose up --build <service>`。

前端容器也會把本機 `./frontend` 掛到容器 `/app`。`docker-compose.yml` 另有 `frontend-node-modules` volume，讓 container 自己管理 Linux 版依賴，避免本機 `node_modules` 和 container 互相污染。

服務位置：

- 前端：http://localhost:3000
- 後端：http://localhost:8080
- phpMyAdmin：http://localhost:8081

### 本機資料庫備份

`db-backup` 容器會依 Asia/Taipei 時區，每天晚上 22:00 後自動備份一次 MySQL。備份檔會寫入本機 `backups/mysql/`，格式為 `anime_tracker_YYYY-MM-DD_HH-MM-SS.sql.gz`；預設保留 30 天，可在 `.env` 用 `BACKUP_RETENTION_DAYS` 調整。

備份容器會隨整組服務啟動；若只需啟動資料庫與備份排程：

```bash
docker compose up -d mysql db-backup
```

正式主機只保留 Compose 檔時，先在已登入 GHCR 的本機建置並推送備份 image（預設為 `ghcr.io/kevinsuu/anime-db-backup:latest`）：

```bash
./scripts/build-backup-image.sh
```

上面的預設模式會推送 image，因此 GHCR 登入必須使用具有 `write:packages` 權限的 Personal Access Token (classic)。如果只想驗證本機能否成功建置、不推送到 GHCR：

```bash
BACKUP_OUTPUT=load ./scripts/build-backup-image.sh
```

只載入本機的 image 無法被遠端主機使用；遠端 Compose 要能拉取時，仍須推送到 registry，或另行用 `docker save`／`docker load` 傳輸。

也可以指定其他 image tag 或遠端主機架構：

```bash
BACKUP_IMAGE=ghcr.io/kevinsuu/anime-db-backup:v1 BACKUP_PLATFORMS=linux/amd64 ./scripts/build-backup-image.sh
```

接著在遠端 `.env` 設定相同的 `BACKUP_IMAGE`，再執行：

```bash
docker compose pull db-backup
docker compose up -d db-backup
```

立即手動建立一份備份：

```bash
docker compose run --rm --entrypoint /usr/local/bin/backup.sh db-backup
```

還原前請先確認目標資料庫，以下指令會把指定備份匯入目前 `.env` 的 `DB_DATABASE`：

```bash
gunzip -c backups/mysql/<備份檔>.sql.gz | docker compose exec -T mysql sh -c 'exec mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
```

備份是主機上的檔案，不會因 `docker compose down -v` 刪除 named volume 而一起消失；但仍建議定期把 `backups/mysql/` 複製到另一顆磁碟或雲端。

本機 `docker-compose.yml` 預設開啟 `DEV_AUTH_BYPASS=true` 與前端開發登入按鈕，方便不設定 Google OAuth 時測試流程。**正式環境的 `DEV_AUTH_BYPASS` 只有在 `APP_ENV=local` 時才會生效**（即使誤設為 `true` 也不會在正式環境開後門）。

### 執行測試

```bash
docker compose exec backend php artisan test              # 全部後端測試
docker compose exec backend php artisan test --filter=X   # 單一測試
```

```bash
cd frontend && npm run test   # 前端測試（Vitest，不需要 Docker）
```

## 資料匯入管線

動漫資料庫**沒有手動新增介面**，內容完全由爬蟲管線提供，並以 JSON 快照形式進版控（`backend/database/seed/acgsecrets/*.json`，一季一檔）：

```
爬蟲（ScrapeAcgSecrets） → 解析 HTML（AcgSecretsParser） → 寫入 JSON 快照
  → 匯入（ImportAcgSecrets） → upsert 進 anime 與相關明細表
```

排程容器（`scheduler`，執行 `php artisan schedule:work`）每週日 05:00 自動執行。手動執行：

```bash
docker compose exec backend php artisan anime:scrape-acgsecrets [--all]
docker compose exec backend php artisan anime:import-acgsecrets
```

`--all` 會爬全部季度；預設只爬近兩年到目前季度。若要擴充或修正動漫資料，應該調整 parser／importer 邏輯或直接編輯 JSON 快照後重新匯入，而不是手動寫入資料庫。

## 後端架構

```text
backend/
├── app/
│   ├── Console/Commands/       # ScrapeAcgSecrets、ImportAcgSecrets
│   ├── Http/
│   │   ├── Controllers/Api/    # AnimeController、AnimeListController、AuthController、CollectionController
│   │   └── Middleware/         # AuthenticateJwt（別名 'jwt'）
│   ├── Models/                 # Anime 及其明細關聯（cast/staff/themes/trailers/links）等 Eloquent model
│   └── Services/
│       ├── AnimeCatalog/       # AcgSecretsClient、AcgSecretsParser、AnimeImportService、SeasonResolver
│       ├── Auth/                # GoogleTokenVerifier、JwtService、RefreshTokenService
│       └── Shared/              # SlugGenerator（公開分享連結）
├── bootstrap/
├── config/
├── database/
│   ├── migrations/
│   ├── seed/acgsecrets/        # 爬蟲產出的 JSON 快照
│   └── seeders/
├── docker/                     # 正式環境用的 nginx.conf、entrypoint.sh
├── public/
├── routes/
└── tests/
```

`routes/api.php` 沒有 `/api` 前綴（`apiPrefix: ''`）；正式環境由外層 nginx 把 `/api/*` rewrite 到後端根路徑，讓前後端共用同一個網域。

## 前端架構

Nuxt 4（`ssr: false`，純 SPA 模式）搭配 Nuxt UI：

```text
frontend/
├── app/
│   ├── components/        # AppHeader、AppMobileNav、AnimeGridCard 等共用元件
│   ├── composables/       # useApi、useSession、useSeasonalCatalog
│   ├── middleware/        # auth 路由保護
│   ├── pages/             # 檔案式路由（seasonal、catalog、list、settings、login、anime/[id]、public/[slug]）
│   └── utils/             # normalize.ts（資料正規化、亂碼修復、標籤配色）
├── public/
├── nuxt.config.ts
└── package.json
```

- `app/pages/index.vue` 直接導向 `/seasonal`（新番表為首頁）。
- `app/middleware/auth.ts` 搭配 `definePageMeta({ middleware: 'auth' })` 保護 `/list`、`/settings`。
- `app/composables/useSession.ts` 是**模組層級單例**（非每次呼叫都重建），確保登入狀態在所有元件間一致；首次於瀏覽器端使用時才從 `localStorage` 水合。
- `app/composables/useApi.ts` 包裝 API 呼叫，401 時自動用 refresh token 換發新 JWT 並重試一次。
- `/catalog` 預設瀏覽當前年份並在前端分頁（40 筆/頁），瀏覽過的年份會快取在 session 內；關鍵字搜尋則解除年份限制搜全庫。
- 環境變數一律用 `NUXT_PUBLIC_*` 前綴（會被打進前端 bundle，不能放真正的密鑰）。

## 認證機制

Google Identity Services 在前端簽發 ID token → `POST /auth/google` 驗證後（`GoogleTokenVerifier`）簽發短效 JWT（1 小時）+ 輪替式 refresh token（30 天，雜湊後存於 `refresh_tokens` 表，單次使用——每次 `POST /auth/refresh` 都會撤銷舊 token 並換發新的）。

## 主要 API

- `POST /auth/google`、`POST /auth/refresh`、`POST /auth/logout`
- `GET /me`
- `GET /anime`、`GET /anime?year=<year>`、`GET /anime/{id}`
- `GET /my/anime-list`、`POST /my/anime-list`、`PATCH /my/anime-list/{itemId}`、`DELETE /my/anime-list/{itemId}`
- `GET /my/collections`、`POST /my/collections`、`PATCH /my/collections/{id}`、`DELETE /my/collections/{id}`
- `POST /my/collections/{id}/items`、`DELETE /my/collections/{id}/items/{listItemId}`
- `GET /public/lists/{slug}`、`GET /public/collections/{slug}`
- `POST /me/share-slug/regenerate`

受保護端點需帶：

```http
Authorization: Bearer <jwt>
```

## 部署

正式環境用 GitHub Actions 建置 Docker image、推送到 GHCR（`ghcr.io/kevinsuu/anime-{backend,scheduler,frontend}`），透過 `deploy/docker-compose.yml` 以 SSH 部署。完整的一次性主機設定、必要的 GitHub Secrets、回滾步驟見 `deploy/README.md`。

- push 到任何分支 → 只跑 CI（`.github/workflows/ci.yml`）：後端測試、前端 build 檢查、三個 image 的 build dry-run。不會部署。
- push `vX.Y.Z` 格式的 tag → `.github/workflows/deploy.yml` 建置並推送 image，再 SSH 進主機執行 `docker compose pull && up -d`。

`backend/Dockerfile.production`（PHP-FPM + nginx）與 `backend/Dockerfile.scheduler` 跟本機開發用的 `backend/Dockerfile`（`php artisan serve`）是分開的——開發用伺服器不適合正式環境的並發流量。

不要提交任何 `.env`。Repository 只保留 `.env.example`、`deploy/.env.production.example`。

## Commit 規範

所有 commit 訊息請遵循 `docs/commit-guidelines.md`（中文描述 + 英文 type 前綴）。
