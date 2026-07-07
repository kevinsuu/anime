# 我的清單標題搜尋設計

日期：2026-07-07

## 目標

在 `/list`（我的清單）頁加入標題搜尋，樣式參考 `/catalog`（資料庫）頁的搜尋卡片，但搜尋範圍限縮在使用者自己清單內的作品。

## 背景

`app/pages/list/index.vue` 目前：
- 進站 `loadAll()` 一次把整份清單載入 `fullList`（screenshot 顯示 761 部）。
- 左側 sidebar：狀態切換（全部/已看/未看）＋自訂清單（col:{id}），透過 URL query `filter` 控制。
- 分類 chip 列：透過 URL query `tags` 走**後端**過濾（`api.myList({ tags })`），結果放進 `list`。
- 渲染鏈：`list`（後端分類結果）→ `applyListFilters(list, activeFilter)`（前端狀態過濾）→ `filteredList` → `ListItemRow`。
- header「共 X 部」綁 `filteredList.length`。

缺少的是**標題文字搜尋框**。因為資料已全載入前端，標題搜尋純前端即時過濾即可，零後端改動。

## 設計決策（已與使用者確認）

- **即時前端過濾**：不需搜尋按鈕，輸入即過濾。
- **搜尋＋分類 chip 同卡片**：白底卡片內上排搜尋框、分隔線下方分類 chip。
- **只存本地狀態**：`searchQuery` 不寫進 URL，重整理即清空（清單是 noindex 私人頁，無分享需求）。
- **專屬空結果提示**：有搜尋輸入但過濾後 0 筆時，顯示「找不到符合「XX」的作品」＋清除搜尋按鈕，與原本「這裡還沒有作品→去新番表」的空清單狀態區分。

## 過濾順序

```
list（後端分類 tags 結果）
  → applyListFilters(list, activeFilter)        # 狀態：all/watched/unwatched/col:{id}
  → applyTitleSearch(_, searchQuery)            # 標題比對（新增）
  → filteredList → 渲染
```

標題比對規則（新純函式 `applyTitleSearch`）：
- 空字串（trim 後）→ 回傳原陣列，不過濾。
- 否則保留 `item.anime.name` 或 `item.anime.titleJa` **包含** query 的項目。
- 不分大小寫（雙方 `.toLowerCase()` 後比對）。
- query 先 `.trim()`。

比對欄位選 `name`（主顯示標題，ListItemRow 顯示的就是它）與 `titleJa`（日文原名），涵蓋使用者可能用中文或日文搜尋的情境。

## 元件與檔案

### 新增純函式 `app/utils/listFilters.ts`

```ts
export function applyTitleSearch(list: ListItem[], query: string): ListItem[] {
  const q = query.trim().toLowerCase()
  if (q === '') return list
  return list.filter(item =>
    item.anime.name.toLowerCase().includes(q) ||
    item.anime.titleJa.toLowerCase().includes(q)
  )
}
```

放在既有 `applyListFilters` 旁邊，同檔案、同職責（清單過濾純函式），維持可獨立單元測試。

### 修改 `app/pages/list/index.vue`

**Script：**
- 新增 `const searchQuery = ref('')`。
- `filteredList` computed 改為兩層套用：
  ```ts
  const filteredList = computed(() =>
    applyTitleSearch(applyListFilters(list.value, activeFilter.value), searchQuery.value)
  )
  ```
- 匯入 `applyTitleSearch`。

**Template：**
- 在 header 之後、`v-if="tagOptions.length > 0"` 的 chip 列之前，插入白底卡片容器 `<div class="space-y-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">`，內含：
  - 上排搜尋框：放大鏡 icon + `<input v-model="searchQuery" type="search" placeholder="搜尋清單內作品…">`，樣式沿用 catalog 的 input class（圓角、focus ring）。**無搜尋按鈕**。
  - 現有 chip 列搬進卡片內，包在 `border-t border-gray-100 pt-3` 分隔線下（沿用 catalog 卡片內 chip 的分隔線樣式）。chip 的 `v-if="tagOptions.length > 0"` 與 toggle/clear 邏輯不變。
- 空結果分支調整：
  - 原本 `v-else-if="filteredList.length === 0"` 拆成兩種：
    - 有搜尋輸入（`searchQuery.trim() !== ''`）且 0 筆 → 顯示「找不到符合「{{ searchQuery }}」的作品」+「清除搜尋」按鈕（點擊 `searchQuery = ''`）。
    - 否則（無搜尋輸入仍 0 筆）→ 維持原本「這裡還沒有作品→去新番表」。

### 不改動

- 左側 sidebar（狀態切換、自訂清單、新增清單）：不動。
- 分類 chip 的後端 tags 過濾、toggle/clear 邏輯：不動（只是外層包進卡片）。
- `loadAll`、`updateItem`、`removeItem`、collection 操作：不動。
- 後端：不動。
- `applyListFilters`：不動。

## 測試

`frontend/test/listFilters.test.ts`（既有檔）新增 `applyTitleSearch` 測試：
1. 空字串 → 回傳原陣列（不過濾）。
2. 命中 `name`（中文標題部分比對）。
3. 命中 `titleJa`（日文原名部分比對）。
4. 不分大小寫（大寫 query 命中小寫標題）。
5. query 前後空白被 trim。
6. 與 `applyListFilters` 疊加：先狀態過濾再標題過濾，結果正確。

元件層維持專案慣例（不 mount 測試）；以 `npm run build` + dev server 手動驗證搜尋、卡片版面、空結果提示。

## 風險

- `item.anime.name` / `titleJa` 由 `normalizeListItem` 保證為字串（`repairText` 有預設值），不會是 undefined，`toLowerCase()` 安全。
- 即時過濾在 761 筆規模下為簡單陣列 filter，效能無虞，不需 debounce。
