# 我的清單分類篩選 — 設計

## 背景與目標

「我的清單」（`/list`）目前只能依「全部 / 已看 / 未看」與使用者自訂清單（collections）篩選作品。使用者希望能依「分類」（劇情類型，如戀愛、戰鬥、搞笑）篩選自己清單中已看過或想看的作品。

資料庫 `anime` 表已有 `tags` 欄位（`array` cast，JSON 儲存），內容來自 acgsecrets 爬蟲解析（`AcgSecretsParser::tags()`），`/catalog` 頁面已用同一欄位做 genre 篩選（`useSeasonalCatalog.ts`）。因此本次不需要新的分類資料表、爬蟲邏輯或匯入流程，純粹是把既有 `tags` 資料串到「我的清單」頁面並加上篩選 UI。

## 範圍

**要做：**
- 後端：`UserAnimeListItem` API 回傳的 `anime` 物件補上 `tags` 欄位
- 前端：`/list` 頁面新增分類多選篩選（主區塊上方，與已看/未看/自訂清單篩選並存、AND 疊加）
- 前端：`ListItemRow.vue` 每列作品顯示分類 chip

**不做：**
- 不新增分類資料表或分類管理 API
- 不修改爬蟲（`AcgSecretsParser`）或匯入（`AnimeImportService`）邏輯
- 不改變 `tags` 的資料來源或格式

## 後端改動

### `AnimeListController::formatItem()`

檔案：`backend/app/Http/Controllers/Api/AnimeListController.php`

目前回傳的 `anime` 物件（第 161-166 行）缺少 `tags`：

```php
'anime' => [
    'id' => $item->anime->id,
    'name' => $item->anime->name,
    'description' => $item->anime->description,
    'imageUrl' => $item->anime->image_url,
],
```

補上一行：

```php
'anime' => [
    'id' => $item->anime->id,
    'name' => $item->anime->name,
    'description' => $item->anime->description,
    'imageUrl' => $item->anime->image_url,
    'tags' => $item->anime->tags ?? [],
],
```

此方法同時被 `index()`（`me()` 的清單）與 `publicList()`（公開分享清單）共用，因此分享出去的清單也會自然帶上分類——這是合理且預期中的副作用，不需額外處理。

不需要新增 API endpoint：分類選項的來源是「使用者清單內作品的 tags 去重統計」，前端直接從已載入的 `list` 資料算出即可。

## 前端改動

### 型別（無需改動）

`frontend/app/utils/normalize.ts` 中 `ListItem.anime` 型別已經是 `Anime`，本來就包含 `tags: string[]`（第 81 行）。後端補上資料後，`normalizeAnime()`（第 106 行 `tags: Array.isArray(item.tags) ? item.tags : []`）會自動生效，型別定義不用改。

### `/list` 頁面（`frontend/app/pages/list/index.vue`）

1. **狀態**：新增 `selectedTags = ref<string[]>([])`，透過 route query 的 `tags` 參數持久化（逗號分隔，例如 `?tags=戀愛,戰鬥`），與現有 `filter` query 參數並存、互不影響。

2. **分類選項來源**：新增 computed，統計 `list.value` 中所有作品的 `anime.tags`，得到去重後的分類清單與各分類的出現次數（做法比照 `useSeasonalCatalog.ts` 對 genre 的萃取邏輯）。只列出使用者清單中實際出現過的分類，不列出資料庫其他作品的分類。

3. **篩選邏輯**：`filteredList` computed 在現有的 all/watched/unwatched/col:id 判斷之後，再疊加一層 tags 過濾——若 `selectedTags` 非空，只保留 `anime.tags` 與 `selectedTags` 有交集（OR）的作品。兩層篩選是 AND 關係（例：篩「已看」+「戀愛、戰鬥」→ 已看過的、且屬於戀愛或戰鬥類型的作品）。

4. **UI 位置**：在右側主區塊 header（「我的清單」標題）下方、清單列表上方，新增一排分類多選 chip 列，樣式與配色沿用 `tagColor()`（`normalize.ts:214`）以及 `/catalog` 頁面既有的 genre chip 視覺語言。選中的 chip 需有明顯的選中態（例如描邊或底色反轉）。清單為空或使用者清單中沒有任何 tags 時，這排 UI 不顯示。

5. **清除篩選**：需有清除全部已選分類的方式（例如再點一次已選 chip 取消，或提供「清除」按鈕）。

### `ListItemRow.vue`

在現有「Collection tags」區塊（第 93-156 行）之前或之後，新增一排分類 chip，顯示 `item.anime.tags`，套用 `tagColor(tag)` 做背景/文字上色，樣式比照 `AnimeGridCard.vue`/`catalog.vue` 中既有的 tag chip（圓角膠囊、小字體）。純顯示，不可點擊互動（點擊分類篩選只在上方篩選列進行，避免互動語意混淆）。若作品沒有 tags，不顯示這排。

## 錯誤處理

無新增的錯誤情境——本次改動不引入新的 API 呼叫、新的資料寫入路徑，純粹是既有資料的展示與前端過濾。既有的 loading/error toast 邏輯無需變動。

## 測試

- 後端：為 `AnimeListController` 的 index/publicList 回應補一個斷言，確認 `tags` 欄位存在且與 `Anime.tags` 一致（Feature test）。
- 前端：Vitest 對 `/list` 頁面的分類篩選 computed 邏輯做單元測試（給定 list + selectedTags，驗證 filteredList 結果），以及 tags 選項萃取 computed 的去重/計數正確性。
