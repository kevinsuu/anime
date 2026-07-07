# 資料庫頁初始載入 hydration 修復 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 修復 `/catalog` 重新整理時有時不顯示資料的 hydration bug，讓 `useAsyncData` 直接接管初始查詢結果。

**Architecture:** 目前 `useAsyncData('catalog-initial')` 的 handler 回傳 `true`、把結果以副作用寫進獨立 `catalog` ref；`ssr: true` 下該 ref 不會進 payload，client hydration 跳過重跑導致 `catalog` 為空。改為讓 handler 直接回傳 raw items 陣列（進 payload），以其 `data` 初始化 `catalog`、以其 `pending` 驅動初始 loading。後續互動維持既有 `loadCatalog()` + `loading` + `requestId` 流程。

**Tech Stack:** Nuxt 4（`ssr: true`）、Vue 3 `<script setup>`、既有 `useApi` composable。

---

## File Structure

- **Modify:** `frontend/app/pages/catalog.vue`
  - 初始載入區塊（`useAsyncData` + `catalog` ref 宣告）改寫。
  - `catalog` ref 初值改由 `useAsyncData` 的 `data` 提供。
  - 模板 skeleton 條件加入 `initialPending`。
  - `loadCatalog` 保留不動（供後續互動）。

僅一個檔案。無新增檔案，無後端改動。

## 測試策略說明

既有前端測試（`frontend/test/*.test.ts`）皆為 composable/util 純函式單元測試；專案未安裝 `@nuxt/test-utils`，無 page-mount harness。本修復的核心是 Nuxt `useAsyncData` 的 payload/hydration 行為，純函式單元測試無法真實觸及。因此驗證方式為：

1. `npm run build`（型別 + 建置檢查，確保改寫無型別錯誤）。
2. dev server 手動驗證：重新整理 `/catalog` 多次，資料穩定出現、初始顯示 skeleton 而非空狀態。

不新增會測到 mock 而非 bug 本身的假 page-mount 測試。

---

### Task 1: 改寫 catalog.vue 初始載入為 useAsyncData 接管資料

**Files:**
- Modify: `frontend/app/pages/catalog.vue`

- [ ] **Step 1: 改寫 catalog ref 宣告與 useAsyncData 區塊**

在 `frontend/app/pages/catalog.vue` 中，找到目前這段（`<script setup>` 內，`loadCatalog` 定義之後、`useAsyncData` 進站載入處）：

```ts
// 主要資料來源：依 activeYear / query / selectedTags 向後端查詢
const catalog = ref<Anime[]>([])
const loading = ref(false)
let requestId = 0

async function loadCatalog() {
```

將 `const catalog = ref<Anime[]>([])` 這一行**移除**（稍後改由 asyncData 初始化），保留 `loading` 與 `requestId`。改為：

```ts
// 主要資料來源：依 activeYear / query / selectedTags 向後端查詢
const loading = ref(false)
let requestId = 0

async function loadCatalog() {
```

- [ ] **Step 2: 以回傳資料的 useAsyncData 取代副作用版本，並用其 data 初始化 catalog**

找到目前這段：

```ts
// 進站載入近期模式
await useAsyncData('catalog-initial', async () => {
  await loadCatalog()
  return true
}, { default: () => true })
```

替換為：

```ts
// 進站載入近期模式：讓 useAsyncData 直接接管查詢結果，回傳值進 payload、
// hydration 後仍在，避免 client 端重跑跳過導致 catalog 落空的空狀態 bug。
const { data: initialData, pending: initialPending } = await useAsyncData(
  'catalog-initial',
  async () => {
    const result = await api.searchAnime('', {})
    return (result.items || []) as Record<string, any>[]
  }
)

// catalog 以初始資料為基礎；後續互動由 loadCatalog 直接覆寫。
const catalog = ref<Anime[]>((initialData.value || []).map(normalizeAnime))
```

注意：`catalog` 的宣告現在位於 `useAsyncData` **之後**。確認原本引用 `catalog` 的其他 setup 程式（`totalPages`、`pagedCatalog` 等 computed）在檔案中都在此宣告**之後**（原本就在 `useAsyncData` 之後，順序不變即可）。

- [ ] **Step 3: 模板 skeleton 條件加入 initialPending**

找到模板中的 loading skeleton：

```html
<!-- Loading skeleton: matches PAGE_SIZE so the layout doesn't jump when real content arrives -->
<div v-if="loading" class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
  <div v-for="i in PAGE_SIZE" :key="i" class="aspect-3/4 w-full animate-pulse rounded-md bg-gray-200" />
</div>
```

將 `v-if="loading"` 改為 `v-if="loading || initialPending"`：

```html
<!-- Loading skeleton: matches PAGE_SIZE so the layout doesn't jump when real content arrives -->
<div v-if="loading || initialPending" class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
  <div v-for="i in PAGE_SIZE" :key="i" class="aspect-3/4 w-full animate-pulse rounded-md bg-gray-200" />
</div>
```

模板下方的 `v-else-if="catalog.length === 0 && !error"` 與 `<template v-else>` 不需更動——它們自動接在新的 `v-if` 之後。

- [ ] **Step 4: 建置與型別檢查**

Run:
```bash
cd /Users/sumingkai/Documents/anime/frontend && npm run build
```
Expected: 建置成功、無型別錯誤。特別確認無 "Cannot find name 'catalog'" 或 "used before declaration" 類錯誤（若出現，代表有 computed 在 `catalog` 宣告前引用，需將 `catalog` 宣告上移到那些 computed 之前、但仍在 `useAsyncData` 之後）。

- [ ] **Step 5: 執行既有前端單元測試（確保未回歸）**

Run:
```bash
cd /Users/sumingkai/Documents/anime/frontend && npm run test
```
Expected: 既有測試全數通過（本改動不觸及被測的 util/composable，應維持綠燈）。

- [ ] **Step 6: dev server 手動驗證**

啟動 dev 環境（若尚未啟動）：
```bash
cd /Users/sumingkai/Documents/anime && docker compose up -d frontend
```
在瀏覽器開 `http://localhost:3000/catalog`，連續重新整理 5～10 次，確認：
- 每次都穩定顯示近期作品資料（不再出現偶發「沒有找到符合的作品」空狀態）。
- 載入瞬間顯示 skeleton 灰塊，而非空狀態。
- 切年份、點分類 chip、輸入關鍵字搜尋，行為與改動前一致。

- [ ] **Step 7: Commit**

```bash
cd /Users/sumingkai/Documents/anime && git add frontend/app/pages/catalog.vue && git commit -m "fix: 資料庫頁初始載入改由 useAsyncData 接管資料

原本 useAsyncData handler 回傳 true、以副作用寫入獨立 catalog ref，
ssr: true 下該 ref 不進 payload，client hydration 跳過重跑導致重新
整理時偶發空狀態。改為 handler 直接回傳 raw items 進 payload，以其
data 初始化 catalog、pending 驅動初始 skeleton。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- 「useAsyncData 接管初始資料、回傳 raw items」→ Task 1 Step 2 ✓
- 「catalog 以初始資料為基礎」→ Task 1 Step 2 ✓
- 「模板 skeleton 條件加入 initialPending」→ Task 1 Step 3 ✓
- 「移除初始載入對 loadCatalog 的依賴、loadCatalog 保留給互動」→ Task 1 Step 1–2（移除 `await useAsyncData(... loadCatalog())`，loadCatalog 未動）✓
- 「不改動 useApi / requestId / 分頁 / SEO / 後端 / catalogTags onMounted」→ 計畫僅改 catalog.vue 指定三處 ✓
- 「測試：初始 hydration / 初始 loading / 初始空結果 / 後續搜尋」→ 已在「測試策略說明」說明為何以 build + 手動驗證取代 page-mount 測試（專案無 @nuxt/test-utils，該類行為無法用純函式單元測試真實觸及）✓
- 「風險：回傳可序列化 raw items」→ Step 2 回傳 `result.items`（plain object 陣列），normalize 延到 client ✓
- 「風險：server 端 fetch 失敗落到空狀態（最小改動）」→ 未綁 initialError，維持 `catalog=[]` → 空狀態，符合 spec 決策 ✓

**Placeholder scan:** 無 TBD/TODO；所有 code step 皆有完整程式碼。

**Type consistency:** `initialData` / `initialPending` / `catalog` / `loading` / `requestId` 命名一致；`searchAnime('', {})` 簽章與 useApi 定義相符。
