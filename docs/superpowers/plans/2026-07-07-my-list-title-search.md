# 我的清單標題搜尋 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在 `/list` 我的清單頁加入即時前端標題搜尋，樣式參考資料庫搜尋卡片，範圍限縮使用者自己清單。

**Architecture:** 純前端功能、零後端改動。清單資料已全載入 `fullList`，新增純函式 `applyTitleSearch` 疊在既有 `applyListFilters`（狀態過濾）之後；頁面新增本地 `searchQuery` 狀態、把搜尋框與現有分類 chip 收進一張白底卡片、並區分「搜尋無結果」與「清單為空」兩種空狀態。

**Tech Stack:** Nuxt 4、Vue 3 `<script setup>`、Vitest（純函式單元測試）。

---

## File Structure

- **Modify:** `frontend/app/utils/listFilters.ts` — 新增 `applyTitleSearch` 純函式（與既有 `applyListFilters` 同職責、同檔）。
- **Modify:** `frontend/test/listFilters.test.ts` — 新增 `applyTitleSearch` 測試。
- **Modify:** `frontend/app/pages/list/index.vue` — 新增 `searchQuery` 狀態、`filteredList` 疊加標題過濾、搜尋卡片版面、空結果分支。

## 測試策略

`applyTitleSearch` 為純函式，用 Vitest 完整覆蓋（TDD）。頁面元件層維持專案慣例（無 `@nuxt/test-utils`、不 mount 測試），以 `npm run build` + dev server 手動驗證版面與空結果提示。

---

### Task 1: applyTitleSearch 純函式（TDD）

**Files:**
- Modify: `frontend/app/utils/listFilters.ts`
- Test: `frontend/test/listFilters.test.ts`

- [ ] **Step 1: 擴充測試 helper 以支援 name / titleJa**

在 `frontend/test/listFilters.test.ts`，把 `makeListItem` 改為可帶 `name` 與 `titleJa`（沿用 `normalizeListItem`，讓 `anime.titleJa` 能被設定——normalizeAnime 從 `titles` 陣列的 ja locale 取 titleJa）。將現有：

```ts
function makeListItem(opts: { watched?: boolean; collections?: { id: number; name: string }[] }): ListItem {
  return normalizeListItem({
    id: Math.random(),
    watched: opts.watched ?? false,
    collections: opts.collections ?? [],
    anime: { id: 1, name: '測試作品', tags: [] },
  })
}
```

替換為：

```ts
function makeListItem(opts: {
  watched?: boolean
  collections?: { id: number; name: string }[]
  name?: string
  titleJa?: string
}): ListItem {
  return normalizeListItem({
    id: Math.random(),
    watched: opts.watched ?? false,
    collections: opts.collections ?? [],
    anime: {
      id: 1,
      name: opts.name ?? '測試作品',
      tags: [],
      titles: opts.titleJa ? [{ locale: 'ja', title: opts.titleJa }] : [],
    },
  })
}
```

- [ ] **Step 2: 寫失敗測試**

在 `frontend/test/listFilters.test.ts` 檔案末尾（`applyListFilters` 的 describe 之後）新增，並在頂部 import 補上 `applyTitleSearch`：

先把第 2 行的 import 改成：
```ts
import { applyListFilters, applyTitleSearch } from '../app/utils/listFilters'
```

再新增測試：
```ts
describe('applyTitleSearch', () => {
  it('returns the full list when query is empty', () => {
    const list = [makeListItem({ name: '芙莉蓮' }), makeListItem({ name: 'Bocchi' })]
    expect(applyTitleSearch(list, '')).toHaveLength(2)
  })

  it('returns the full list when query is only whitespace', () => {
    const list = [makeListItem({ name: '芙莉蓮' }), makeListItem({ name: 'Bocchi' })]
    expect(applyTitleSearch(list, '   ')).toHaveLength(2)
  })

  it('matches on the Chinese name (substring)', () => {
    const list = [makeListItem({ name: '葬送的芙莉蓮' }), makeListItem({ name: '排球少年' })]
    const result = applyTitleSearch(list, '芙莉蓮')
    expect(result).toHaveLength(1)
    expect(result[0].anime.name).toBe('葬送的芙莉蓮')
  })

  it('matches on the Japanese title (titleJa, substring)', () => {
    const list = [
      makeListItem({ name: '孤獨搖滾', titleJa: 'ぼっち・ざ・ろっく！' }),
      makeListItem({ name: '排球少年', titleJa: 'ハイキュー' }),
    ]
    const result = applyTitleSearch(list, 'ぼっち')
    expect(result).toHaveLength(1)
    expect(result[0].anime.name).toBe('孤獨搖滾')
  })

  it('is case-insensitive', () => {
    const list = [makeListItem({ name: 'Bocchi the Rock' }), makeListItem({ name: '排球少年' })]
    const result = applyTitleSearch(list, 'BOCCHI')
    expect(result).toHaveLength(1)
    expect(result[0].anime.name).toBe('Bocchi the Rock')
  })

  it('trims surrounding whitespace from the query', () => {
    const list = [makeListItem({ name: '芙莉蓮' }), makeListItem({ name: '排球少年' })]
    const result = applyTitleSearch(list, '  芙莉蓮  ')
    expect(result).toHaveLength(1)
    expect(result[0].anime.name).toBe('芙莉蓮')
  })

  it('composes after applyListFilters (status then title)', () => {
    const list = [
      makeListItem({ name: '芙莉蓮', watched: true }),
      makeListItem({ name: '芙莉蓮外傳', watched: false }),
      makeListItem({ name: '排球少年', watched: true }),
    ]
    const result = applyTitleSearch(applyListFilters(list, 'watched'), '芙莉蓮')
    expect(result).toHaveLength(1)
    expect(result[0].anime.name).toBe('芙莉蓮')
  })
})
```

- [ ] **Step 3: 執行測試確認失敗**

Run: `cd <repo>/frontend && npm run test -- listFilters`
Expected: FAIL —「applyTitleSearch is not a function」或 import 找不到（因為函式尚未實作）。

- [ ] **Step 4: 實作 applyTitleSearch**

在 `frontend/app/utils/listFilters.ts`，`applyListFilters` 函式之後新增：

```ts
// 標題搜尋：在已載入的清單上做即時前端過濾，與 applyListFilters 疊加使用。
// 比對主顯示標題（name）與日文原名（titleJa），不分大小寫、query 先 trim；
// 空字串不過濾。
export function applyTitleSearch(list: ListItem[], query: string): ListItem[] {
  const q = query.trim().toLowerCase()
  if (q === '') return list
  return list.filter(item =>
    item.anime.name.toLowerCase().includes(q) ||
    item.anime.titleJa.toLowerCase().includes(q)
  )
}
```

- [ ] **Step 5: 執行測試確認通過**

Run: `cd <repo>/frontend && npm run test -- listFilters`
Expected: PASS，`applyTitleSearch` 全部案例通過、`applyListFilters` 既有案例仍通過。

- [ ] **Step 6: Commit**

```bash
cd <repo> && git add frontend/app/utils/listFilters.ts frontend/test/listFilters.test.ts && git commit -m "feat: 新增 applyTitleSearch 清單標題過濾純函式

比對 anime.name 與 titleJa、不分大小寫、trim、空字串不過濾，
與 applyListFilters 疊加。含 Vitest 覆蓋命中/大小寫/trim/疊加。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: list 頁接上搜尋卡片與空結果分支

**Files:**
- Modify: `frontend/app/pages/list/index.vue`

- [ ] **Step 1: import applyTitleSearch 並新增 searchQuery 狀態**

在 `frontend/app/pages/list/index.vue`，把第 4 行：
```ts
import { applyListFilters } from '../../utils/listFilters'
```
改為：
```ts
import { applyListFilters, applyTitleSearch } from '../../utils/listFilters'
```

找到 `const filteredList = computed(() => applyListFilters(list.value, activeFilter.value))`（約第 64 行），在它上方新增 searchQuery、並改寫 filteredList：
```ts
// 標題搜尋：只存本地狀態（不進 URL），重整理即清空。
const searchQuery = ref('')

const filteredList = computed(() =>
  applyTitleSearch(applyListFilters(list.value, activeFilter.value), searchQuery.value)
)
```
（移除原本單行的 `const filteredList = computed(...)`。）

- [ ] **Step 2: 在 header 後、chip 列前插入搜尋卡片，並把 chip 列搬進卡片**

在 template 中找到現有的 chip 列區塊（約第 326–349 行）：
```html
      <div v-if="tagOptions.length > 0" class="flex flex-wrap items-center gap-1.5">
        <button
          v-for="opt in tagOptions"
          ...
        </button>
        <button
          v-if="selectedTags.length > 0"
          type="button"
          class="text-xs font-medium text-gray-400 hover:text-gray-700"
          @click="clearTags"
        >
          清除分類
        </button>
      </div>
```

用一張白底卡片包住「搜尋框 + 分類 chip」。把上面整段替換為：
```html
      <!-- 搜尋 + 分類卡片：搜尋框即時過濾（無按鈕），分類 chip 以分隔線區隔，
           與資料庫頁的搜尋卡片一致 -->
      <div class="space-y-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
        <div class="relative">
          <label for="list-search" class="sr-only">搜尋清單內作品</label>
          <UIcon
            name="i-lucide-search"
            class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400 pointer-events-none"
          />
          <input
            id="list-search"
            v-model="searchQuery"
            type="search"
            placeholder="搜尋清單內作品…"
            class="w-full rounded-lg border border-gray-200 bg-white py-2.5 pl-9 pr-4 text-sm text-gray-900 placeholder:text-gray-400 shadow-sm outline-none transition focus:border-primary-400 focus:ring-2 focus:ring-primary-100"
          />
        </div>

        <div v-if="tagOptions.length > 0" class="flex flex-wrap items-center gap-1.5 border-t border-gray-100 pt-3">
          <button
            v-for="opt in tagOptions"
            :key="opt.tag"
            type="button"
            class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold transition"
            :style="selectedTags.includes(opt.tag)
              ? { backgroundColor: tagColor(opt.tag).text, color: '#fff' }
              : { backgroundColor: tagColor(opt.tag).bg, color: tagColor(opt.tag).text }"
            :aria-pressed="selectedTags.includes(opt.tag)"
            @click="toggleTag(opt.tag)"
          >
            {{ opt.tag }}
            <span class="opacity-70">{{ opt.count }}</span>
          </button>
          <button
            v-if="selectedTags.length > 0"
            type="button"
            class="text-xs font-medium text-gray-400 hover:text-gray-700"
            @click="clearTags"
          >
            清除分類
          </button>
        </div>
      </div>
```

- [ ] **Step 3: 拆分空狀態為「搜尋無結果」與「清單為空」**

找到現有的空狀態分支（約第 355–359 行）：
```html
      <div v-else-if="filteredList.length === 0" class="rounded-xl border border-dashed border-gray-200 p-8 text-center text-gray-500">
        <UIcon name="i-lucide-inbox" class="mx-auto mb-2 size-8 text-gray-300" />
        <p class="text-sm font-medium">這裡還沒有作品</p>
        <NuxtLink to="/seasonal" class="mt-3 inline-block text-xs font-semibold text-primary-600 hover:underline">去新番表加入作品</NuxtLink>
      </div>
```

替換為兩個分支（先判斷有搜尋輸入）：
```html
      <div v-else-if="filteredList.length === 0 && searchQuery.trim() !== ''" class="rounded-xl border border-dashed border-gray-200 p-8 text-center text-gray-500">
        <UIcon name="i-lucide-search-x" class="mx-auto mb-2 size-8 text-gray-300" />
        <p class="text-sm font-medium">找不到符合「{{ searchQuery.trim() }}」的作品</p>
        <button type="button" class="mt-3 inline-block text-xs font-semibold text-primary-600 hover:underline" @click="searchQuery = ''">清除搜尋</button>
      </div>

      <div v-else-if="filteredList.length === 0" class="rounded-xl border border-dashed border-gray-200 p-8 text-center text-gray-500">
        <UIcon name="i-lucide-inbox" class="mx-auto mb-2 size-8 text-gray-300" />
        <p class="text-sm font-medium">這裡還沒有作品</p>
        <NuxtLink to="/seasonal" class="mt-3 inline-block text-xs font-semibold text-primary-600 hover:underline">去新番表加入作品</NuxtLink>
      </div>
```

- [ ] **Step 4: 建置與既有測試**

Run:
```bash
cd <repo>/frontend && npm run build && npm run test
```
Expected: build 成功、無型別錯誤；全部測試通過。

- [ ] **Step 5: dev server 手動驗證**

在瀏覽器開 `http://localhost:3000/list`（需登入），確認：
- header 下方出現白底卡片，內含搜尋框（上）＋分類 chip（分隔線下）。
- 輸入標題關鍵字即時過濾清單、「共 X 部」同步變化；清空即恢復。
- 搜尋一個不存在的字 → 顯示「找不到符合「XX」的作品」＋「清除搜尋」，點擊清除恢復。
- 分類 chip toggle / 清除分類、左側狀態切換（全部/已看/未看）行為與改動前一致，且與搜尋疊加正確。

- [ ] **Step 6: Commit**

```bash
cd <repo> && git add frontend/app/pages/list/index.vue && git commit -m "feat: 我的清單頁加入標題搜尋卡片

header 下方新增白底卡片：即時搜尋框（無按鈕）＋分類 chip 收進同卡片，
樣式參考資料庫頁。filteredList 疊加 applyTitleSearch；搜尋無結果顯示
專屬「找不到」提示與清除搜尋鈕，與清單為空狀態區分。搜尋詞只存本地。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- 即時前端過濾、無搜尋按鈕 → Task 2 Step 2（input 無按鈕、v-model 即時）✓
- 搜尋＋分類 chip 同卡片 → Task 2 Step 2 ✓
- 只存本地狀態（不進 URL）→ Task 2 Step 1（`searchQuery = ref('')`，未碰 route）✓
- 過濾順序 list→applyListFilters→applyTitleSearch → Task 2 Step 1 ✓
- applyTitleSearch 規則（name+titleJa、不分大小寫、trim、空字串不過濾）→ Task 1 Step 4 + 測試 ✓
- 專屬空結果提示 vs 清單為空 → Task 2 Step 3 ✓
- 不改動 sidebar / 後端 / loadAll / collection 操作 / applyListFilters → 計畫僅動指定三檔的指定處 ✓
- 測試六案例 → Task 1 Step 2（空字串、空白、name、titleJa、大小寫、trim、疊加，實為 7 案例，涵蓋 spec 的 6 項）✓

**Placeholder scan:** 無 TBD/TODO；所有 code step 有完整程式碼。

**Type consistency:** `applyTitleSearch(list: ListItem[], query: string): ListItem[]` 在 util、測試、頁面三處簽章一致；`searchQuery` 命名一致；`item.anime.name` / `item.anime.titleJa` 對應 normalize.ts 的 `Anime` 欄位（name:38、titleJa:78）。
