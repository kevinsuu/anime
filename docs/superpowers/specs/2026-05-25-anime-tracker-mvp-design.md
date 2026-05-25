# 動漫追番網站 MVP 設計

## 目標

建立第一版可實際使用的動漫追番網站。MVP 聚焦在 Google OAuth 登入、個人動漫清單、評價、是否看過、公開分享、手機版支援、Docker 本機開發，以及部署時避免密鑰外流。

未來的 AI 爬取、每季動漫自動匯入、外部熱門排行榜、AI 推薦都不放進 MVP 主線。MVP 只需要保留清楚的擴充點，方便後續接上這些功能。

## 範圍

### MVP 內含

- Vue 單頁前端，部署到 GitHub Pages。
- PHP REST API，獨立部署到 GCP。
- MySQL 資料庫。
- 使用 Docker Compose 進行本機開發。
- Google OAuth 登入，後端驗證 Google ID token。
- 後端簽發 JWT 作為 API 授權憑證。
- 個人動漫清單管理：
  - 將動漫加入個人清單。
  - 標記是否看過。
  - 儲存個人評價。
  - 儲存可選的個人備註。
- 動漫資料：
  - 名稱。
  - 敘述。
  - 圖片 URL。
  - 可選別名。
- 使用者個人清單公開分享頁。
- 桌機與手機版響應式版面。
- `.env.example` 與必要環境變數說明。

### MVP 不包含

- AI 產生推薦。
- AI 網路搜尋或網頁爬取。
- 每季動漫自動匯入。
- 外部熱門排行榜。
- Email/密碼登入。
- 忘記密碼流程。
- 社交關係、追蹤、留言、按讚。
- 原生手機 App。

## 架構

系統採用前後端分離。

- 前端：Vue SPA，由 GitHub Pages 提供靜態檔案。
- 後端：PHP REST API，以容器形式部署到 GCP Cloud Run。
- 資料庫：MySQL。本機開發使用 Docker MySQL 容器，正式環境建議使用 Cloud SQL MySQL。
- 驗證流程：
  - 瀏覽器完成 Google 登入並取得 Google ID token。
  - 前端將 ID token 送到 `POST /auth/google`。
  - 後端使用 Google 公開金鑰與設定的 client ID 驗證 ID token。
  - 後端建立或更新使用者資料，接著回傳本系統的 JWT。
  - 前端後續呼叫 API 時帶上 `Authorization: Bearer <token>`。

前端不應取得任何後端私密資訊。公開的 client ID 可以放在前端建置結果中；私密金鑰、JWT 簽章密鑰、資料庫帳密、Google 驗證相關私密設定都只能存在後端環境變數或 GCP Secret Manager。

MVP 不應向瀏覽器簽發 refresh token。因為前端部署在公開靜態環境 GitHub Pages，後端 JWT 應設為短效。JWT 過期後，前端應要求使用者重新使用 Google 登入。

## 前端設計

### 頁面

- `Login`
  - Google 登入按鈕。
  - 簡潔的未登入狀態。
- `Home`
  - 使用本地動漫資料顯示簡單探索區。
  - 提供搜尋與個人清單入口。
- `Catalog/Search`
  - 依名稱或別名搜尋動漫。
  - 將動漫加入個人清單。
  - 若找不到動漫，允許手動建立名稱、敘述、圖片 URL。
- `My List`
  - 顯示使用者已儲存的動漫。
  - 可依全部、已看、未看篩選。
  - 可編輯是否看過、評價、備註。
  - 提供可複製的公開分享連結。
- `Public List`
  - 依分享 slug 或使用者公開 ID 顯示唯讀公開頁。
  - 顯示動漫名稱、圖片、敘述、是否看過、評價。
- `Settings`
  - 顯示 Google 帳號資訊。
  - 重新產生公開分享 slug。
  - 登出。

### 手機版行為

- 導覽列在小螢幕改為底部分頁列或精簡 header menu。
- 動漫卡片使用固定比例圖片區塊，避免載入圖片時版面跳動。
- 清單編輯控制項在小螢幕仍需容易操作，不應出現水平捲動。
- 表單在手機版採單欄排列。

## 後端設計

### API 形式

使用 JSON REST API 端點。所有受保護路由都需要有效的本系統 JWT。

建議路由群組：

- `POST /auth/google`
  - 請求：Google ID token。
  - 回應：本系統 JWT 與使用者資料。
- `GET /me`
  - 回傳目前登入使用者資料。
- `GET /anime`
  - 依搜尋字串查詢動漫資料。
- `POST /anime`
  - 建立手動動漫資料。
- `GET /my/anime-list`
  - 回傳登入使用者的個人清單。
- `POST /my/anime-list`
  - 將動漫加入個人清單。
- `PATCH /my/anime-list/{itemId}`
  - 更新是否看過、評價或備註。
- `DELETE /my/anime-list/{itemId}`
  - 從個人清單移除。
- `GET /public/lists/{slug}`
  - 回傳公開唯讀清單。
- `POST /me/share-slug/regenerate`
  - 重新產生公開分享 slug。

### 錯誤處理

- 回傳結構化 JSON 錯誤：
  - `code`：穩定的機器可讀錯誤代碼。
  - `message`：可安全顯示給使用者的訊息。
  - `details`：可選的驗證細節。
- 使用合適的 HTTP 狀態碼：
  - `400`：請求格式錯誤。
  - `401`：缺少或無效的登入資訊。
  - `403`：沒有權限。
  - `404`：資料不存在。
  - `409`：重複加入清單。
  - `422`：驗證失敗。
  - `500`：未預期的伺服器錯誤。
- 後端紀錄內部錯誤細節，但不得將密鑰或 stack trace 回傳給前端。

## 資料模型

### `users`

- `id` bigint primary key。
- `google_sub` varchar unique，必填。
- `email` varchar，必填。
- `display_name` varchar。
- `avatar_url` text。
- `public_slug` varchar unique，必填。
- `created_at` datetime。
- `updated_at` datetime。

### `anime`

- `id` bigint primary key。
- `name` varchar，必填。
- `description` text。
- `image_url` text。
- `source` 類 enum 的 varchar，值可為 `manual`、`seed`、`future_import`。
- `created_by_user_id` bigint nullable。
- `created_at` datetime。
- `updated_at` datetime。

### `anime_aliases`

- `id` bigint primary key。
- `anime_id` bigint foreign key。
- `alias` varchar，必填。

### `user_anime_list_items`

- `id` bigint primary key。
- `user_id` bigint foreign key。
- `anime_id` bigint foreign key。
- `watched` boolean，預設 false。
- `rating` tinyint nullable，允許範圍為 `1` 到 `10`。
- `note` text nullable。
- `created_at` datetime。
- `updated_at` datetime。
- `(user_id, anime_id)` 需有唯一限制。

### 索引

- `users.google_sub` unique。
- `users.public_slug` unique。
- `anime.name`。
- `anime_aliases.alias`。
- `user_anime_list_items.user_id`。
- `user_anime_list_items.anime_id`。
- `user_anime_list_items(user_id, anime_id)` unique。

## 安全性

- JWT 簽章密鑰只能存在後端環境變數。
- MVP 使用短效 JWT，不在瀏覽器儲存 refresh token。
- 資料庫帳密只能存在後端環境變數或 GCP Secret Manager。
- 不提交 `.env` 檔案。
- 只提交帶有範例值的 `.env.example`。
- 驗證 Google ID token 的 audience 必須符合設定的 Google OAuth client ID。
- 後端需驗證所有輸入：
  - 評價必須是 `1` 到 `10` 或 null。
  - 動漫名稱不可為空，且需限制長度。
  - 圖片 URL 若有提供，必須是有效的 HTTP 或 HTTPS URL。
- CORS 只允許 GitHub Pages 前端來源與本機開發來源。
- 正式環境必須使用 HTTPS。
- 不得將任何私密 API key 放進 Vue 建置結果。

## Docker 與本機開發

本機 Docker Compose 應包含：

- `frontend`：Vue 開發伺服器。
- `backend`：PHP API 伺服器。
- `mysql`：MySQL 資料庫。
- 可選 `phpmyadmin`：本機資料檢視工具。

後端容器應透過明確指令在開發時執行 migration。正式環境 migration 應作為受控部署步驟執行，不應在每次請求時隱式執行。

## 部署

### 前端

- 建置 Vue 靜態檔案。
- 部署到 GitHub Pages。
- 前端環境設定包含：
  - 公開 API base URL。
  - 公開 Google OAuth client ID。

### 後端

- 建置 PHP 後端容器。
- 部署到 GCP Cloud Run。
- 使用執行環境變數或 Secret Manager 綁定：
  - 資料庫 host、port、name、user、password。
  - JWT 簽章密鑰。
  - Google OAuth client ID。
  - 允許的前端來源。

### 資料庫

- 正式環境使用 Cloud SQL MySQL。
- 限制資料庫網路存取，只允許後端服務連線。
- 在儲存真實使用者資料前，應啟用備份。

## 未來擴充點

- `anime.source = future_import` 可支援後續每季動漫來源匯入。
- 未來可新增 `anime_popularity_snapshots` 表儲存排行榜資料。
- 未來可新增 `recommendation_runs` 表儲存 AI 推薦執行紀錄。
- 現有動漫資料與個人清單分離，讓 AI 推薦可引用動漫資料，不會影響個人清單歸屬。

## 測試策略

### 後端

- 驗證規則單元測試。
- 受保護清單路由整合測試。
- 重複加入清單處理測試。
- 不帶 JWT 存取公開清單測試。
- Google 登入驗證測試應 mock Google token 驗證。

### 前端

- 清單項目編輯狀態的元件測試。
- 需登入頁面的路由守衛測試。
- API 呼叫工具自動帶入授權標頭測試。
- 主要頁面的手機版響應式基本檢查。

### 部署安全檢查

- 確認 `.env` 已被忽略。
- 確認前端建置結果不包含後端私密資訊。
- 確認後端拒絕不允許來源的請求。
- 確認正式環境使用 HTTPS API URL。

## 成功標準

- 使用者可以用 Google 登入。
- 使用者可以搜尋或手動建立動漫資料。
- 使用者可以將動漫加入個人清單。
- 使用者可以更新是否看過與評價。
- 使用者可以開啟並分享公開唯讀清單 URL。
- 網站在手機尺寸下可使用，且不出現水平捲動。
- 本機開發可透過 Docker Compose 啟動。
- 沒有私密金鑰或密鑰被提交到 repository，或被打包進前端建置結果。
