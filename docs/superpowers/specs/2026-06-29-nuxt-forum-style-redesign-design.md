# 前端改版：遷移至 Nuxt 3 + 論壇風格 RWD 設計

## 目標

把現有 Vue + Vite SPA 前端整套改寫為 Nuxt 3 專案，並改用 Nuxt UI（Tailwind CSS）元件庫，把版面從目前的「SaaS 卡片式」風格調整為密度更高的「論壇／速覽列表」風格，主要參考 acgsecrets.hk 的新番速覽頁。後端 Laravel API 完全不變，只動 `frontend/` 目錄。

社群圖卡推薦功能（使用者產生可分享的推薦圖卡）不在這次範圍內，留給下一輪設計。

## 範圍

### 包含

- `frontend/` 整套改寫為 Nuxt 3 專案結構，取代現有 Vue + Vite SPA。
- 導入 Nuxt UI（含 Tailwind CSS）作為元件與樣式基礎，取代手寫的 `styles.css`。
- 重新設計三個既有頁面的版面：
  - 新番表（首頁 `/` 與 `/seasonal`）
  - 資料庫搜尋（`/catalog`）
  - 我的清單（`/list`、`/watched`、`/unwatched`）
- 維持現有的路由集合、API 呼叫邏輯、登入流程（Google OAuth + dev login）、session 管理不變，只是用 Nuxt 的方式重新組織（例如 Nuxt 的檔案式路由、composables）。
- RWD：桌機與手機版面皆需處理，沿用 Tailwind breakpoint。

### 不包含

- 後端 API、資料庫 schema、認證機制的任何修改。
- 社群圖卡推薦功能（新資料模型、生成邏輯、分享機制）。
- 登入頁（`/login`）、設定頁（`/settings`）、公開分享頁（`/public/:slug`）的版面大改 — 這幾頁沿用現有資訊結構，只套用 Nuxt UI 元件重新實作外觀，不重新設計版面骨架。
- 部署設定（GitHub Pages / Cloud Run）的調整，除非 Nuxt build 輸出需要對應變更（見「部署」一節）。

## 技術選型

- **框架**：Nuxt 3（最新穩定版），SPA 模式（`ssr: false`），維持現有「純前端靜態檔案部署到 GitHub Pages」的部署模型，不引入 SSR/伺服器執行環境。
- **UI 套件**：`@nuxt/ui`（內含 Tailwind CSS、Headless UI 元件、深色模式支援）。用其 `UButton`、`UBadge`、`UTabs`、`USlideover`（側拉面板）、`UCard` 等元件取代手寫的 `.primary-button`、`.source-pill`、`.season-row` 等 class。
- **Icon**：Nuxt UI 預設整合 `lucide` icon set（透過 `@iconify-json/lucide`），可直接沿用現有 lucide icon 的命名習慣，不需要再裝 `lucide-vue-next`。
- **狀態管理**：沿用現有 `useSession`、`useHashRoute` 的概念，改寫為 Nuxt composables（`app/composables/`），路由改用 Nuxt 內建的檔案式路由（`app/pages/`）取代手寫的 hash router。
- **API client**：`services/api.js` 的邏輯（含 mojibake 修復、normalize 函式）原樣搬遷到 Nuxt 的 `app/utils/` 或 `app/composables/useApi.ts`，不改動行為。

## 頁面設計

### 新番表（首頁）

**整體骨架**：

1. 頂部：標題區（年份/季度文字說明，沿用現有 `bangumi-head` 的文案邏輯，改用 `UCard` 或簡化版 banner 呈現）。
2. 星期 Tab 列：「一、二、三、四、五、六、日」七個分頁，獨立佔一整行，使用 `UTabs` 或自訂橫向可滑動 tab。手機版可橫向滑動（`overflow-x: auto`），不換行。點擊某天，下方網格只顯示該星期首播的作品。
   - 需要新增「全部」分頁（或預設選中今天對應的星期），因為現有資料模型沒有「星期」欄位，需要從 `airDate` 衍生星期（複用現有 `formatGuideSchedule` 的邏輯）。
3. 篩選列：星期 Tab 列下方**另起一行**，靠右對齊放一個「篩選」按鈕（`UButton`，帶上已套用篩選數量的 badge）。點擊後用 `USlideover` 從右側滑出篩選面板，內容包含：
   - 年份／季度選擇（原 `season-form`）
   - 分類 chips（原 `genreCategories`）
   - 觀看狀態（原 `seasonalFilterOptions`：全部/已加入/已看/待補/有封面）
   - 面板底部保留「同步新番資料」按鈕與同步結果摘要（`sync-summary`），邏輯不變，只套用 Nuxt UI 樣式。
4. 作品網格：`auto-fill` 響應式網格（取代 `guide-grid`），每格是一張作品卡片：
   - 封面圖滿版鋪底（無封面時顯示首字 fallback，沿用現有邏輯）。
   - 底部疊加片名（漸層遮罩 + 文字，沿用現有 `guide-shade`/`guide-title` 的視覺邏輯）。
   - 右上角時間角標（沿用 `guide-date` 的黃/藍/綠/粉色循環配色邏輯）。
   - 若該作品已加入使用者清單，左上角疊加一個小圖示角標（已加入／已看），用 `UBadge`。
   - 點擊卡片 = 加入清單（未登入則導向登入頁），不需要進入詳情頁（目前沒有作品詳情頁，維持現狀）。
   - 網格密度：桌機每行 6-8 格，手機每行 3 格（沿用現有 breakpoint 邏輯調整欄數）。

**互動細節**：

- 切換星期 Tab、套用篩選都是前端 client-side 過濾現有已載入的 `state.seasonal` 陣列，不重新打 API（沿用現有 `filteredSeasonal` 的 computed 模式）。
- 「同步新番資料」按鈕觸發後端 API 呼叫，行為與現在完全一致，只是視覺上收進篩選面板。

### 資料庫搜尋（`/catalog`）

- 套用跟新番表相同的卡片網格風格（封面滿版疊加片名），維持視覺一致性。
- 搜尋框移到頂部，使用 `UInput` + `UButton`。
- 手動建立作品的表單（`ManualAnimeForm`）維持側邊或下方面板形式，改用 `UForm` 元件重新實作，欄位與驗證邏輯不變。

### 我的清單（`/list`、`/watched`、`/unwatched`）

- 保持現有的條列式排版（封面 + 文字內容 + 控制項三欄式卡片），不改版面骨架。
- 用 `UCard` 重新實作 `list-card`，評分用 `USelect`，已看切換用 `USwitch` 或 `UCheckbox`，移除按鈕的二次確認用 Nuxt UI 的 `UButton` + 內嵌確認態（沿用現有「點擊變成確認列」的互動模式）。
- 全部/已看/未看的分頁籤改用 `UTabs`。

### 其他頁面（登入、設定、公開分享）

- 不重新設計版面骨架，僅將現有手寫元件（按鈕、表單、卡片）替換為對應的 Nuxt UI 元件，維持目前的單欄/雙欄排列。

## 元件對應表

| 現有手寫 class / 元件 | 改用 Nuxt UI |
|---|---|
| `.primary-button` / `.secondary-button` / `.quiet-button` / `.danger-button` | `UButton`（color/variant prop） |
| `.source-pill` / `.watch-pill` / `.count-pill` | `UBadge` |
| `.panel` / `.anime-card` / `.list-card` | `UCard` |
| `.segmented-control` | `UTabs` |
| `AppNavigation.vue` 桌機 nav / 手機 bottom nav | 桌機沿用頂部 nav（`UHorizontalNavigation` 或自訂），手機底部導覽列維持現有 5 個項目（總覽/資料庫/本季新番/我的清單/設定），改用 Nuxt UI 按鈕樣式重做，不調整項目內容或位置邏輯 |
| 篩選彈出面板 | `USlideover` |
| `ManualAnimeForm` / 表單 input | `UForm` + `UInput` / `USelect` / `UTextarea` |
| `EmptyState.vue` | 維持自訂元件，內部換成 Nuxt UI 排版 |
| `StatusMessage.vue`（loading/error/notice） | 改用 `UAlert` 或 Nuxt UI 的 toast（`useToast()`），錯誤/成功訊息可考慮改為 toast 通知，loading 狀態維持原本的行內提示 |

## 資料流與邏輯（不變部分）

以下邏輯原樣保留，只是搬遷到 Nuxt 的檔案組織方式，行為不變：

- `services/api.js` 全部函式與 mojibake 修復邏輯。
- `useSession`：token/user 存 localStorage 的邏輯。
- 路由保護邏輯（`protectedRoutes` 陣列，未登入導向 `/login`）。
- `genreCategories` 分類關鍵字比對邏輯。
- `seasonalFilterOptions` 的計算邏輯（全部/已加入/已看/待補/有封面）。
- Google Sign-In 按鈕渲染（`renderGoogleButton`）與 dev login 邏輯。

## 路由對應

Nuxt 檔案式路由取代現有 hash router：

| 現有 hash 路由 | Nuxt 頁面檔案 |
|---|---|
| `#/` , `#/seasonal` | `app/pages/index.vue` |
| `#/catalog` | `app/pages/catalog.vue` |
| `#/list`, `#/watched`, `#/unwatched` | `app/pages/list/index.vue`，用 `?filter=watched` / `?filter=unwatched` query param 區分（無 query 時顯示全部），不用三個獨立路由 |
| `#/login` | `app/pages/login.vue` |
| `#/settings` | `app/pages/settings.vue` |
| `#/public/:slug` | `app/pages/public/[slug].vue` |

路由保護用 Nuxt 的 `definePageMeta` + middleware 實作，取代現有手寫的 `isProtectedRoute` 判斷。

## RWD 斷點策略

沿用 Tailwind 預設斷點（`sm` 640px / `md` 768px / `lg` 1024px / `xl` 1280px），取代現有手寫的 1100px/820px/520px/420px 斷點。各頁面網格欄數：

- 新番表/資料庫卡片網格：手機 3 欄（`grid-cols-3`），平板 4-5 欄（`md:grid-cols-5`），桌機 6-8 欄（`lg:grid-cols-7`）。
- 星期 Tab 列：手機橫向可滑動，桌機平均分配寬度。
- 我的清單條列卡片：手機單欄堆疊（圖片+文字+控制項改直向排列），桌機維持三欄式（圖/文字/控制）。
- 手機底部導覽列：維持現有 5 個固定項目，`fixed bottom-0`，桌機隱藏（沿用現有 `.mobile-nav` 在 ≥820px 隱藏的邏輯，改為 Tailwind 的 `md:hidden`）。

## 部署影響

- GitHub Pages 部署流程需要調整建置指令：Nuxt SPA 模式建置輸出目錄與 Vite 不同（`dist/` → `.output/public/`），需要更新 CI/部署腳本中的路徑（若有 GitHub Actions workflow，需要同步調整；目前 repo 內沒有發現對應 workflow 檔案，視為後續部署時再處理）。
- 環境變數命名沿用 `VITE_*` 前綴會失效，需改為 Nuxt 的 `NUXT_PUBLIC_*` 命名慣例（例如 `VITE_API_BASE_URL` → `NUXT_PUBLIC_API_BASE_URL`），`docker-compose.yml` 與 `.env.example` 中對應的前端環境變數需要同步更新。
- `docker-compose.yml` 的 frontend service 指令（`npm install && npm run dev`）需確認 Nuxt 的 dev server 指令與 port 是否與現有 5173 一致，或調整對應 port 映射。

## 測試策略

- 沿用現有 `frontend/src/ui-contract.test.mjs` 的精神（驗證 API 回應正規化邏輯），搬遷到 Nuxt 專案後確認 mojibake 修復、`normalizeAnime`/`normalizeListItem` 邏輯不被破壞。
- 主要頁面（新番表、資料庫搜尋、我的清單）在桌機與手機寬度下手動驗證：星期 Tab 切換、篩選面板開合、卡片網格欄數隨斷點變化、底部導覽在手機顯示/桌機隱藏。
- 確認 Google 登入、dev login、路由保護（未登入訪問 `/list` 導向 `/login`）行為與改版前一致。

## 成功標準

- `docker compose up --build frontend` 可正常啟動 Nuxt dev server，行為與現有 Vite dev server 一致（檔案變更即時熱更新）。
- 新番表頁面呈現星期 Tab + 篩選面板 + 高密度封面網格的論壇風格版面，視覺密度明顯高於目前的卡片牆設計。
- 資料庫搜尋頁與新番表視覺一致。
- 我的清單頁功能（評分、已看切換、備註、移除確認、分頁籤）與現有行為一致，僅外觀改用 Nuxt UI。
- 手機寬度（375px 起）至桌機寬度（1440px+）皆無版面破版或非預期橫向捲動。
- 登入、登出、分享連結複製、新番同步等既有功能全部正常運作，無迴歸。
