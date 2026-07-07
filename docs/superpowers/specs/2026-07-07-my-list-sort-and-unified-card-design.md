# 我的清單排序 ＋ 統一資料庫搜尋卡片模板 設計

日期：2026-07-07

## 目標

1. 我的清單（`/list`）加入排序：**播出日期 / 加入清單日期 / 年份**（皆新→舊）。
2. 我的清單搜尋卡片改用與資料庫頁（`/catalog`「搜尋動漫資料庫」）**相同的版面模板**，統一介面。

## 關於「是否要調整後端」

使用者的疑慮：排序是否要搬後端，否則前端負荷太大。結論：**不需要把排序搬後端**。

- `myList()` 一次回傳整份清單（約 761 筆），已全載入前端（`fullList`/`list`）。對這規模做 `Array.sort` 是毫秒級，瀏覽器無壓力。搜尋/狀態/分類過濾本來就在前端做，排序同理。
- **唯一的後端改動**：目前 `AnimeListController::formatItem()` 的 anime 物件只給 `id/name/description/imageUrl/tags`，**沒有** `air_date` 與 `season_year`。要照「播出日期」「年份」排序，前端需要這兩個欄位。所以後端只是**在 formatItem 補兩個欄位**，不是搬排序邏輯。
- 「加入清單日期」用現有的 `createdAt` 即可，無需後端改動。
- 前端 `normalizeAnime` 已能讀 `seasonYear`/`airDate`（camelCase 與 snake_case 皆吃，見 normalize.ts:93/95），補欄位後前端自動可用。

## 設計決策（已與使用者確認）

- 排序選項：**播出日期（新→舊）、加入清單日期（新→舊）、年份（新→舊）**，預設「加入清單日期」（貼近目前後端 `orderByDesc('updated_at')` 的近似行為，且不依賴新欄位、最穩）。
- 排序控制件：**搜尋框左側下拉選單**，對齊 catalog 的「近期/年份」位置。
- **綠色搜尋按鈕**：純裝飾對齊 catalog 外觀。list 為即時過濾，按鈕不觸發查詢（即時 v-model 已過濾）；按下僅收合鍵盤/失焦，不改變結果。
- 卡片模板對齊 catalog：頂排 `[排序下拉] [搜尋框 flex-1] [綠色搜尋鈕]`，分隔線下方分類 chips。
- 保留上一輪加入的「清除全部篩選」按鈕（清搜尋＋分類）。

## 後端改動

### `backend/app/Http/Controllers/Api/AnimeListController.php`

`formatItem()` 的 `anime` 陣列補上兩欄（其餘不動）：

```php
'anime' => [
    'id' => $item->anime->id,
    'name' => $item->anime->name,
    'description' => $item->anime->description,
    'imageUrl' => $item->anime->image_url,
    'tags' => $item->anime->tags ?? [],
    'season_year' => $item->anime->season_year,
    'air_date' => $item->anime->air_date,
],
```

影響：`index`（我的清單）與 `publicList`（公開清單）都經 `formatItem`，兩者都會多這兩欄，無害。

### 後端測試

`backend/tests/`：既有涵蓋 `/my/anime-list` 的 feature test 若斷言回傳結構，補上 `season_year`/`air_date` 存在的斷言；若無專門斷言 anime 欄位則不必新增測試（欄位為單純透傳）。以 `php artisan test` 綠燈為準。

## 前端改動

### 新增排序純函式 `frontend/app/utils/listFilters.ts`

```ts
export type ListSortKey = 'added' | 'airDate' | 'year'

// 排序皆為新→舊。airDate/year 缺值（null）者排到最後，避免污染前段。
export function applyListSort(list: ListItem[], sort: ListSortKey): ListItem[] {
  const copy = [...list]
  if (sort === 'added') {
    return copy.sort((a, b) => b.createdAt.localeCompare(a.createdAt))
  }
  if (sort === 'airDate') {
    return copy.sort((a, b) => nullsLast(a.anime.airDate, b.anime.airDate, (x, y) => y.localeCompare(x)))
  }
  // year
  return copy.sort((a, b) => nullsLast(a.anime.seasonYear, b.anime.seasonYear, (x, y) => y - x))
}

// 兩值皆有 → cmp；只有一方有 → 有值在前；皆無 → 相等。
function nullsLast<T>(a: T | null, b: T | null, cmp: (x: T, y: T) => number): number {
  if (a === null && b === null) return 0
  if (a === null) return 1
  if (b === null) return -1
  return cmp(a, b)
}
```

`applyListSort` 為純函式、非破壞性（複製後排序），與 `applyListFilters`/`applyTitleSearch` 同檔同職責，可獨立單元測試。

### 修改 `frontend/app/pages/list/index.vue`

**Script：**
- import 追加 `applyListSort`、型別 `ListSortKey`。
- 新增 `const sortKey = ref<ListSortKey>('added')`（只存本地）。
- `filteredList` 尾端套排序：
  ```ts
  const filteredList = computed(() =>
    applyListSort(
      applyTitleSearch(applyListFilters(list.value, activeFilter.value), searchQuery.value),
      sortKey.value
    )
  )
  ```
- 新增裝飾用 `function onSearchSubmit(e: Event) { (e.target as HTMLFormElement)?.querySelector('input')?.blur() }`（或直接 `@submit.prevent` 不接函式——即時過濾已生效，submit 僅 prevent 預設行為）。採**最簡**：`@submit.prevent`，不接函式。

**Template（卡片改為 catalog 模板）：**
把現有搜尋卡片頂排改成 `flex` 一排，依序：
1. **排序下拉**（`<select v-model="sortKey">`，選項：加入日期／播出日期／年份），樣式對齊 catalog 左控制項（`rounded-lg border ... px-3 py-2.5 text-sm`）。
2. **搜尋框 flex-1**（現有 input，包進 `<form @submit.prevent>`）。
3. **綠色搜尋按鈕**（`type="submit"`，sr 對齊 catalog：`rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white ...`，內容文字「搜尋」）。
4. 「清除全部篩選」：維持，但併到這排右側或分隔線區——採維持現狀（有 active filter 時顯示於搜尋鈕右側）。
5. 分隔線下方分類 chips：不動。

排序下拉選項標籤：
- `added` → 「加入日期」
- `airDate` → 「播出日期」
- `year` → 「年份」

### 前端測試

`frontend/test/listFilters.test.ts` 新增 `applyListSort` 測試：
1. `added`：依 createdAt 新→舊。
2. `airDate`：依 airDate 新→舊，null 排最後。
3. `year`：依 seasonYear 新→舊，null 排最後。
4. 非破壞性：不改動傳入陣列（原陣列順序不變）。
5. 與過濾疊加：`applyListSort(applyTitleSearch(applyListFilters(...)))` 結果正確。

`makeListItem` helper 擴充支援 `createdAt`、`airDate`、`seasonYear`（透過 `anime.air_date`/`anime.season_year`、item `createdAt`，經 normalizeListItem/normalizeAnime）。

元件層維持慣例（不 mount 測試）；`npm run build` + dev server 手動驗證版面與排序。

## 過濾／排序總順序

```
list（後端 tags 結果）
  → applyListFilters（狀態）
  → applyTitleSearch（標題）
  → applyListSort（排序，新增）
  → filteredList
```

## 風險

- `createdAt` 為 `YYYY-MM-DD HH:MM:SS` 字串，`localeCompare` 對此格式字典序等同時間序，排序正確。
- `airDate` 若後端為 `YYYY-MM-DD` 字串同理；`seasonYear` 為數值。normalize 已保證型別（缺值為 null）。
- 綠色搜尋鈕為裝飾：務必在 spec/註解標明「即時過濾、按鈕不觸發查詢」，避免日後誤解為缺少查詢邏輯。
