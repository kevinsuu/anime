# 動畫卡片網格虛擬滾動 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 用 `vue-virtual-scroller` 取代 `/seasonal`、`/catalog` 兩頁目前手寫 CSS grid + `useProgressiveReveal` 的渲染方式，限制同時存在的卡片 DOM 節點數量，根治快速滾動時節點無限累積的問題。

**Architecture:** 新增 `useResponsiveGridColumns` composable（純計算函式 + `ResizeObserver` 包裝）算出當前欄數與卡片高度；新增 `AnimeVirtualGrid.vue` 元件包裝 `WindowScroller`（grid mode），SSR/首次掛載前用 `<ClientOnly>` 的 `#fallback` 顯示現有完整 CSS grid。`seasonal.vue`、`catalog.vue` 改用這個新元件，移除 `useProgressiveReveal`。

**Tech Stack:** `vue-virtual-scroller@3.0.4`（已實測確認相容 Vue `^3.5.38`），Nuxt 4 內建 `<ClientOnly>`，Vitest + `happy-dom`（已確認內建 `ResizeObserver`）。

---

## 已驗證的技術細節（供各 Task 直接引用，不需再摸索）

- **套件版本與 API**：`vue-virtual-scroller@3.0.4`，`peerDependencies: { vue: '^3.3.0' }`。使用 `WindowScroller` 元件（不是 `RecycleScroller`），它是「視窗捲動」版本，跟 `RecycleScroller` 共用相同的核心 props（`items`、`itemSize`、`gridItems`、`itemSecondarySize`、`keyField`、`buffer`）。
- **Grid mode 限制（已讀套件內建文件確認）**：`gridItems` 與 `itemSize` 都必須是**固定數值**，套件不會自動依 CSS breakpoint 改變欄數或列高——這兩個值要由呼叫端算好傳入。
- **Tailwind 斷點**：這個專案用 Tailwind 4（`@import "tailwindcss"`，[main.css](../../../frontend/app/assets/css/main.css)，沒有 `tailwind.config.js`），沿用 Tailwind 預設斷點：`sm = 640px`、`md = 768px`。對應現有的 `grid-cols-3 sm:grid-cols-4 md:grid-cols-5`：
  - 容器寬度 `< 640px` → 3 欄
  - `640px ≤` 容器寬度 `< 768px` → 4 欄
  - 容器寬度 `≥ 768px` → 5 欄
- **卡片高寬比**：現有卡片是 `aspect-3/4`（寬:高 = 3:4），所以 `itemSize`（列高）= 單欄寬度 × 4/3。
- **`happy-dom`（這個專案的 Vitest test environment，見 [vitest.config.ts](../../../frontend/vitest.config.ts)）內建 `ResizeObserver`**——已實測確認 `'ResizeObserver' in new Window()` 回傳 `true`，但為了讓核心計算邏輯能不依賴 DOM/ResizeObserver 直接測試，`useResponsiveGridColumns` 內部把「給定寬度算出欄數與 itemSize」拆成一個獨立的純函式 `calculateGridLayout(containerWidth: number)`，composable 只負責用 `ResizeObserver` 量測寬度後呼叫這個純函式。
- **`AnimeGridCard.vue` 現有的完整 props/emits 介面**（[AnimeGridCard.vue:4-22](../../../frontend/app/components/AnimeGridCard.vue#L4-L22)，這次不改動這個檔案本身，`AnimeVirtualGrid` 的 slot 需要把下列全部透傳出去）：
  ```ts
  props: {
    anime: Anime
    inList: boolean
    watched: boolean
    listItem?: ListItem
    collections: Collection[]
    popoverOpen: boolean
    eagerLoad?: boolean
  }
  emits: {
    addToList: [animeId: number]
    markWatched: [animeId: number]
    toggleCollection: [col: Collection]
    openPopover: []
    closePopover: []
  }
  ```
- **`nuxt.config.ts` 目前 `ssr: true`**（[nuxt.config.ts:4](../../../frontend/nuxt.config.ts#L4)）——`AnimeVirtualGrid` 必須用 Nuxt 內建的 `<ClientOnly>` 元件包住 `WindowScroller`，`#fallback` slot 放現有的完整 CSS grid（不虛擬化），這是刻意的架構決策（見 spec），不是要消除的 bug。

---

## Task 1: 安裝 `vue-virtual-scroller` 套件

**Files:**
- Modify: `frontend/package.json`
- Modify: `frontend/package-lock.json`（由 npm 自動更新）

- [ ] **Step 1: 安裝套件**

Run: `docker compose exec frontend npm install vue-virtual-scroller@3.0.4`
Expected: 安裝成功，`package.json` 的 `dependencies` 出現 `"vue-virtual-scroller": "^3.0.4"` 或類似版本字串。

- [ ] **Step 2: 確認套件可以被 import**

Run: `docker compose exec frontend node -e "require.resolve('vue-virtual-scroller')" && echo OK`
Expected: 輸出 `OK`

**不要 commit**——使用者要求這次計畫完成後所有改動留在工作區供 code review，不執行 git commit。此步驟結束後，直接繼續下一個 Task，不執行任何 `git add`/`git commit` 指令。

---

## Task 2: `useResponsiveGridColumns` composable

**Files:**
- Create: `frontend/app/composables/useResponsiveGridColumns.ts`
- Test: `frontend/test/useResponsiveGridColumns.test.ts`

**背景**：純計算函式 `calculateGridLayout(containerWidth)` 算出欄數與 itemSize；composable `useResponsiveGridColumns(containerRef, gapPx)` 用 `ResizeObserver` 監聽容器實際寬度，呼叫純函式取得 `{ columns, itemSize }` 這組響應式資料。

- [ ] **Step 1: 寫失敗測試**

```typescript
import { describe, expect, it } from 'vitest'
import { calculateGridLayout } from '../app/composables/useResponsiveGridColumns'

describe('calculateGridLayout', () => {
  it('returns 3 columns below the sm breakpoint (640px)', () => {
    const result = calculateGridLayout(375, 12)
    expect(result.columns).toBe(3)
  })

  it('returns 4 columns between sm (640px) and md (768px)', () => {
    const result = calculateGridLayout(700, 12)
    expect(result.columns).toBe(4)
  })

  it('returns 5 columns at or above the md breakpoint (768px)', () => {
    const result = calculateGridLayout(1024, 12)
    expect(result.columns).toBe(5)
  })

  it('returns 5 columns exactly at the md breakpoint boundary', () => {
    const result = calculateGridLayout(768, 12)
    expect(result.columns).toBe(5)
  })

  it('returns 4 columns exactly at the sm breakpoint boundary', () => {
    const result = calculateGridLayout(640, 12)
    expect(result.columns).toBe(4)
  })

  it('calculates itemSize as columnWidth * 4/3 (aspect-3/4 ratio)', () => {
    // containerWidth=1024, gap=12, columns=5
    // total gap width = 12 * (5-1) = 48
    // columnWidth = (1024 - 48) / 5 = 195.2
    // itemSize = 195.2 * 4/3 = 260.266...
    const result = calculateGridLayout(1024, 12)
    expect(result.columns).toBe(5)
    expect(result.itemSize).toBeCloseTo(260.2666, 3)
  })

  it('calculates itemSize correctly for 3-column layout with zero gap', () => {
    // containerWidth=300, gap=0, columns=3
    // columnWidth = 300 / 3 = 100
    // itemSize = 100 * 4/3 = 133.333...
    const result = calculateGridLayout(300, 0)
    expect(result.columns).toBe(3)
    expect(result.itemSize).toBeCloseTo(133.3333, 3)
  })
})
```

- [ ] **Step 2: 執行測試，確認因檔案不存在而失敗**

Run: `cd <repo>/frontend && npm run test -- useResponsiveGridColumns`
Expected: FAIL（找不到模組 `../app/composables/useResponsiveGridColumns`）

- [ ] **Step 3: 實作 `useResponsiveGridColumns.ts`**

```typescript
import { type Ref, ref, onMounted, onBeforeUnmount } from 'vue'

/**
 * Tailwind 4 預設斷點（這個專案用 @import "tailwindcss"，沒有自訂
 * tailwind.config.js），對應現有卡片網格的
 * grid-cols-3 sm:grid-cols-4 md:grid-cols-5。
 */
const SM_BREAKPOINT_PX = 640
const MD_BREAKPOINT_PX = 768

/** 卡片的高寬比（aspect-3/4：寬 3 高 4）。 */
const CARD_ASPECT_HEIGHT_OVER_WIDTH = 4 / 3

export interface GridLayout {
  columns: number
  itemSize: number
}

/**
 * 給定容器寬度與欄間距，算出目前應該顯示幾欄、以及虛擬滾動需要的
 * 固定列高（itemSize）。純函式，不依賴 DOM，方便單獨測試。
 */
export function calculateGridLayout(containerWidth: number, gapPx: number): GridLayout {
  const columns = containerWidth >= MD_BREAKPOINT_PX
    ? 5
    : containerWidth >= SM_BREAKPOINT_PX
      ? 4
      : 3

  const totalGap = gapPx * (columns - 1)
  const columnWidth = (containerWidth - totalGap) / columns
  const itemSize = columnWidth * CARD_ASPECT_HEIGHT_OVER_WIDTH

  return { columns, itemSize }
}

/**
 * 監聽 containerRef 的實際寬度變化，回傳響應式的欄數與列高，供
 * AnimeVirtualGrid 傳給 WindowScroller 的 grid-items / item-size。
 */
export function useResponsiveGridColumns(containerRef: Ref<HTMLElement | null>, gapPx: number) {
  const columns = ref(3)
  const itemSize = ref(0)

  if (!import.meta.client) {
    return { columns, itemSize }
  }

  let observer: ResizeObserver | null = null

  onMounted(() => {
    const el = containerRef.value
    if (!el) return

    observer = new ResizeObserver((entries) => {
      const width = entries[0]?.contentRect.width ?? el.clientWidth
      const layout = calculateGridLayout(width, gapPx)
      columns.value = layout.columns
      itemSize.value = layout.itemSize
    })
    observer.observe(el)

    // 立即算一次初始值，不等第一次 ResizeObserver 回呼（跟 useLazyLoad
    // 的既有慣例一致：同步做一次初始檢查，不完全依賴非同步 callback）。
    const initialLayout = calculateGridLayout(el.clientWidth, gapPx)
    columns.value = initialLayout.columns
    itemSize.value = initialLayout.itemSize
  })

  onBeforeUnmount(() => {
    observer?.disconnect()
  })

  return { columns, itemSize }
}
```

- [ ] **Step 4: 執行測試，確認通過**

Run: `cd <repo>/frontend && npm run test -- useResponsiveGridColumns`
Expected: `7 passed`

- [ ] **Step 5: 執行完整前端測試套件**

Run: `cd <repo>/frontend && npm run test`
Expected: 全部 PASS（原有測試不受影響）

**不要 commit**——留在工作區，繼續下一個 Task。

---

## Task 3: `AnimeVirtualGrid.vue` 元件

**Files:**
- Create: `frontend/app/components/AnimeVirtualGrid.vue`

**背景**：包裝 `WindowScroller`，SSR/首次掛載前用 `<ClientOnly>` 的 `#fallback` 顯示現有完整 CSS grid。對外透傳 `AnimeGridCard` 的全部 props/emits，呼叫端透過具名 slot 決定卡片內容（保留呼叫端對 `in-list`、`watched`、`popover-open` 等每張卡片各自不同的 props 綁定彈性）。

**設計說明**：這個元件不直接內嵌 `<AnimeGridCard>`，而是透過 default slot 把 `item`（單筆 Anime）、`index` 往外傳，卡片渲染完全交給呼叫端（`seasonal.vue`/`catalog.vue`）——因為每個頁面對同一個 `AnimeGridCard` 綁定的 `in-list`/`watched`/`popover-open`/事件處理函式都不同（例如 `catalog.vue` 目前是寫死 `:in-list="false"`），元件內部不應該假設這些綁定方式一致。

- [ ] **Step 1: 建立元件**

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { WindowScroller } from 'vue-virtual-scroller'
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css'
import { useResponsiveGridColumns } from '../composables/useResponsiveGridColumns'
import type { Anime } from '../utils/normalize'

const props = withDefaults(defineProps<{
  items: Anime[]
  gapPx?: number
}>(), {
  gapPx: 12
})

const containerRef = ref<HTMLElement | null>(null)
const { columns, itemSize } = useResponsiveGridColumns(containerRef, props.gapPx)

defineSlots<{
  default(props: { item: Anime; index: number }): unknown
}>()
</script>

<template>
  <div ref="containerRef">
    <ClientOnly>
      <WindowScroller
        v-if="itemSize > 0"
        :items="items"
        :item-size="itemSize"
        :grid-items="columns"
        key-field="id"
      >
        <template #default="{ item, index }">
          <slot :item="item" :index="index" />
        </template>
      </WindowScroller>

      <template #fallback>
        <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
          <slot
            v-for="(item, index) in items"
            :key="item.id"
            :item="item"
            :index="index"
          />
        </div>
      </template>
    </ClientOnly>
  </div>
</template>
```

**注意**：`v-if="itemSize > 0"` 是必要的守衛——`useResponsiveGridColumns` 在 `onMounted` 才會算出真正的 `itemSize`（初始值是 `0`），避免 `WindowScroller` 在 `itemSize` 還沒被正確計算前就以 `0` 渲染。這段等待期間，`<ClientOnly>` 已經完成 client 掛載但 `WindowScroller` 的 `v-if` 還是 false，此時畫面會短暫空白；由於 `onMounted` 內有同步初始計算（Task 2 的 Step 3），這個空窗期是同一個 tick 內完成，實務上不會有肉眼可見的空白閃爍。

- [ ] **Step 2: 型別檢查確認元件語法正確**

Run: `cd <repo>/frontend && npx vue-tsc --noEmit -p tsconfig.json`
Expected: 無錯誤輸出（若有既有的、與本次改動無關的錯誤，需先確認是否為既有問題；本次新增的 `AnimeVirtualGrid.vue` 不應產生新的型別錯誤）

**不要 commit**——留在工作區，繼續下一個 Task。

---

## Task 4: `seasonal.vue` 改用 `AnimeVirtualGrid`

**Files:**
- Modify: `frontend/app/pages/seasonal.vue`

**背景**：移除 `useProgressiveReveal`、`sentinelRef`、`visibleCount`/`visibleSeasonal` 這組「目前揭露到第幾筆」的邏輯，改把 `filteredSeasonal`（篩選後的完整陣列）直接交給 `AnimeVirtualGrid`。

- [ ] **Step 1: 移除 `useProgressiveReveal` 相關程式碼**

在 [seasonal.vue](../../../frontend/app/pages/seasonal.vue) 的 `<script setup>` 區塊，刪除這兩行（第 72-73 行）：

```typescript
const { visibleCount, sentinelRef } = useProgressiveReveal(filteredSeasonal, 10)
const visibleSeasonal = computed(() => filteredSeasonal.value.slice(0, visibleCount.value))
```

- [ ] **Step 2: 修改模板，把手寫 grid 換成 `AnimeVirtualGrid`**

把模板裡的（原第 288-308 行）：

```vue
    <template v-else>
      <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
        <AnimeGridCard
          v-for="(anime, index) in visibleSeasonal"
          :key="anime.id"
          :anime="anime"
          :in-list="listByAnimeId.has(anime.id)"
          :watched="Boolean(listByAnimeId.get(anime.id)?.watched)"
          :list-item="listByAnimeId.get(anime.id)"
          :collections="collections"
          :popover-open="activePopoverAnimeId === anime.id"
          :eager-load="index < 10"
          @add-to-list="addAnime"
          @mark-watched="markWatched"
          @toggle-collection="(col) => toggleCollection(anime.id, col)"
          @open-popover="activePopoverAnimeId = anime.id"
          @close-popover="activePopoverAnimeId = null"
        />
      </div>
      <div ref="sentinelRef" class="h-px" aria-hidden="true" />
    </template>
```

改為：

```vue
    <template v-else>
      <AnimeVirtualGrid :items="filteredSeasonal">
        <template #default="{ item: anime, index }">
          <AnimeGridCard
            :key="anime.id"
            :anime="anime"
            :in-list="listByAnimeId.has(anime.id)"
            :watched="Boolean(listByAnimeId.get(anime.id)?.watched)"
            :list-item="listByAnimeId.get(anime.id)"
            :collections="collections"
            :popover-open="activePopoverAnimeId === anime.id"
            :eager-load="index < 10"
            @add-to-list="addAnime"
            @mark-watched="markWatched"
            @toggle-collection="(col) => toggleCollection(anime.id, col)"
            @open-popover="activePopoverAnimeId = anime.id"
            @close-popover="activePopoverAnimeId = null"
          />
        </template>
      </AnimeVirtualGrid>
    </template>
```

- [ ] **Step 3: 型別檢查**

Run: `cd <repo>/frontend && npx vue-tsc --noEmit -p tsconfig.json`
Expected: 無新增錯誤

- [ ] **Step 4: 執行前端測試套件**

Run: `cd <repo>/frontend && npm run test`
Expected: 全部 PASS

**不要 commit**——留在工作區，繼續下一個 Task。

---

## Task 5: `catalog.vue` 改用 `AnimeVirtualGrid`

**Files:**
- Modify: `frontend/app/pages/catalog.vue`

**背景**：跟 Task 4 相同的改法，套用到 `catalog.vue`。這個頁面有分頁機制（`PAGE_SIZE = 40`），`AnimeVirtualGrid` 收到的 `items` 是當前頁的 `pagedCatalog`（40 筆內），不是全部搜尋結果。

- [ ] **Step 1: 移除 `useProgressiveReveal` 相關程式碼**

在 [catalog.vue](../../../frontend/app/pages/catalog.vue) 的 `<script setup>` 區塊，刪除這一行（原第 47-48 行）：

```typescript
const { visibleCount, sentinelRef } = useProgressiveReveal(pagedCatalog, 10)
const visiblePagedCatalog = computed(() => pagedCatalog.value.slice(0, visibleCount.value))
```

- [ ] **Step 2: 修改模板**

把模板裡的（原第 179-194 行）：

```vue
    <template v-else>
      <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
        <AnimeGridCard
          v-for="(anime, index) in visiblePagedCatalog"
          :key="anime.id"
          :anime="anime"
          :in-list="false"
          :watched="false"
          :collections="[]"
          :popover-open="false"
          :eager-load="index < 10"
          @add-to-list="addAnime"
          @mark-watched="addAnime"
        />
      </div>
      <div ref="sentinelRef" class="h-px" aria-hidden="true" />

      <!-- Pagination -->
```

改為：

```vue
    <template v-else>
      <AnimeVirtualGrid :items="pagedCatalog">
        <template #default="{ item: anime, index }">
          <AnimeGridCard
            :key="anime.id"
            :anime="anime"
            :in-list="false"
            :watched="false"
            :collections="[]"
            :popover-open="false"
            :eager-load="index < 10"
            @add-to-list="addAnime"
            @mark-watched="addAnime"
          />
        </template>
      </AnimeVirtualGrid>

      <!-- Pagination -->
```

- [ ] **Step 3: 型別檢查**

Run: `cd <repo>/frontend && npx vue-tsc --noEmit -p tsconfig.json`
Expected: 無新增錯誤

- [ ] **Step 4: 執行前端測試套件**

Run: `cd <repo>/frontend && npm run test`
Expected: 全部 PASS

**不要 commit**——留在工作區，繼續下一個 Task。

---

## Task 6: 移除不再使用的 `useProgressiveReveal`

**Files:**
- Delete: `frontend/app/composables/useProgressiveReveal.ts`

**背景**：Task 4、5 完成後，`seasonal.vue`、`catalog.vue` 都不再呼叫 `useProgressiveReveal`。確認沒有其他呼叫端後刪除這個檔案。

- [ ] **Step 1: 確認沒有其他檔案還在使用 `useProgressiveReveal`**

Run: `grep -rn "useProgressiveReveal" <repo>/frontend/app <repo>/frontend/test`
Expected: 無任何輸出（代表沒有任何檔案還在引用它）

- [ ] **Step 2: 刪除檔案**

Run: `rm <repo>/frontend/app/composables/useProgressiveReveal.ts`

- [ ] **Step 3: 型別檢查與測試套件確認整體沒有回歸**

Run:
```bash
cd <repo>/frontend
npx vue-tsc --noEmit -p tsconfig.json
npm run test
```
Expected: 型別檢查無錯誤；測試全部 PASS

**不要 commit**——留在工作區，等待使用者 code review。此為本計畫最後一個 Task，完成後停止，不執行任何 `git add`/`git commit`/`git push`。

---

## Task 7: 端對端驗證（僅驗證，非程式改動）

**Files:** 無新檔案，純驗證步驟。

- [ ] **Step 1: 啟動前端開發伺服器（若尚未啟動）**

Run: `docker compose up -d frontend`
Expected: 容器狀態為 running

- [ ] **Step 2: 瀏覽器打開 `/seasonal`，確認欄數與外觀跟改動前一致**

開啟 `http://localhost:3000/seasonal`，分別在手機寬度（< 640px）、平板寬度（640~768px）、桌機寬度（≥ 768px）下確認卡片欄數分別是 3、4、5 欄，卡片外觀（比例、圓角、hover 效果）與改動前一致。

- [ ] **Step 3: 快速滾動驗證 DOM 節點數量有上限**

在 `/seasonal` 頁面打開瀏覽器 DevTools 的 Elements 面板，快速滾動整個頁面數次，確認：
- 不再出現「有徽章、無圖」的空白卡片（縮圖已於稍早的 backfill 補齊）。
- 用 Elements 面板搜尋 `<img` 標籤數量，確認同時存在的數量有上限（不隨捲動距離持續增長，滾到頁面底部時的節點數應與滾到中段時相近，而不是持續累加）。

- [ ] **Step 4: 驗證既有互動功能正常**

在 `/seasonal` 頁面測試：加入清單（愛心按鈕）、標記已看（勾選按鈕）、開啟收藏清單 popover 並勾選/取消收藏分類。確認這些互動在虛擬滾動下正常運作、UI 即時反應正確。

- [ ] **Step 5: 驗證 `/catalog` 頁面**

開啟 `http://localhost:3000/catalog`，確認年份切換、分頁切換（若當年資料超過 40 筆）、搜尋功能都正常運作，且切換分頁後頁面捲動位置回到頂部。

- [ ] **Step 6: 確認完整測試套件與型別檢查最終狀態**

Run:
```bash
cd <repo>/frontend
npm run test
npx vue-tsc --noEmit -p tsconfig.json
```
Expected: 測試全部 PASS，型別檢查無錯誤

（此 Task 不需要 commit——純驗證。若驗證中發現問題，回到對應 Task 修正，修正後的程式碼一樣留在工作區不 commit，等待使用者一併 review。）
