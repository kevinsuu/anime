# 資料庫頁初始載入 hydration 修復設計

日期：2026-07-07

## 問題

`/catalog` 頁面重新整理時，**有時候不會顯示資料**，畫面停在「沒有找到符合的作品」空狀態。

### 根因

`app/pages/catalog.vue` 使用 `useAsyncData('catalog-initial', ...)`，但 handler 回傳的是 `true`，實際查詢結果以**副作用**寫進另一個獨立的 `catalog` ref：

```ts
const catalog = ref<Anime[]>([])
async function loadCatalog() { /* ...寫入 catalog.value... */ }

await useAsyncData('catalog-initial', async () => {
  await loadCatalog()   // 副作用寫入 catalog，回傳 true
  return true
}, { default: () => true })
```

`nuxt.config.ts` 實際為 `ssr: true`（與 CLAUDE.md 記載的 `ssr: false` 不符）。在 SSR 下：

1. Server 執行 handler → 填入 server 端的 `catalog` ref → 產生 HTML。
2. Nuxt payload 只序列化 handler 的**回傳值**（`true`），不會序列化副作用的 `catalog` ref。
3. Client hydration 時 `useAsyncData` 讀到快取 payload `true`，**判定已完成、不重跑 handler** → `catalog` 保持 `[]` → 顯示空狀態，且 `loading` 從未被設為 `true`，skeleton 也不會出現。

因為是否重跑取決於 server 執行結果與 hydration 快取命中狀況，症狀呈現「有時候」不顯示。

現有的 loading skeleton（`v-if="loading"`）程式碼本身正確，只是這條路徑走不到它。

## 修復方向

讓 `useAsyncData` **接管初始資料**：handler 直接回傳查詢結果，讓資料進入 payload、hydration 後仍在。後續使用者操作（切年份、點分類、搜尋）維持既有的 `loadCatalog()` + `loading` ref + `requestId` 守衛（這部分本來就正確）。

### 具體改動（`app/pages/catalog.vue`）

1. **初始載入改為回傳資料的 `useAsyncData`**

   ```ts
   const { data: initialData, pending: initialPending } = await useAsyncData(
     'catalog-initial',
     async () => {
       const result = await api.searchAnime('', {})   // 近期模式：無 year、無 tags
       return (result.items || []) as Record<string, any>[]
     }
   )
   ```

   - handler 回傳 raw items 陣列，Nuxt 會序列化進 payload，hydration 後 `initialData` 仍有值。
   - 初始一律走近期模式（`activeYear = null`、無 tags、無 query），與現行進站預設一致。

2. **`catalog` 以初始資料為基礎**

   維持 `catalog` 為可變 ref（後續互動要覆寫它），但初始值來自 `initialData`：

   ```ts
   const catalog = ref<Anime[]>((initialData.value || []).map(normalizeAnime))
   ```

   後續 `loadCatalog()` 仍直接覆寫 `catalog.value`，行為不變。

3. **初始 loading 狀態**

   模板 skeleton 條件由 `loading` 改為 `loading || initialPending`：

   ```html
   <div v-if="loading || initialPending" ...>skeleton</div>
   <div v-else-if="catalog.length === 0 && !error" ...>空狀態</div>
   ```

   - `initialPending` 涵蓋初始請求進行中；`loading` 涵蓋後續互動。
   - SSR 情境下 server 已把 `initialPending` 解為 `false`、資料已在，client hydration 直接顯示內容，不閃空狀態。

4. **移除初始載入對 `loadCatalog` 的依賴**

   原本 `await useAsyncData(... loadCatalog() ...)` 移除；`loadCatalog` 保留給 `changeYear` / `toggleTag` / `clearTags` / `search` 使用。

### 不改動

- `useApi.searchAnime` / `catalogTags`：介面與行為不變。
- `requestId` 競態守衛、分頁、`useSeoMeta` / `useHead`：不動。
- 後端：不動。
- `catalogTags` 的 `onMounted`：維持（分類 chip 屬客戶端輔助資料，非 SEO 關鍵，容許 client-only）。

## 元件邊界

- **初始資料流**：`useAsyncData` handler（純函式，回傳陣列）→ payload → `catalog` 初值。單一職責、可獨立理解。
- **互動資料流**：使用者事件 → `loadCatalog()`（含 `requestId` 守衛、`loading` 旗標）→ 覆寫 `catalog`。
- **呈現**：模板依 `loading || initialPending` / `error` / `catalog.length` 三態切換 skeleton / 錯誤 / 空狀態 / 格線。

## 測試

前端 Vitest（`cd frontend && npm run test`）：

1. **初始資料 hydration**：mock `api.searchAnime` 回傳 N 筆，掛載頁面後 `catalog` 應為 N 筆、不顯示空狀態。
2. **初始 loading**：`initialPending` 為 true 時顯示 skeleton、不顯示空狀態。
3. **初始空結果**：mock 回傳 0 筆且無 error → 顯示空狀態（而非永久 skeleton）。
4. **後續搜尋仍運作**：觸發 `search()` → 呼叫 `searchAnime` 並覆寫 `catalog`（既有 `loading` 行為）。

若既有測試檔已涵蓋 catalog 頁，沿用其掛載/mock 模式；否則新增最小測試檔驗證上述三態。

## 風險

- `useAsyncData` 回傳值須為可序列化的 plain object 陣列（raw items，非已 normalize 的含方法物件）—— 設計上 handler 回傳 raw items、normalize 延到 client 端，避免序列化問題。
- 若 `api.searchAnime` 在 server 端因網路失敗拋錯，`useAsyncData` 會捕捉到 `error`；需確認模板 error 分支涵蓋此情形（既有 `UAlert v-if="error"` 尚未綁定 asyncData 的 error，補綁 `initialError` 或維持現況以 `catalog=[]` 落到空狀態——採後者，保持最小改動）。
