# 我的清單排序 ＋ 統一搜尋卡片模板 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 我的清單加入播出日期/加入日期/年份排序（純前端），搜尋卡片對齊資料庫頁模板；後端 formatItem 補 air_date/season_year 兩欄。

**Architecture:** 排序純前端（對已載入的 761 筆 sort，毫秒級）。後端只在 `AnimeListController::formatItem` 補兩欄讓前端拿得到日期。前端新增 `applyListSort` 純函式接在過濾鏈尾端，頁面卡片改成 `[排序下拉][搜尋框][綠色搜尋鈕]` + chips。

**Tech Stack:** Laravel（PHPUnit）、Nuxt 4、Vue 3、Vitest。

---

## File Structure

- **Modify:** `backend/app/Http/Controllers/Api/AnimeListController.php` — `formatItem` anime 陣列補 `season_year`、`air_date`。
- **Modify:** `backend/tests/Feature/ApiTest.php` — 斷言 list item 回傳含兩新欄。
- **Modify:** `frontend/app/utils/listFilters.ts` — 新增 `ListSortKey` 型別 + `applyListSort` 純函式。
- **Modify:** `frontend/test/listFilters.test.ts` — `applyListSort` 測試 + helper 擴充。
- **Modify:** `frontend/app/pages/list/index.vue` — sortKey 狀態、filteredList 套排序、卡片改 catalog 模板（排序下拉 + 綠色搜尋鈕）。

---

### Task 1: 後端 formatItem 補 air_date / season_year

**Files:**
- Modify: `backend/app/Http/Controllers/Api/AnimeListController.php`
- Test: `backend/tests/Feature/ApiTest.php`

- [ ] **Step 1: 加斷言（失敗測試）**

在 `backend/tests/Feature/ApiTest.php`，找到既有取清單的斷言區塊（約 line 61–64）：
```php
            ->getJson('/my/anime-list')
            ...
            ->assertJsonPath('items.0.anime.name', '尖帽子的魔法工房')
            ->assertJsonPath('items.0.anime.tags', ['奇幻', '日常']);
```
在最後一行的 `->assertJsonPath('items.0.anime.tags', ['奇幻', '日常']);` 之前插入兩行斷言（把該行結尾的 `;` 改成串接）：
```php
            ->assertJsonPath('items.0.anime.name', '尖帽子的魔法工房')
            ->assertJsonPath('items.0.anime.tags', ['奇幻', '日常'])
            ->assertJsonPath('items.0.anime.season_year', 2026)
            ->assertJsonPath('items.0.anime.air_date', '2026-04-04');
```
（測試上方 factory 已設 `season_year => 2026`、`air_date => '2026-04-04'`，見同檔約 line 77/80。）

- [ ] **Step 2: 執行確認失敗**

Run: `docker compose exec backend php artisan test --filter=ApiTest`
Expected: FAIL —— `season_year`/`air_date` 路徑不存在（formatItem 尚未回傳）。

- [ ] **Step 3: formatItem 補兩欄**

在 `backend/app/Http/Controllers/Api/AnimeListController.php` 的 `formatItem`，把 anime 陣列：
```php
            'anime' => [
                'id' => $item->anime->id,
                'name' => $item->anime->name,
                'description' => $item->anime->description,
                'imageUrl' => $item->anime->image_url,
                'tags' => $item->anime->tags ?? [],
            ],
```
改為：
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
（`air_date` 在 Anime model 未做 date cast，回傳即為原始 `YYYY-MM-DD` 字串，適合前端字典序排序。）

- [ ] **Step 4: 執行確認通過**

Run: `docker compose exec backend php artisan test --filter=ApiTest`
Expected: PASS。

- [ ] **Step 5: Commit**

```bash
cd <repo> && git add backend/app/Http/Controllers/Api/AnimeListController.php backend/tests/Feature/ApiTest.php && git commit -m "feat: 清單 API 回傳 anime 的 season_year 與 air_date

formatItem 補這兩欄，讓前端能依播出日期/年份排序清單。air_date
未做 date cast、回傳原始 YYYY-MM-DD 字串。含 ApiTest 斷言。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: applyListSort 純函式（TDD）

**Files:**
- Modify: `frontend/app/utils/listFilters.ts`
- Test: `frontend/test/listFilters.test.ts`

- [ ] **Step 1: 擴充測試 helper 支援 createdAt / airDate / seasonYear**

在 `frontend/test/listFilters.test.ts`，把 `makeListItem` 改為：
```ts
function makeListItem(opts: {
  watched?: boolean
  collections?: { id: number; name: string }[]
  name?: string
  titleJa?: string
  createdAt?: string
  airDate?: string | null
  seasonYear?: number | null
}): ListItem {
  return normalizeListItem({
    id: Math.random(),
    watched: opts.watched ?? false,
    collections: opts.collections ?? [],
    createdAt: opts.createdAt ?? '2026-01-01 00:00:00',
    anime: {
      id: 1,
      name: opts.name ?? '測試作品',
      tags: [],
      titles: opts.titleJa ? [{ locale: 'ja', title: opts.titleJa }] : [],
      air_date: opts.airDate ?? null,
      season_year: opts.seasonYear ?? null,
    },
  })
}
```

- [ ] **Step 2: 寫失敗測試**

在 `frontend/test/listFilters.test.ts` 頂部 import 補上 `applyListSort` 與型別：
```ts
import { applyListFilters, applyTitleSearch, applyListSort } from '../app/utils/listFilters'
import type { ListSortKey } from '../app/utils/listFilters'
```
（`ListSortKey` 若造成未使用警告可先不 import 型別，只 import `applyListSort`；但保留以備 typed 呼叫。）

在檔案末尾新增：
```ts
describe('applyListSort', () => {
  it('sorts by added date (createdAt) newest first', () => {
    const list = [
      makeListItem({ name: 'A', createdAt: '2026-01-01 00:00:00' }),
      makeListItem({ name: 'B', createdAt: '2026-03-01 00:00:00' }),
      makeListItem({ name: 'C', createdAt: '2026-02-01 00:00:00' }),
    ]
    const result = applyListSort(list, 'added')
    expect(result.map(i => i.anime.name)).toEqual(['B', 'C', 'A'])
  })

  it('sorts by airDate newest first, nulls last', () => {
    const list = [
      makeListItem({ name: 'A', airDate: '2024-04-01' }),
      makeListItem({ name: 'B', airDate: null }),
      makeListItem({ name: 'C', airDate: '2026-07-01' }),
    ]
    const result = applyListSort(list, 'airDate')
    expect(result.map(i => i.anime.name)).toEqual(['C', 'A', 'B'])
  })

  it('sorts by seasonYear newest first, nulls last', () => {
    const list = [
      makeListItem({ name: 'A', seasonYear: 2020 }),
      makeListItem({ name: 'B', seasonYear: null }),
      makeListItem({ name: 'C', seasonYear: 2026 }),
    ]
    const result = applyListSort(list, 'year')
    expect(result.map(i => i.anime.name)).toEqual(['C', 'A', 'B'])
  })

  it('does not mutate the input array', () => {
    const list = [
      makeListItem({ name: 'A', createdAt: '2026-01-01 00:00:00' }),
      makeListItem({ name: 'B', createdAt: '2026-03-01 00:00:00' }),
    ]
    const before = list.map(i => i.anime.name)
    applyListSort(list, 'added')
    expect(list.map(i => i.anime.name)).toEqual(before)
  })

  it('composes after filters (status → title → sort)', () => {
    const list = [
      makeListItem({ name: '芙莉蓮 A', watched: true, createdAt: '2026-01-01 00:00:00' }),
      makeListItem({ name: '芙莉蓮 B', watched: true, createdAt: '2026-05-01 00:00:00' }),
      makeListItem({ name: '排球少年', watched: true, createdAt: '2026-09-01 00:00:00' }),
      makeListItem({ name: '芙莉蓮 C', watched: false, createdAt: '2026-12-01 00:00:00' }),
    ]
    const result = applyListSort(
      applyTitleSearch(applyListFilters(list, 'watched'), '芙莉蓮'),
      'added'
    )
    expect(result.map(i => i.anime.name)).toEqual(['芙莉蓮 B', '芙莉蓮 A'])
  })
})
```

- [ ] **Step 3: 執行確認失敗**

Run: `cd <repo>/frontend && npm run test -- listFilters`
Expected: FAIL —— `applyListSort is not a function`。

- [ ] **Step 4: 實作 applyListSort**

在 `frontend/app/utils/listFilters.ts`，`applyTitleSearch` 之後新增：
```ts
export type ListSortKey = 'added' | 'airDate' | 'year'

// 清單排序（皆新→舊）。非破壞性（複製後排序）。airDate/year 缺值排到最後，
// 避免 null 污染前段。與 applyListFilters/applyTitleSearch 疊加使用。
export function applyListSort(list: ListItem[], sort: ListSortKey): ListItem[] {
  const copy = [...list]
  if (sort === 'added') {
    return copy.sort((a, b) => b.createdAt.localeCompare(a.createdAt))
  }
  if (sort === 'airDate') {
    return copy.sort((a, b) => nullsLast(a.anime.airDate, b.anime.airDate, (x, y) => y.localeCompare(x)))
  }
  return copy.sort((a, b) => nullsLast(a.anime.seasonYear, b.anime.seasonYear, (x, y) => y - x))
}

// 兩值皆有 → cmp；只有一方為 null → 有值者在前；皆 null → 相等。
function nullsLast<T>(a: T | null, b: T | null, cmp: (x: T, y: T) => number): number {
  if (a === null && b === null) return 0
  if (a === null) return 1
  if (b === null) return -1
  return cmp(a, b)
}
```

- [ ] **Step 5: 執行確認通過**

Run: `cd <repo>/frontend && npm run test -- listFilters`
Expected: PASS，全部 applyListSort 案例與既有測試通過。

- [ ] **Step 6: Commit**

```bash
cd <repo> && git add frontend/app/utils/listFilters.ts frontend/test/listFilters.test.ts && git commit -m "feat: 新增 applyListSort 清單排序純函式

支援加入日期/播出日期/年份（皆新→舊），非破壞性、缺值排最後，
與既有過濾疊加。含 Vitest 覆蓋三種排序/不變性/疊加。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: list 頁套排序 + 卡片改 catalog 模板

**Files:**
- Modify: `frontend/app/pages/list/index.vue`

- [ ] **Step 1: import + sortKey 狀態 + filteredList 套排序**

在 `frontend/app/pages/list/index.vue`，把第 4 行：
```ts
import { applyListFilters, applyTitleSearch } from '../../utils/listFilters'
```
改為：
```ts
import { applyListFilters, applyTitleSearch, applyListSort } from '../../utils/listFilters'
import type { ListSortKey } from '../../utils/listFilters'
```

找到現有：
```ts
const filteredList = computed(() =>
  applyTitleSearch(applyListFilters(list.value, activeFilter.value), searchQuery.value)
)
```
在其上方新增 sortKey，並改寫 filteredList：
```ts
// 排序：只存本地狀態。預設「加入日期」。
const sortKey = ref<ListSortKey>('added')

const filteredList = computed(() =>
  applyListSort(
    applyTitleSearch(applyListFilters(list.value, activeFilter.value), searchQuery.value),
    sortKey.value
  )
)
```

- [ ] **Step 2: 卡片頂排改成 [排序下拉][搜尋框 form][綠色搜尋鈕]**

找到現有卡片頂排（搜尋框那段，約含 `<div class="flex items-center gap-2">` 到「清除全部篩選」按鈕結束）：
```html
        <div class="flex items-center gap-2">
          <div class="relative flex-1">
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
          <button
            v-if="hasActiveFilters"
            type="button"
            class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm font-semibold text-gray-600 shadow-sm transition hover:bg-gray-50"
            @click="clearAllFilters"
          >
            <UIcon name="i-lucide-x" class="size-4" />
            清除全部篩選
          </button>
        </div>
```
替換為（排序下拉在左、搜尋框 form 中間、綠色搜尋鈕、清除全部篩選在最右）：
```html
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
          <!-- 排序下拉（對齊 catalog 左控制項位置） -->
          <div class="shrink-0">
            <label for="list-sort" class="sr-only">排序方式</label>
            <select
              id="list-sort"
              v-model="sortKey"
              class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm font-semibold text-gray-700 shadow-sm outline-none transition focus:border-primary-400 focus:ring-2 focus:ring-primary-100 sm:w-auto"
            >
              <option value="added">加入日期</option>
              <option value="airDate">播出日期</option>
              <option value="year">年份</option>
            </select>
          </div>

          <!-- 搜尋框 + 綠色搜尋鈕（即時過濾；按鈕純裝飾對齊 catalog，submit 不觸發查詢） -->
          <form class="flex flex-1 gap-2" @submit.prevent>
            <div class="relative flex-1">
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
            <button
              type="submit"
              class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
            >
              搜尋
            </button>
          </form>

          <button
            v-if="hasActiveFilters"
            type="button"
            class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm font-semibold text-gray-600 shadow-sm transition hover:bg-gray-50"
            @click="clearAllFilters"
          >
            <UIcon name="i-lucide-x" class="size-4" />
            清除全部篩選
          </button>
        </div>
```

- [ ] **Step 3: 建置與測試**

Run:
```bash
cd <repo>/frontend && npm run build && npm run test
```
Expected: build 成功、無型別錯誤（`sortKey` 為 `ListSortKey`、`<select v-model>` 綁定正確）；全部測試通過。

- [ ] **Step 4: dev server 手動驗證**

`http://localhost:3000/list`（需登入）確認：
- 卡片頂排為 `[排序下拉] [搜尋框] [搜尋]`，與資料庫頁外觀一致；分隔線下分類 chips。
- 切排序：加入日期 / 播出日期 / 年份，清單順序即時改變（缺日期者排最後）。
- 搜尋即時過濾照舊；綠色搜尋鈕按下不改變結果（即時已生效）。
- 「清除全部篩選」於有搜尋詞或已選分類時出現、可清空。

- [ ] **Step 5: Commit**

```bash
cd <repo> && git add frontend/app/pages/list/index.vue && git commit -m "feat: 我的清單套用排序與統一資料庫搜尋卡片模板

卡片頂排改為 [排序下拉][搜尋框][綠色搜尋鈕]，與資料庫頁一致；排序
支援加入日期/播出日期/年份（新→舊），filteredList 尾端套 applyListSort。
綠色搜尋鈕為裝飾對齊（即時過濾，submit 不觸發查詢）。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- 後端 formatItem 補 air_date/season_year → Task 1 ✓
- 後端測試斷言 → Task 1 Step 1 ✓
- applyListSort（三種排序、null 最後、非破壞、疊加）→ Task 2 ✓
- 預設加入日期 → Task 3 Step 1（`ref<ListSortKey>('added')`）✓
- 排序下拉在搜尋框左側 → Task 3 Step 2 ✓
- 綠色搜尋鈕裝飾對齊、submit 不觸發查詢 → Task 3 Step 2（`@submit.prevent` 無 handler）✓
- 保留清除全部篩選 → Task 3 Step 2 ✓
- 分類 chips 不動 → 計畫未觸及 chip 區塊 ✓
- 過濾/排序總順序 filters→title→sort → Task 3 Step 1 ✓

**Placeholder scan:** 無 TBD/TODO；所有 code step 有完整程式碼。

**Type consistency:** `ListSortKey = 'added'|'airDate'|'year'` 在 util/測試/頁面三處一致；`applyListSort(list, sort)` 簽章一致；下拉 option value（added/airDate/year）與型別字面值一致；`anime.airDate`/`anime.seasonYear` 對應 normalize.ts 欄位。
