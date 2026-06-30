# ACGSecrets 新番爬蟲與每週同步 — 設計文件

日期:2026-06-30
狀態:草案(待使用者審閱)

## 目標

從 [acgsecrets.hk](https://acgsecrets.hk/bangumi/list/) 爬取歷年(2016 Q1 ~ 2025 Q4,約 40 季、~2800 部)新番資料,建檔進現有 Laravel 後端,並每週自動同步最新季度,保持資料即時。

資料來源選定 acgsecrets 的理由:它提供**原生繁體中文**的片名與故事大綱(含台譯標註)、封面圖、播放日期/時段、台港串流平台、以及外部資料庫 ID(MAL / Bangumi),品質明顯優於需要簡轉繁的 Bangumi API 或無中文的 AniList / Jikan。

非營利、個人使用前提下,以遵守爬蟲禮節(降速、自訂 User-Agent、尊重 robots.txt、僅每週抓最新季)為原則。

## 範圍

### 本次要做
1. 重構:移除既有 Bangumi 簡轉繁爬取系統,並清空既有資料庫資料重建。
2. 階段一:Scraper + Parser,輸出 JSON 供人工驗證資料品質。
3. 階段二:Importer,將 JSON upsert 進既有資料表(新增一張串流平台表)。
4. 每週排程同步(當季 + 上一季)。

### 不在範圍
- 下載/重製封面圖檔(本設計只儲存圖片 URL;是否落地存圖為日後決定)。
- 前端展示變更。
- 公開上線時的大綱版權處理(僅在文件記錄注意事項)。

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

### 4. Command `anime:sync-acgsecrets`
- `--all`:抓索引全部季度(全量回補)。
- 無參數:只抓當季 + 上一季(`SeasonResolver::current` 推算)。
- `--json-only`:只輸出 JSON 到 `storage/app/scrape/`,不寫 DB(階段一驗證用)。
- `--season=YYYYMM`:指定單季(除錯/重跑用)。
- 每季產出統計,全部跑完輸出 `summary.json`(各季筆數、各欄位缺漏率、失敗季清單)。

### 5. 排程
- 在 `routes/console.php` 註冊 `Schedule::command('anime:sync-acgsecrets')->weeklyOn(1, '05:00')`(每週一 05:00)。

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

1. 重構移除舊 Bangumi 程式碼 + 改 config + 清空既有資料庫資料(migrate:fresh)。
2. 實作 Parser(TDD,fixture)。
3. 實作 Client。
4. 實作 Command `--json-only`,跑 202604 → 人工驗證 JSON 品質。【驗證閘門】
5. 新增 `anime_streams` 表 + model,重構 ImportService。
6. 接通 DB 匯入,跑全量回補。
7. 註冊每週排程。
