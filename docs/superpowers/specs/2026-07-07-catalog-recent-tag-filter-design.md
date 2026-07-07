# 資料庫頁：近期作品 + 後端分類篩選

日期：2026-07-07

## 背景與目標

`/catalog`（資料庫）頁目前是純「年份瀏覽」：預設載入當前年份（`searchAnime('', { year })`），
前端 40/頁分頁，並提供 `‹ 年 ›` 切換器。缺點是：

- 進站只看得到「今年」，看不到跨年份的近期新番全貌。
- 沒有分類（genre）篩選，無法快速找特定類型作品。

本次要把資料庫頁升級為：

1. **近期模式（新預設）**：不限年份，依播出日期（`air_date`）由新到舊，顯示 50 筆。
2. **後端分類篩選**：比照 `/list`（我的清單）的作法——後端 `tags` 參數 OR 篩選 + 專屬 tags 清單端點。
3. **搜尋**：維持現狀（全庫關鍵字搜尋），可與分類併用。
4. **年份切換保留**：仍可切回單一年份瀏覽，與近期模式並存。

## 決策紀錄（來自 brainstorming）

- **排序**：近期 = `air_date` 由新到舊。
- **分類來源**：比照我的清單——後端提供完整 genre 清單，前端後端篩選。
- **篩選結果**：預設一般 query 顯示分頁 50 筆；篩選則套用 tag 後端篩選 50 筆。
- **年份切換器**：保留，與分類並存。
- **分類數量**：chip 列只顯示前 N 個高頻分類（前 20）。

## 後端設計

### `AnimeController@index` 改動

新增 `tags` query 參數並引入「近期模式」，**現有 year / season / 搜尋行為完全不變**。

模式判定：

| 條件 | 模式 | 排序 | 筆數上限 |
|------|------|------|----------|
| 有 `year`（可含 `season`） | 年份瀏覽（現狀） | `air_date is null`, `air_date asc`, `name` | 不限 |
| 有 `q` 但無 `year` | 全庫搜尋（現狀） | 同上 | 200 |
| 無 `year`、無 `season`、無 `q` | **近期模式（新）** | `air_date is null`, `air_date desc`, `name` | **50** |

`tags` 參數（新，適用所有模式，OR 邏輯）：

```php
$tags = array_values(array_filter(
    array_map('trim', explode(',', (string) $request->query('tags', ''))),
    fn (string $t): bool => $t !== ''
));

// ... 在 query builder：
->when($tags !== [], function ($builder) use ($tags): void {
    $builder->where(function ($where) use ($tags): void {
        foreach ($tags as $tag) {
            $where->orWhereJsonContains('tags', $tag);
        }
    });
})
```

近期模式的排序與 limit：

```php
$isRecentMode = $year === null && $season === '' && $query === '';

// 排序：年份/搜尋模式維持 air_date asc；近期模式改 air_date desc
->orderByRaw('air_date is null')
->when($isRecentMode,
    fn ($b) => $b->orderByDesc('air_date'),
    fn ($b) => $b->orderBy('air_date'),
)
->orderBy('name')
// limit：年份模式不限；搜尋模式 200；近期模式 50
->when($isRecentMode, fn ($b) => $b->limit(50))
->when(! $isRecentMode && ! $isYearScoped, fn ($b) => $b->limit(200))
```

> 注意 `$isYearScoped`（現有變數）= `$year !== null`，仍用來決定是否套 200 上限。
> 近期模式不是 year-scoped，但用自己的 50 上限，故上面兩個 `when` 互斥。

### 新端點 `GET /anime/tags`

比照 `AnimeListController@tags`，回傳全庫 genre tag 清單 + count，供前端顯示分類 chip。

- 路由：`routes/api.php` 於 `/anime/{id}` 之前加 `Route::get('/anime/tags', [AnimeController::class, 'tags']);`
  （放在 `{id}` 之前避免 `tags` 被當成 id 參數）。
- 邏輯：掃全庫 anime 的 `tags` 欄位，用 `GenreTags::isGenreTag()` 過濾，統計 count，
  按 `[count desc, tag asc]` 排序回傳 `{ tags: [{ tag, count }] }`。
- 效能：全庫掃描僅取 `id, tags` 欄位；資料量約 2000 筆內可接受。若日後過大再加快取。

## 前端設計

### `useApi.searchAnime` 擴充

現：`searchAnime(query, { year?, season? })`。
新增 `tags?: string[]`，序列化為逗號分隔 query 參數。

新增 `catalogTags()` → `GET /anime/tags`。

### `catalog.vue` 狀態機

三種瀏覽狀態，互斥切換（切換其一時重置其他）：

1. **近期模式（預設）**：`activeYear = null`、`query = ''`。可疊加 `selectedTags`。
   → `searchAnime('', { tags })`。
2. **年份瀏覽**：點年份切換器 → `activeYear = 某年`，清空 `selectedTags` 與 `query`。
   → `searchAnime('', { year })`（現狀）。
3. **搜尋**：輸入關鍵字 → 走 `searchAnime(q, { tags })`，可與 tags 併用；清空 `activeYear`。

`selectedTags` 變動時（近期或搜尋模式）重新向後端查詢，`page` 重置為 1，沿用現有 skeleton loading。
用 request id 防止 race（比照 list/index.vue 的 `tagRequestId`）。

### UI

- **年份切換器**：保留現狀。近期模式時顯示「近期」而非年份（或維持切換器但標示當前為近期）。
  具體：新增一顆「近期」按鈕/狀態；點左右箭頭進入年份瀏覽。
- **分類 chip 列**：進站呼叫 `catalogTags()`，取前 20（已按 count 排序）顯示。
  多選 OR，比照 seasonal 頁 genre 列樣式（`tagColor` 上色、選中反白）。
  提供「全部」清除鍵。
- **loading / empty / 分頁**：沿用現有元件（skeleton、`AnimeVirtualGrid`、40/頁分頁）。

### SEO / canonical

- 近期模式：title「近期動漫作品｜動漫庫」，canonical 指向 `/catalog`（無 query）。
- 年份模式與搜尋模式維持現狀。
- tags 篩選不改 canonical（避免重複頁面被索引）。

## 一致性風險與注意事項

- **MySQL null 排序**：`air_date desc` 時 null 會排在最後或最前依版本而定，故明確用
  `orderByRaw('air_date is null')` 先把 null 推到最後，再 `orderByDesc('air_date')`。
- **`GenreTags` 雙份同步**：後端 `GenreTags::isGenreTag` 與前端 `useSeasonalCatalog.isGenreTag`
  已註記需同步，本次不動這兩份。
- **路由順序**：`/anime/tags` 必須在 `/anime/{id}` 之前註冊。
- **模式互斥**：前端三模式切換務必清掉其他模式的狀態，避免 year + tags + q 混用產生非預期查詢。

## 測試

### 後端（`php artisan test`）

- 近期模式：無參數 → 回 ≤50 筆，且依 air_date desc（最新在前，null 在後）。
- `tags` OR 篩選：帶 `tags=戀愛,戰鬥` → 只含至少一個該 tag 的作品。
- `tags` + `year`：年份模式下 tags 仍生效且不受 50 限制（走年份上限）。
- 搜尋 + tags：`q` + `tags` 併用正確。
- `GET /anime/tags`：回傳格式 `{ tags: [{ tag, count }] }`，已過濾 source tag，按 count desc 排序。
- 既有 year / season / 搜尋行為 regression（現有測試應維持綠燈）。

### 前端（`npm run test`，Vitest）

- `searchAnime` 序列化 `tags` 為逗號分隔。
- catalog 三模式切換的狀態重置邏輯（若有可測的 composable/util）。

## 不做（YAGNI）

- 分類 chip 的「展開全部」——只顯示前 20 即可。
- tags 篩選的 SEO 索引頁。
- 全庫 tags 端點的快取層（資料量小，暫不需要）。
- 排序方式切換 UI（固定 air_date desc）。
