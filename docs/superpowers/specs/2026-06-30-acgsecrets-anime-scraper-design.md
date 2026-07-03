# ACGSecrets 新番爬蟲與每週同步 — 設計文件

日期:2026-06-30
狀態:草案(待使用者審閱)

## 目標

從 [acgsecrets.hk](https://acgsecrets.hk/bangumi/list/) 爬取歷年(2016 Q1 ~ 2025 Q4,約 40 季、~2800 部)新番資料,**以 JSON 實體檔案落地保存為資料來源真相(source of truth)**,並每週自動同步最新季度,保持資料即時。

資料來源選定 acgsecrets 的理由:它提供**原生繁體中文**的片名與故事大綱(含台譯標註)、封面圖、播放日期/時段、台港串流平台、以及外部資料庫 ID(MAL / Bangumi),品質明顯優於需要簡轉繁的 Bangumi API 或無中文的 AniList / Jikan。

非營利、個人使用前提下,以遵守爬蟲禮節(降速、自訂 User-Agent、尊重 robots.txt、僅每週抓最新季)為原則。

### 核心策略:JSON 為真相,爬蟲與匯入解耦

爬蟲只負責**產生 / 更新 JSON 檔**;DB 匯入則**完全只讀 JSON**,不直接連 acgsecrets。兩者解耦帶來:

- 歷史只需爬一次,JSON 檔進 git 版控長期保留。
- **上線後直接 clone 倉庫從 JSON 倒入,無需再連 acgsecrets**。
- DB 可隨時由 JSON 一鍵重建,不依賴外部網站存活。
- 每週更新只抓最新季 → 更新該季 JSON → 倒入,**不重爬歷史**。

## 範圍

### 本次要做
1. 重構:移除既有 Bangumi 簡轉繁爬取系統,並清空既有資料庫資料重建。
2. 階段一:Scraper + Parser,輸出 JSON 實體檔(進 git 版控),供人工驗證資料品質。
3. 階段二:Importer,**只讀 JSON** upsert 進既有資料表(新增一張串流平台表)。
4. 每週排程同步(當季 + 上一季):更新該季 JSON 後倒入。
5. 前端串接:既有 `seasonal.vue` / `catalog.vue` 改吃新資料,顯示串流平台/別名/標籤等新欄位;移除舊的手動 sync 按鈕。

### 不在範圍
- 下載/重製封面圖檔(本設計只儲存圖片 URL;是否落地存圖為日後決定)。
- 公開上線時的大綱版權處理(僅在文件記錄注意事項)。
- 前端版面重新設計(僅在既有頁面結構上接資料 + 新欄位呈現)。

## 重構:移除既有 Bangumi 系統

以下既有檔案處置:

| 檔案 | 處置 |
|------|------|
| `app/Services/AnimeCatalog/BangumiClient.php` | 刪除 |
| `app/Services/AnimeCatalog/BangumiAnimeNormalizer.php` | 刪除 |
| `app/Services/AnimeCatalog/ChineseTextConverter.php` | 刪除(acgsecrets 原生繁中) |
| `app/Console/Commands/SyncSeasonalAnime.php` | 刪除(由新 command 取代) |
| `app/Services/AnimeCatalog/AnimeImportService.php` | 重構(改吃 acgsecrets 解析結果) |
| `app/Services/AnimeCatalog/SeasonResolver.php` | 保留(通用季別工具) |
| `routes/api.php` 的 `POST /anime/sync-seasonal` | 移除 |
| `AnimeController::syncSeasonal()` | 移除 |
| `config/services.php` 的 `bangumi` 區塊 | 改為 `acgsecrets` 區塊 |

資料庫:既有 `anime` 等表結構保留(完全適用),但**既有資料全部清除後重建**(全面重構)。首次全量匯入前執行 `php artisan migrate:fresh`(或 truncate `anime` / `anime_titles` / `anime_aliases` / `anime_external_ids` / `anime_streams`),再以 acgsecrets 全量回補。`users` 與 `user_anime_list_items` 視同保留(若無實際使用者資料,`migrate:fresh` 一併重建亦可)。

> **⚠️ 此文件描述的是本功能「首次上線」時的一次性遷移步驟,該遷移早已完成。**
> **`migrate:fresh` 絕對不可再對現有環境執行**——會清空全部使用者資料(帳號、已看清單、collections)且不可逆。
> 日常補資料請用 `php artisan anime:import-acgsecrets`(不加 `--fresh`);
> 需要重建 catalog 資料(不動 users)才用 `--fresh` 選項,且執行前務必先確認这是你要的操作。

## 資料來源結構(已實測)

- 季別索引 `https://acgsecrets.hk/bangumi/list/` → 內含 40 個季度連結 `/bangumi/YYYYMM/`(`YYYYMM` 為 `201601`、`202604` 等,月份 01/04/07/10 對應冬/春/夏/秋)。
- 每個季度頁為**靜態 HTML**,每部動畫是一個 `<div class="clear-both acgs-anime-block ...">` 區塊,約 70 部/季。
- 區塊內含以下資訊(class 名稱已驗證):
  - 繁中主名、日文原名(`anime_names` / `entity_localized_name` / `entity_original_name`)
  - 其他名稱(「其他名稱:」後逗號分隔,含台譯)
  - 故事大綱(`anime_story` / `anime_summary`)
  - 封面圖 URL(`anime_cover_image` 下的 `<img src>`,位於 `static.acgsecrets.hk`)
  - 播放日期文字(`onair_times`,如「4月4日起/每週六/23時30分」)
  - 標籤(`main_tags` / `sub_tags`:類型、改編來源等)
  - 串流平台(`stream-sites`:地區 + 平台名 + URL,如 台灣/巴哈姆特動畫瘋、香港/Viu)
  - 外部連結(`anime_links`:`hasicon mal`、`hasicon bgmtv` 等,可取 MAL / Bangumi ID)

## 架構

三層,各層職責單一、介面明確、可獨立測試。

```
                ┌─────────────────────────────────────────────┐
階段一 (爬取)    │  AcgSecretsClient        AcgSecretsParser     │
                │  (HTTP, 降速, 重試)  ──►  (純函式: HTML→陣列)  │
                └───────────────┬─────────────────────────────┘
                                │  array<AnimeRecord>
                                ▼
                       storage/app/scrape/YYYYMM.json   ← 人工驗證點
                                │
階段二 (匯入)                    ▼
                ┌─────────────────────────────────────────────┐
                │  AnimeImportService (重構)                    │
                │  upsert → anime / anime_titles / anime_aliases│
                │          / anime_external_ids / anime_streams │
                └─────────────────────────────────────────────┘
                                ▲
排程            ┌───────────────┴─────────────────────────────┐
                │  Command: anime:sync-acgsecrets               │
                │   --all        全量 2016~當季 (初始化/JSON)    │
                │   (無參數)      當季 + 上一季 (每週更新)         │
                │  Scheduler: 每週一 05:00                       │
                └─────────────────────────────────────────────┘
```

## 元件設計

### 1. `AcgSecretsParser`(純函式,核心,最需測試)
- `parseSeasonIndex(string $html): array<string>` — 從索引頁取出所有 `YYYYMM` 季度代碼。
- `parseSeasonPage(string $html, string $yyyymm): array<AnimeRecord>` — 把一頁拆成多個 `parseAnimeBlock`。
- `parseAnimeBlock(string $blockHtml, string $yyyymm): AnimeRecord` — 單一區塊 → 結構化陣列。
- 實作:PHP 內建 `DOMDocument` + `DOMXPath`,零外部依賴。
- 容錯:任一欄位抓不到 → 該欄 null / 空陣列,不拋例外、不讓整部失敗。
- 不做任何 IO,純輸入字串、輸出陣列 → 可用固定 HTML fixture 做單元測試。

### 2. `AcgSecretsClient`(HTTP 取得,有禮貌)
- `fetchIndex(): string`、`fetchSeason(string $yyyymm): string`。
- 用 Laravel `Http`:自訂 User-Agent、timeout、每次請求間隨機延遲 1~3 秒。
- 單頁失敗自動重試 2 次(指數退避),仍失敗則拋例外由上層記錄並跳過該季。
- base URL / User-Agent / 延遲 / 重試次數放 `config/services.php` 的 `acgsecrets` 區塊。

### 3. `AnimeImportService`(重構,JSON/陣列 → DB)
- `importRecord(AnimeRecord $record): Anime` — 單筆 upsert,沿用既有 transaction + payload_hash 模式。
- `importSeason(string $yyyymm, array $records): array` — 回傳統計(imported / skipped / errors)。
- upsert 唯一鍵策略(依序):
  1. 若有 Bangumi 或 MAL external_id → 以 `anime_external_ids(provider, external_id)` 對應既有 anime。
  2. 否則以 `(season_year, season_code, 繁中主名)` 比對。
  3. 都無 → 新建。
  (首次全量匯入因資料庫已清空,多為新建;唯一鍵策略主要服務每週重複同步,避免同一部動畫被重複建立。)
- 寫入對應:
  - `anime`:name(繁中主名)、description(大綱)、image_url(封面 URL)、season_year、season_code、air_date、source=`acgsecrets`。
  - `anime_titles`:繁中(locale `zh-Hant`, is_primary)、日文(locale `ja`)。
  - `anime_aliases`:其他名稱逐筆。
  - `anime_external_ids`:mal / bangumi(含 url、last_synced_at、payload_hash)。
  - `anime_streams`(新表):region、platform、url。

### 4. JSON 檔案儲存(資料來源真相)
- 位置:`backend/database/seed/acgsecrets/`(進 git 版控,非 `storage/`)。
- 每季一檔 `YYYYMM.json`(含該季所有 AnimeRecord),外加 `summary.json`(各季筆數、各欄位缺漏率、失敗季清單)。
- 由爬蟲 command 產生 / 更新;匯入 command 只讀此目錄。

### 5. Command(兩個,職責分離)

**`anime:scrape-acgsecrets`(連外網,產生 JSON)**
- `--all`:抓索引全部季度,寫入全部 `YYYYMM.json`(全量回補,執行一次)。
- 無參數:只抓當季 + 上一季(`SeasonResolver::current` 推算),更新對應 JSON(每週用)。
- `--season=YYYYMM`:指定單季(除錯 / 重跑)。
- 輸出 / 更新 `summary.json`。

**`anime:import-acgsecrets`(不連外網,JSON → DB)**
- 讀 `database/seed/acgsecrets/*.json`,upsert 進資料表。
- `--fresh`:匯入前先清空 anime 相關表(全面重建用)。
- 上線部署時只跑這個,不需連 acgsecrets。

### 6. 排程
- 在 `routes/console.php` 註冊每週一 05:00:先 `anime:scrape-acgsecrets`(更新最新季 JSON),再 `anime:import-acgsecrets`(倒入 DB)。
  ```php
  Schedule::command('anime:scrape-acgsecrets')->weeklyOn(1, '05:00')
      ->then(fn () => Artisan::call('anime:import-acgsecrets'));
  ```

## 資料結構(JSON / AnimeRecord)

每部動畫的中介格式(階段一輸出 / 階段二輸入):

```json
{
  "season": "202604",
  "season_year": 2026,
  "season_code": "spring",
  "title_zh": "黃泉雙使",
  "title_ja": "黄泉のツガイ",
  "aliases": ["黃泉的使者", "Yomi no Tsugai", "黃泉使者"],
  "summary": "居於深山小村落的少年月落(台譯:尤爾)…",
  "cover_image": "https://static.acgsecrets.hk/img/.../xxx.jpg",
  "air_date_text": "4月4日起/每週六/23時30分",
  "air_date": "2026-04-04",
  "tags": ["漫畫改編", "動作", "冒險", "奇幻"],
  "streams": [
    {"region": "台灣", "platform": "巴哈姆特動畫瘋", "url": "https://..."},
    {"region": "香港", "platform": "Viu", "url": "https://..."}
  ],
  "external_ids": {"mal": "12345", "bangumi": "377130"}
}
```

`season_code` 採既有 `SeasonResolver` 的字詞制(winter/spring/summer/fall);`YYYYMM` 月份 01→winter、04→spring、07→summer、10→fall。

## 新增資料表 `anime_streams`

```php
Schema::create('anime_streams', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
    $table->string('region', 32);       // 台灣 / 香港 …
    $table->string('platform', 64);     // 巴哈姆特動畫瘋 / Viu / Muse …
    $table->text('url')->nullable();
    $table->timestamps();
    $table->unique(['anime_id', 'region', 'platform'], 'uniq_anime_stream');
});
```

對應新增 `App\Models\AnimeStream` 與 `Anime::streams()` 關聯。

## 前端串接

既有前端已具備所需骨架,串接以「沿用現有 API 合約 + 補新欄位」為原則,不重做版面。

### API 回應擴充
`GET /anime`(`AnimeController::index`)目前回傳 anime 基本欄位。擴充為 eager-load 並回傳:
- `streams`:`[{region, platform, url}]`(來自 `anime_streams`)
- `aliases`:`[string]`(來自 `anime_aliases`)
- `titles`:`[{locale, title, is_primary}]`(來自 `anime_titles`,供顯示日文原名)
保持既有 `items` 陣列結構與既有欄位不變,只新增欄位 → 不破壞既有前端。

### 後端移除
- `AnimeController::syncSeasonal()` 與 `POST /anime/sync-seasonal` 路由移除(資料改由 JSON 預先匯入,使用者不再手動觸發爬取)。

### 前端調整
- `frontend/app/composables/useApi.ts`:移除 `syncSeasonalAnime`。
- `frontend/app/utils/normalize.ts`:`Anime` interface 與 `normalizeAnime` 新增 `streams` / `aliases` / `titleJa` 欄位。
- `frontend/app/pages/seasonal.vue`:移除「同步」按鈕與 `syncSeasonal()` / `syncResult` 相關狀態;在卡片呈現串流平台連結(台港可看平台)與日文原名。
- `frontend/app/pages/catalog.vue`:卡片同步顯示新欄位(串流/別名)。
- 既有 `loadSeasonal()` 走 `searchAnime('', {year, season})` 的流程不變。

### 前端測試
- 既有 `frontend/test/` 用 vitest;為 `normalizeAnime` 新欄位補測,確保 snake_case / camelCase 兩種後端鍵都能正確映射(沿用既有測試風格)。

## 錯誤處理

- 解析層:單欄缺漏不致命,記入 summary 缺漏統計。
- HTTP 層:單頁重試 2 次後跳過該季,記入 summary 失敗清單,不中斷整批。
- 匯入層:單筆例外 → skipped++ 並記錄,不影響同季其他筆(沿用既有 try/catch 模式)。
- 排程:每週同步只動當季 + 上一季,失敗有日誌但不阻斷下次排程。

## 測試

- **Parser 單元測試(重點)**:抓取真實季度頁存成 fixture,對 `parseAnimeBlock` 斷言各欄位(繁中名、日文名、別名、大綱、圖 URL、播放日期、串流、external_ids)正確。涵蓋缺漏欄位的容錯案例。
- **SeasonResolver / YYYYMM 對應**:測月份→季別代碼。
- **Importer 測試**:用 in-memory SQLite,驗證 upsert 唯一鍵(external_id 命中、季+名命中、新建)與多語標題/別名/串流寫入正確。
- **驗證流程**:先對 1 季(202604)跑 `--json-only`,人工比對 3~5 部與網站內容一致,再全量跑。

## 爬蟲禮節與版權注意(記錄)

- 降速(隨機 1~3 秒)、自訂可辨識 User-Agent、尊重 robots.txt、全量爬僅執行一次,日常只抓最新季。
- 封面圖僅存 URL,不熱連結展示時應評估改用第三方來源。
- 故事大綱為 acgsecrets 編輯撰寫之著作;非營利自用風險低,若日後公開展示應考慮改摘要或改用其他來源,並標註資料來源 acgsecrets.hk。

## 待辦階段順序

1. 重構移除舊 Bangumi 程式碼(Client / Normalizer / ChineseTextConverter / 舊 command / 舊 API)+ 改 config。
2. 實作 `AcgSecretsParser`(TDD,fixture)。
3. 實作 `AcgSecretsClient`。
4. 實作 `anime:scrape-acgsecrets`,跑 202604 單季 → 人工驗證 `202604.json` 品質。【驗證閘門】
5. 全量爬取,產生全部 `YYYYMM.json` + `summary.json`,commit 進 git。
6. 新增 `anime_streams` migration + `AnimeStream` model;重構 `AnimeImportService` 改讀 JSON。
7. 實作 `anime:import-acgsecrets`(`--fresh`);清空 DB 後全量匯入。
8. 註冊每週排程(scrape → import)。
9. 後端 API:`AnimeController::index` 擴充回傳 streams/aliases/titles;移除 `syncSeasonal`。
10. 前端:更新 `useApi` / `normalize` / `seasonal.vue` / `catalog.vue`,移除同步按鈕、呈現新欄位;補 vitest。
11. 端對端驗證:啟動 backend + frontend,確認新番表正確顯示繁中名、大綱、圖、串流平台。
