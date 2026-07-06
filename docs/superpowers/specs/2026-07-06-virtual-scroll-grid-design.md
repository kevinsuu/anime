# 動畫卡片網格虛擬滾動設計

## 背景與問題

`/seasonal`、`/catalog` 兩個頁面用 `useProgressiveReveal` 分批把卡片揭露進 DOM——卡片一旦進場就不會再消失。快速滾動長列表時，同時存在的 `<img>` 數量隨捲動距離持續累積，這是白卡問題（[2026-07-03 縮圖 pipeline 設計文件](2026-07-03-anime-cover-thumbnail-design.md)已解決「原圖過大」那一半根因）之外，另一個結構性成因：DOM 節點本身沒有上限。

縮圖 backfill 補齊後（單張 15-25KB WebP）能大幅緩解下載壓力，但不解決「同時存在幾百個 `<img>` 節點、瀏覽器仍要為每個節點維持佈局/合成成本」這件事。虛擬滾動（只保留可視範圍 ± buffer 的卡片在 DOM 裡，其餘回收）從根本上限制同時存在的節點數量。

## 目標

- 用 `vue-virtual-scroller`（已實測確認支援 Vue 3.5、有 grid mode）取代目前手寫的 CSS grid + `useProgressiveReveal` 揭露機制。
- `/seasonal`、`/catalog` 兩個頁面都套用，體驗一致。
- 維持現有的響應式欄數（< sm: 3 欄、sm~md: 4 欄、≥ md: 5 欄，對應 Tailwind 的 `grid-cols-3 sm:grid-cols-4 md:grid-cols-5`）與卡片外觀（`aspect-3/4`），使用者視覺上感覺不到改動，只有滾動效能變好。
- 保留 `useLazyLoad`（IntersectionObserver 控制圖片何時設定 `src`），移除 `useProgressiveReveal`（虛擬滾動本身已取代它的 DOM 節點管理職責）。
- 頁面維持整頁自然捲動（不want 卡片區塊自己開一個內部捲軸），沿用既有的頁首、篩選列、分頁控制項佈局。

## 技術選型（已實測驗證）

- **套件**：`vue-virtual-scroller@3.0.4`，`peerDependencies: { vue: '^3.3.0' }`，涵蓋專案目前的 Vue `^3.5.38`。
- **元件**：`WindowScroller`（不是 `RecycleScroller`）——文件明確列出 `WindowScroller` 支援與 `RecycleScroller` 相同的核心 props（含 `gridItems`），差異只在於「視窗捲動永遠開啟」，這正好對應「頁面自然捲動、不要獨立捲動框」的需求。
- **Grid mode 的限制（已讀套件內建文件確認）**：`gridItems` prop 是一個**固定數值**，套件本身不會依 CSS breakpoint 自動改變欄數；`itemSize`（列高）在 grid mode 下也必須是**固定數值**，不支援每列不同高度。這代表響應式欄數與對應的卡片高度，需要我們自己用 JS 計算後傳入，套件只負責「拿到欄數與列高之後的虛擬化渲染」。
- **資料結構**：`items` 是攤平的一維陣列，套件內部依 `gridItems` 自動分行，不需要把 `visibleSeasonal`/`pagedCatalog` 重組成二維陣列。

## 架構

```
新增 frontend/app/composables/useResponsiveGridColumns.ts
  ├─ 用 ResizeObserver 監聽容器寬度
  ├─ 依斷點規則算出目前欄數（columns）：
  │     width < 640px（sm 斷點以下） → 3
  │     640px ≤ width < 768px（sm~md） → 4
  │     width ≥ 768px（md 以上） → 5
  │   （對應現有 Tailwind class `grid-cols-3 sm:grid-cols-4 md:grid-cols-5`
  │    的斷點值，需在實作時對照 tailwind.config 確認 sm/md 實際 px 值）
  └─ 算出目前單欄寬度（容器寬度 ÷ columns，扣除 gap）
      × 4/3（aspect-3/4 的高寬比）得到 itemSize（列高，含 gap）

新增 frontend/app/components/AnimeVirtualGrid.vue
  ├─ Props: items: Anime[]（攤平陣列，呼叫端已完成篩選/分頁）
  ├─ 內部用 useResponsiveGridColumns() 取得 columns、itemSize
  ├─ 用 WindowScroller，:items="items" :grid-items="columns" :item-size="itemSize"
  ├─ 用 default slot 把單一 item 往外傳出（呼叫端決定要渲染什麼卡片內容）
  └─ 對外 emit 的事件（add-to-list、mark-watched、toggle-collection、
     open-popover、close-popover）原樣透傳，不在這層攔截或轉譯

seasonal.vue / catalog.vue（改動）
  ├─ 移除 useProgressiveReveal 的呼叫、sentinelRef、visibleCount/visibleSeasonal
  │   （seasonal.vue）或 visibleCount/visiblePagedCatalog（catalog.vue）這類
  │   衍生的「目前揭露到第幾筆」計算 —— 直接把篩選/分頁後的完整陣列交給
  │   AnimeVirtualGrid，虛擬滾動自己決定哪些要渲染
  ├─ 移除手寫的 <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5">
  │   容器，改用 <AnimeVirtualGrid :items="...">，內部 slot 放 AnimeGridCard
  └─ 其餘邏輯（篩選狀態、清單/收藏互動、分頁按鈕、SEO meta）完全不變

AnimeGridCard.vue（不改動業務邏輯）
  └─ useLazyLoad 保留：套件回收/重用 DOM 節點時，Vue 的元件生命週期
     （unmount 舊卡片、mount 新卡片，或是 prop 更新同一個元件實例）
     仍會正確觸發 useLazyLoad 的 onMounted/watch 邏輯，不需要特別處理
     虛擬滾動的節點重用
```

## 兩個頁面的差異考量

`catalog.vue` 目前有分頁機制（`PAGE_SIZE = 40`，每頁最多 40 筆再手動翻頁），单頁資料量本來就有上限，虛擬滾動在這裡的迫切性低於 `/seasonal`（一次性顯示整季全部，可能上百筆無分頁）。但兩頁都套用是為了行為一致性——`catalog.vue` 套用後，`AnimeVirtualGrid` 收到的 `items` 就是 `visiblePagedCatalog` 曾經代表的、當前頁面的 40 筆，虛擬滾動在資料量小的情境下仍然正常運作（只是可視範圍內大概率就能裝下全部 40 筆，回收行為不明顯，這是預期中的正常現象，不是 bug）。

## 互動相容性

- **收藏清單 popover**：現況用 `activePopoverAnimeId`（記錄「哪個 anime id 的 popover 是開啟的」，不是存 DOM/元件參考）判斷要不要顯示 popover，這個模式與虛擬滾動相容——卡片被回收重建不影響這個 id 比對邏輯。
- **`eager-load`（前幾張圖優先載入）**：目前用 `index < 10` 判斷。虛擬滾動情境下，`index` 對應的是「在完整 `items` 陣列中的位置」，不是「目前在 DOM 中第幾個可見節點」，這個判斷邏輯不需要修改，含義不變。

## 失敗處理與邊界情況

| 情境 | 行為 |
|---|---|
| `items` 為空陣列（篩選後沒有符合結果） | `AnimeVirtualGrid` 不渲染任何 `WindowScroller` 內容，呼叫端維持現有的「沒有找到符合的作品」空狀態提示（這段邏輯在 `AnimeVirtualGrid` 外層，不受影響） |
| 視窗寬度在斷點邊界快速震盪（例如使用者手動拖拉瀏覽器寬度） | `useResponsiveGridColumns` 的 `ResizeObserver` 回呼觸發頻率交由瀏覽器原生節流，不額外做 debounce——先以簡單實作驗證是否有明顯效能問題，若有觀察到再補 debounce |
| SSR（伺服器端渲染）階段 | `WindowScroller` 依賴 `window`/`ResizeObserver`，只在 client 端運作。專案目前 `ssr: true`，`AnimeVirtualGrid` 內部用 `<ClientOnly>`（Nuxt 內建元件）包住 `WindowScroller`，並提供 `#fallback` slot：SSR 階段與 client 首次掛載前，`#fallback` 渲染現有的完整手寫 CSS grid（`grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5`，不虛擬化，全部 `items` 直接 `v-for`），保留 SEO 可爬取性與無 JS 環境下的可見性。Client 端 `<ClientOnly>` 完成掛載後才換成 `WindowScroller` 虛擬滾動版本。這個切換是刻意的、預期中的行為（不是要消除的 hydration mismatch），使用者會感覺到首次載入後有一次「內容從完整 grid 換成虛擬滾動」的重排，這是為了維持 SEO 與 SSR 内容可見性所做的取捨。 |

## 測試計畫

- `useResponsiveGridColumns` 的單元測試：驗證不同容器寬度輸入對應正確的 columns 與 itemSize 計算結果。
- 手動驗證：
  - 兩個頁面在三種螢幕寬度（手機/平板/桌機）下欄數與卡片外觀與改動前一致。
  - 快速滾動 `/seasonal`（縮圖已補齊的資料）確認不再出現白卡，且用瀏覽器 DevTools 的 Elements 面板確認同時存在的卡片 DOM 節點數量有上限（不隨捲動距離增長）。
  - 收藏清單 popover、加入清單、標記已看等既有互動在虛擬滾動下正常運作。
  - `/catalog` 的分頁切換與虛擬滾動共存，切換分頁後捲動位置回到頂部（現有 `watch(page, ...)` 邏輯不變）。

## 範圍外（Out of scope）

- `/anime/[id]` 詳情頁與其他非網格列表頁面不受影響。
- 不處理 `DynamicScroller`（可變高度）模式——現有卡片高度由固定的 `aspect-3/4` 決定，不需要逐項量測。
- 不新增虛擬滾動的無障礙（screen reader）特殊處理，沿用套件預設行為。
