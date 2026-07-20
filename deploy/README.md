# 動漫庫專案與部署說明

## 專案說明

動漫庫是部署於 `anime.kaistarstudio.me` 的動畫資料與追番服務。使用者可以依季度、年份、星期與標籤瀏覽作品，查看角色、聲優、製作人員、主題曲、宣傳片及串流平台資訊，並透過 Google OAuth 建立個人追番清單、自訂收藏與公開分享頁面。

作品資料由排程服務定期擷取 acgsecrets.hk，並透過 Bangumi API 補充集數等資訊。匯入程序會更新 MySQL 資料庫並產生 WebP 封面縮圖，前端不提供手動新增作品功能。

## 專案目的

- 集中整理每季新番與歷年動畫資訊，降低跨網站查找資料的成本。
- 提供個人化的觀看狀態、評分、備註及收藏管理。
- 讓使用者能以公開連結分享追番清單與收藏內容。
- 以自動爬取、JSON 快照及可重複匯入的流程維護資料來源。
- 建立可測試、可回滾且能透過容器穩定部署的正式環境。

## 系統架構

```text
使用者瀏覽器
    │ HTTPS
    ▼
主機 nginx（TLS 與反向代理）
    ├── /          → frontend：Nuxt 4 SSR（127.0.0.1:3000）
    ├── /api/*     → backend：Laravel API（127.0.0.1:8080）
    └── /storage/* → backend：WebP 封面縮圖

backend ───────────────┐
scheduler ─────────────┼──→ MySQL 8.4
                       └──→ backend-storage-public 具名儲存卷

acgsecrets.hk ──→ scheduler ──→ JSON 快照／匯入流程
Bangumi API ─────→ scheduler ──→ 集數資料補充
```

| 元件 | 技術 | 職責 |
|---|---|---|
| `frontend` | Nuxt 4、Vue 3、Nuxt UI | SSR 頁面、使用者介面、SEO 與瀏覽器端 API 呼叫 |
| `backend` | Laravel、PHP-FPM、nginx | REST API、Google OAuth、JWT、資料存取、migration 與封面靜態檔案 |
| `scheduler` | Laravel Console | 每週爬取、資料補充、匯入與 WebP 縮圖產生 |
| `mysql` | MySQL 8.4 | 動畫資料、使用者、清單、收藏與 refresh token 儲存 |
| 主機 nginx | 原生 nginx、certbot | 對外 80/443、TLS 憑證與路徑分流 |

正式環境使用三個應用程式映像檔：`backend`、`scheduler`、`frontend`。MySQL 資料存放於 `mysql-data`，封面縮圖存放於 `backend-storage-public`，兩者皆透過 Docker 具名儲存卷跨容器保留。

## 部署概覽

- 推送至任何分支：只執行 CI（後端測試、前端建置檢查、Docker 映像檔建置檢查），不會部署。
- 執行 `git tag vX.Y.Z && git push --tags`：建置三個映像檔並推送至 GHCR，接著透過 SSH 連線至部署主機，執行 `docker compose pull && up -d`。

映像檔：`ghcr.io/kevinsuu/anime-{backend,scheduler,frontend}:vX.Y.Z`，以及 `:latest`。

## 一次性主機設定

本專案的 `deploy/docker-compose.yml` **不會**自行啟動 nginx 或 certbot。主機原生的 nginx（systemd 服務）負責 TLS 與反向代理；應用程式容器只綁定 `127.0.0.1:<port>`，不直接暴露於外部網路。

本專案需要的 nginx 路由範本請參閱 [`../docs/shared-nginx-deployment.md`](../docs/shared-nginx-deployment.md)。完整主機 inventory、其他服務與私人路徑不應記錄在公開 repository。

1. 在部署主機建立目錄，並複製 `deploy/docker-compose.yml`：

   ```
   ssh <user>@<host> mkdir -p ~/anime-deploy
   scp deploy/docker-compose.yml <user>@<host>:~/anime-deploy/
   ```

2. 將 `deploy/.env.production.example` 複製成主機上的 `~/anime-deploy/.env`，並填入正式設定值，包括：

   - 資料庫密碼。
   - 使用 `openssl rand -base64 48` 產生的 `JWT_SECRET`。
   - Google OAuth client ID（此值會公開於前端，不是秘密）。
   - 在本機執行 `php artisan key:generate --show` 產生的 `APP_KEY`。

3. GHCR 映像檔預設為私人套件。先在部署主機登入一次，讓 `docker compose pull` 可以拉取映像檔：

   ```
   docker login ghcr.io -u <github-username>
   ```

   密碼請使用具備 `read:packages` 權限的 GitHub PAT。

4. 執行 `docker compose up -d`。`backend` 會綁定 `127.0.0.1:8080`，`frontend` 會綁定 `127.0.0.1:3000`。

5. 依照 [`../docs/shared-nginx-deployment.md`](../docs/shared-nginx-deployment.md)，在主機共用 nginx 設定中新增或更新 `anime.kaistarstudio.me` 伺服器區塊，接著簽發憑證：

   ```
   sudo certbot certonly --nginx -d anime.kaistarstudio.me
   sudo nginx -t && sudo systemctl reload nginx
   ```

   憑證會自動續期。certbot 第一次安裝時會建立 systemd 計時器或 cron 排程，可用 `systemctl list-timers | grep certbot` 確認。`certbot renew` 會沿用相同的 `--nginx` 外掛，因此後續不需手動續期。

6. 將 `anime.kaistarstudio.me` 的 DNS A record 指向部署主機的公開 IP。

7. 在 Google Cloud Console 將 `https://anime.kaistarstudio.me` 加入 OAuth 用戶端的「已授權的 JavaScript 來源」。

## GitHub 儲存庫密鑰

設定位置：Settings → Secrets and variables → Actions。

| Secret | 設定值 |
|---|---|
| `DEPLOY_HOST` | 部署主機的 IP 或網域 |
| `DEPLOY_USER` | SSH 使用者名稱 |
| `DEPLOY_SSH_KEY` | 可登入該使用者的私鑰；對應公鑰需加入主機的 `~/.ssh/authorized_keys` |
| `DEPLOY_PATH` | 主機上部署目錄的絕對路徑，例如 `/home/<user>/anime-deploy` |
| `NUXT_PUBLIC_GOOGLE_CLIENT_ID` | 與主機 `.env` 相同的 Google OAuth client ID |

Actions 會自動提供推送至 GHCR 所需的 `GITHUB_TOKEN`，不需額外設定。請確認儲存庫的套件可見性與權限允許工作流程發布，並將 Settings → Actions → General → Workflow permissions 設為 Read and write。

## 發布版本

```
git tag v1.0.0
git push --tags
```

觀察 GitHub Actions 執行結果。成功後，在主機執行 `docker compose ps`，`backend`、`scheduler`、`frontend` 與 `mysql` 都應處於正常狀態。

後端啟動腳本會在啟動 PHP-FPM 與 nginx 前執行 `php artisan migrate --force` 和 `php artisan storage:link --force`。資料庫 migration 只有這一個執行來源，首次部署後不需要另外手動執行資料庫設定。

## 回滾

編輯主機上的 `.env`，將三個 `*_IMAGE` 變數改回先前版本的 tag，接著執行：

```
docker compose pull
docker compose up -d
```

## 首次部署：匯入正式資料

`scheduler` 每週日 05:00 執行 `anime:scrape-acgsecrets` 並自動匯入資料。因此，首次部署後若不手動匯入，動漫資料庫會維持空白直到下一次週日排程。若要立即建立資料，請執行：

```
docker compose exec backend php artisan anime:scrape-acgsecrets --all
docker compose exec backend php artisan anime:import-acgsecrets
```

## 首次啟用縮圖功能

產生縮圖前，先將 [`docs/shared-nginx-deployment.md`](../docs/shared-nginx-deployment.md) 中的 `/storage/` 反向代理加入主機 nginx 設定，完成設定檢查並重新載入 nginx。新版 `backend` 映像檔啟動後，執行一次既有資料縮圖補齊：

```
docker compose exec backend php artisan anime:generate-thumbnails
```

若只需先補齊目前要發布的季度，可用範圍選項縮短執行時間，再於離峰時段執行完整補齊：

```bash
docker compose exec backend php artisan anime:generate-thumbnails --year=2026 --season=summer
```

縮圖編碼設定更新後，可在同一範圍加上 `--force` 重新產生既有縮圖。

確認 `/api/anime` 回傳的 `image_url` 指向 `/storage/covers/*.webp`，而且請求回應為 `200 image/webp`。

具名儲存卷 `backend-storage-public` 會在容器替換後繼續保留已產生的封面。後續匯入則由 `scheduler` 映像檔自動產生縮圖。

## 個人已看清單（seed/mylist）

`backend/database/seed/mylist/` 使用與 acgsecrets 相同的 JSON 結構保存個人 seed 資料；`anime:import-acgsecrets` 會同時匯入兩個目錄。

若主機 `.env` 設有 `MYLIST_OWNER_EMAIL`，匯入程序也會將 `seed/mylist/watched.json` 內的所有項目標記為該使用者已看。系統會預先建立該帳號，並在使用者第一次透過 Google 登入時自動完成綁定。請在部署主機 `.env` 加入：

```
MYLIST_OWNER_EMAIL=you@example.com
```
