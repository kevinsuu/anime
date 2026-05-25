# 追番格納庫

依照 `docs/superpowers/specs/2026-05-25-anime-tracker-mvp-design.md` 建立的動漫追番網站 MVP。

## 功能範圍

- Vue + Vite 前端 SPA。
- PHP REST API 後端。
- MySQL 資料庫。
- Google OAuth ID token 登入，後端簽發短效 JWT。
- 個人動漫清單、是否看過、評價、備註。
- 動漫搜尋與手動建立。
- 公開分享清單頁。
- 手機版響應式介面。
- Docker Compose 本機開發環境。

## 本機啟動

複製環境變數範例：

```bash
cp .env.example .env
```

啟動服務：

```bash
docker compose up --build
```

服務位置：

- 前端：http://localhost:5173
- 後端：http://localhost:8080
- phpMyAdmin：http://localhost:8081

本機 `docker-compose.yml` 預設開啟 `DEV_AUTH_BYPASS=true` 與前端開發登入按鈕，方便不設定 Google OAuth 時測試流程。正式環境必須關閉。

## API

主要端點：

- `POST /auth/google`
- `GET /me`
- `GET /anime`
- `POST /anime`
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

前端部署到 GitHub Pages 時，只能放公開設定：

- `VITE_API_BASE_URL`
- `VITE_GOOGLE_CLIENT_ID`

後端部署到 GCP Cloud Run 時，以下值只能放在 Cloud Run 環境變數或 Secret Manager：

- `DB_PASSWORD`
- `JWT_SECRET`
- Cloud SQL 連線設定
- 允許來源 `ALLOWED_ORIGINS`

不要提交 `.env`。repository 只保留 `.env.example`。
