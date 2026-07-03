# 我的清單分類篩選 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 讓「我的清單」（`/list`）頁面可以依分類（`anime.tags`，如戀愛/戰鬥/搞笑）篩選使用者自己清單中的作品，分類資料與篩選邏輯需要能被單元測試覆蓋。

**Architecture:** `anime.tags` 資料已存在資料庫且已由爬蟲/匯入流程寫入，唯一缺的是清單 API 回應沒有把 `tags` 帶出來。後端只需在 `AnimeListController::formatItem()` 補一個欄位。前端把「篩選判斷邏輯」與「分類選項萃取邏輯」抽成純函式放進 `frontend/app/utils/listFilters.ts`（新檔案），使其可以獨立單元測試，再由 `/list` 頁面呼叫；`ListItemRow.vue` 加上分類 chip 顯示。

**Tech Stack:** Laravel (PHPUnit Feature test), Nuxt 4 + Vue 3 + TypeScript (Vitest)

---

## Task 1: 後端 — 清單 API 回應補上 `tags` 欄位

**Files:**
- Modify: `backend/app/Http/Controllers/Api/AnimeListController.php:161-166`
- Test: `backend/tests/Feature/ApiTest.php`

- [ ] **Step 1: 在既有測試中新增分類斷言（先寫失敗的測試）**

打開 `backend/tests/Feature/ApiTest.php`，修改 `test_authenticated_user_can_manage_anime_list`：在建立 `$anime` 時加入 `tags`，並在最後一段 `getJson('/my/anime-list')` 後新增斷言。完整修改後的方法如下：

```php
    public function test_authenticated_user_can_manage_anime_list(): void
    {
        $login = $this->postJson('/auth/google', ['idToken' => 'dev:dev@example.com']);
        $token = $login->json('token');
        $anime = Anime::query()->create([
            'name' => '尖帽子的魔法工房',
            'description' => '魔法工房簡介',
            'image_url' => 'https://example.com/anime.jpg',
            'source' => 'test',
            'tags' => ['奇幻', '日常'],
        ]);

        $create = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/my/anime-list', ['animeId' => $anime->id]);

        $create->assertCreated()
            ->assertJsonPath('item.anime.name', '尖帽子的魔法工房')
            ->assertJsonPath('item.watched', false)
            ->assertJsonPath('item.anime.tags', ['奇幻', '日常']);

        $itemId = $create->json('item.id');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/my/anime-list/{$itemId}", ['watched' => true, 'rating' => 9, 'note' => '值得追'])
            ->assertOk()
            ->assertJsonPath('item.watched', true)
            ->assertJsonPath('item.rating', 9);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list')
            ->assertOk()
            ->assertJsonPath('items.0.anime.name', '尖帽子的魔法工房')
            ->assertJsonPath('items.0.anime.tags', ['奇幻', '日常']);
    }
```

- [ ] **Step 2: 執行測試，確認因缺少 `tags` 欄位而失敗**

Run: `docker compose exec backend php artisan test --filter=test_authenticated_user_can_manage_anime_list`
Expected: FAIL — `assertJsonPath('item.anime.tags', ...)` 找不到 `tags` 鍵或值不符

- [ ] **Step 3: 在 `formatItem()` 補上 `tags` 欄位**

打開 `backend/app/Http/Controllers/Api/AnimeListController.php`，找到 `formatItem()` 方法中的 `anime` 陣列（第 161-166 行）：

```php
            'anime' => [
                'id' => $item->anime->id,
                'name' => $item->anime->name,
                'description' => $item->anime->description,
                'imageUrl' => $item->anime->image_url,
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
            ],
```

- [ ] **Step 4: 執行測試，確認通過**

Run: `docker compose exec backend php artisan test --filter=test_authenticated_user_can_manage_anime_list`
Expected: PASS

- [ ] **Step 5: 執行完整後端測試套件，確認沒有破壞其他測試**

Run: `docker compose exec backend php artisan test`
Expected: 全數 PASS

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Api/AnimeListController.php backend/tests/Feature/ApiTest.php
git commit -m "$(cat <<'EOF'
feat: 我的清單 API 回應補上動漫分類 tags

分類資料已存在 anime.tags，清單 API 先前組裝回應時漏了這個欄位，導致前端拿不到分類做篩選。
EOF
)"
```

---

## Task 2: 前端 — 分類篩選與選項萃取的純函式（含單元測試）

**Files:**
- Create: `frontend/app/utils/listFilters.ts`
- Test: `frontend/test/listFilters.test.ts`

**背景：** `ListItem.anime` 型別（`frontend/app/utils/normalize.ts:69-82`）已經含 `tags: string[]`，且 `normalizeListItem`（`normalize.ts:172-185`）會透過 `normalizeAnime` 自動把 tags 帶進來，這一步不需要改型別或 normalize 邏輯，Task 1 完成後即自動生效。

- [ ] **Step 1: 寫失敗的測試 — 分類選項萃取**

建立 `frontend/test/listFilters.test.ts`：

```typescript
import { describe, expect, it } from 'vitest'
import { extractTagOptions, matchesSelectedTags } from '../app/utils/listFilters'
import { normalizeListItem } from '../app/utils/normalize'
import type { ListItem } from '../app/utils/normalize'

function makeItem(tags: string[], overrides: Record<string, any> = {}): ListItem {
  return normalizeListItem({
    id: Math.random(),
    anime: { id: 1, name: '測試作品', tags, ...overrides },
  })
}

describe('extractTagOptions', () => {
  it('returns empty array when list is empty', () => {
    expect(extractTagOptions([])).toEqual([])
  })

  it('dedupes tags and counts occurrences', () => {
    const list = [
      makeItem(['戀愛', '戰鬥']),
      makeItem(['戀愛', '搞笑']),
      makeItem(['戰鬥']),
    ]
    const options = extractTagOptions(list)
    expect(options).toEqual(
      expect.arrayContaining([
        { tag: '戀愛', count: 2 },
        { tag: '戰鬥', count: 2 },
        { tag: '搞笑', count: 1 },
      ])
    )
    expect(options).toHaveLength(3)
  })

  it('ignores items with no tags', () => {
    const list = [makeItem([]), makeItem(['戀愛'])]
    expect(extractTagOptions(list)).toEqual([{ tag: '戀愛', count: 1 }])
  })

  it('sorts options by count descending', () => {
    const list = [makeItem(['戰鬥']), makeItem(['戀愛']), makeItem(['戀愛'])]
    const options = extractTagOptions(list)
    expect(options[0]).toEqual({ tag: '戀愛', count: 2 })
    expect(options[1]).toEqual({ tag: '戰鬥', count: 1 })
  })
})

describe('matchesSelectedTags', () => {
  it('returns true when no tags are selected (no-op filter)', () => {
    const item = makeItem(['戀愛'])
    expect(matchesSelectedTags(item, [])).toBe(true)
  })

  it('returns true when item has at least one selected tag (OR logic)', () => {
    const item = makeItem(['戀愛', '日常'])
    expect(matchesSelectedTags(item, ['戰鬥', '戀愛'])).toBe(true)
  })

  it('returns false when item has none of the selected tags', () => {
    const item = makeItem(['日常'])
    expect(matchesSelectedTags(item, ['戰鬥', '戀愛'])).toBe(false)
  })
})
```

- [ ] **Step 2: 執行測試，確認因檔案不存在而失敗**

Run: `cd frontend && npm run test -- listFilters`
Expected: FAIL — 找不到模組 `../app/utils/listFilters`

- [ ] **Step 3: 建立 `frontend/app/utils/listFilters.ts`**

```typescript
import type { ListItem } from './normalize'

export interface TagOption {
  tag: string
  count: number
}

export function extractTagOptions(list: ListItem[]): TagOption[] {
  const counts: Record<string, number> = {}
  for (const item of list) {
    for (const tag of item.anime.tags) {
      counts[tag] = (counts[tag] ?? 0) + 1
    }
  }
  return Object.entries(counts)
    .map(([tag, count]) => ({ tag, count }))
    .sort((a, b) => b.count - a.count)
}

export function matchesSelectedTags(item: ListItem, selectedTags: string[]): boolean {
  if (selectedTags.length === 0) return true
  return selectedTags.some(tag => item.anime.tags.includes(tag))
}
```

- [ ] **Step 4: 執行測試，確認通過**

Run: `cd frontend && npm run test -- listFilters`
Expected: PASS（全部 8 個測試案例）

- [ ] **Step 5: Commit**

```bash
git add frontend/app/utils/listFilters.ts frontend/test/listFilters.test.ts
git commit -m "$(cat <<'EOF'
feat: 新增我的清單分類篩選的純函式與單元測試

抽出分類選項萃取（去重計數）與分類比對（OR 邏輯）成獨立可測試函式，供 /list 頁面串接篩選 UI 使用。
EOF
)"
```

---

## Task 3: 前端 — `/list` 頁面串接分類篩選 UI

**Files:**
- Modify: `frontend/app/pages/list/index.vue`

**背景：** 現有 `activeFilter`（`list/index.vue:19`）是單一字串 query 參數 `filter`（all/watched/unwatched/col:{id}）。本任務新增獨立的 `tags` query 參數，與 `filter` 並存、AND 疊加，不改動 `filter` 既有語意。

- [ ] **Step 1: import 新函式並新增分類狀態**

打開 `frontend/app/pages/list/index.vue`，在 `<script setup>` 頂部的 import 區塊（第 1-2 行）新增：

```typescript
import { normalizeListItem, normalizeCollection } from '../../utils/normalize'
import type { ListItem, Collection } from '../../utils/normalize'
import { extractTagOptions, matchesSelectedTags } from '../../utils/listFilters'
import { tagColor } from '../../utils/normalize'
```

在 `filterTabs` 定義（第 21-25 行）之後、`function setFilter` 之前，新增分類狀態與操作函式：

```typescript
// Selected tag filters — separate query param from `filter`, AND-combined with it.
const selectedTags = computed<string[]>(() => {
  const raw = route.query.tags
  if (!raw || typeof raw !== 'string') return []
  return raw.split(',').filter(Boolean)
})

function toggleTag(tag: string) {
  const current = selectedTags.value
  const next = current.includes(tag)
    ? current.filter(t => t !== tag)
    : [...current, tag]

  const query = { ...route.query }
  if (next.length > 0) query.tags = next.join(',')
  else delete query.tags

  router.push({ path: '/list', query })
}

function clearTags() {
  const query = { ...route.query }
  delete query.tags
  router.push({ path: '/list', query })
}

const tagOptions = computed(() => extractTagOptions(list.value))
```

- [ ] **Step 2: 在 `filteredList` computed 疊加分類過濾**

找到現有的 `filteredList` computed（第 31-40 行）：

```typescript
const filteredList = computed(() => {
  const f = activeFilter.value
  if (f === 'watched') return list.value.filter(i => i.watched)
  if (f === 'unwatched') return list.value.filter(i => !i.watched)
  if (f.startsWith('col:')) {
    const colId = Number(f.slice(4))
    return list.value.filter(i => i.collections.some(c => c.id === colId))
  }
  return list.value
})
```

改為在既有結果之後再疊加一層分類過濾：

```typescript
const filteredList = computed(() => {
  const f = activeFilter.value
  let base = list.value
  if (f === 'watched') base = list.value.filter(i => i.watched)
  else if (f === 'unwatched') base = list.value.filter(i => !i.watched)
  else if (f.startsWith('col:')) {
    const colId = Number(f.slice(4))
    base = list.value.filter(i => i.collections.some(c => c.id === colId))
  }
  return base.filter(i => matchesSelectedTags(i, selectedTags.value))
})
```

- [ ] **Step 3: 在主區塊 header 下方新增分類篩選 chip 列**

找到 `<template>` 中主區塊的 `<header>` 區塊（第 250-260 行）：

```html
      <header class="space-y-1">
        <p class="text-xs font-extrabold uppercase tracking-widest text-primary-600">追番清單</p>
        <div class="flex items-center justify-between">
          <h1 class="text-3xl font-extrabold tracking-tight text-gray-950">
            {{ activeFilter.startsWith('col:')
              ? collections.find(c => c.id === Number(activeFilter.slice(4)))?.name ?? '清單'
              : '我的清單' }}
          </h1>
          <span class="text-sm text-gray-500">共 {{ filteredList.length }} 部</span>
        </div>
      </header>
```

在其後（`</header>` 之後、`<div v-if="filteredList.length === 0"` 之前）新增分類篩選列：

```html
      <div v-if="tagOptions.length > 0" class="flex flex-wrap items-center gap-1.5">
        <button
          v-for="opt in tagOptions"
          :key="opt.tag"
          type="button"
          class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold transition"
          :style="selectedTags.includes(opt.tag)
            ? { backgroundColor: tagColor(opt.tag).text, color: '#fff' }
            : { backgroundColor: tagColor(opt.tag).bg, color: tagColor(opt.tag).text }"
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
```

- [ ] **Step 4: 手動驗證行為**

啟動開發環境（若尚未啟動）：`docker compose up -d`，開啟 `http://localhost:3000/list`。

驗證項目：
- 清單中若有帶 tags 的作品，主區塊 header 下方出現分類 chip 列，每個 chip 顯示分類名稱與作品數
- 點擊一個 chip，`filteredList` 只顯示含該分類的作品，URL query 出現 `?tags=戀愛`
- 同時點選「已看」分頁 + 一個分類 chip，兩者為 AND：只顯示「已看且含該分類」的作品
- 再點一次已選的 chip，取消該分類篩選
- 選了分類後出現「清除分類」按鈕，點擊後清除所有已選分類、`tags` query 參數移除
- 清單中所有作品皆無 tags 時，分類 chip 列不顯示（因為 `tagOptions` 為空陣列）

- [ ] **Step 5: Commit**

```bash
git add frontend/app/pages/list/index.vue
git commit -m "$(cat <<'EOF'
feat: 我的清單頁面新增分類篩選功能

新增獨立的 tags query 參數與現有已看/未看/自訂清單篩選並存（AND 疊加），分類選項僅從使用者清單內作品的 tags 統計產生。
EOF
)"
```

---

## Task 4: 前端 — `ListItemRow.vue` 顯示分類 chip

**Files:**
- Modify: `frontend/app/components/ListItemRow.vue`

- [ ] **Step 1: import `tagColor`**

打開 `frontend/app/components/ListItemRow.vue`，修改第 1-2 行的 import：

```typescript
import type { ListItem, Collection } from '../utils/normalize'
import { tagColor } from '../utils/normalize'
```

- [ ] **Step 2: 在 Collection tags 區塊前新增分類 chip 顯示**

找到現有「Collection tags」區塊的開頭（第 93-94 行）：

```html
      <!-- Collection tags -->
      <div v-if="item.collections.length > 0 || collections.length > 0" class="flex flex-wrap items-center gap-1.5">
```

在其**之前**新增分類顯示區塊：

```html
      <!-- Genre tags (display only, filtering happens in the page's filter bar) -->
      <div v-if="item.anime.tags.length > 0" class="flex flex-wrap items-center gap-1.5">
        <span
          v-for="tag in item.anime.tags"
          :key="tag"
          class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
          :style="{ backgroundColor: tagColor(tag).bg, color: tagColor(tag).text }"
        >
          {{ tag }}
        </span>
      </div>

      <!-- Collection tags -->
      <div v-if="item.collections.length > 0 || collections.length > 0" class="flex flex-wrap items-center gap-1.5">
```

- [ ] **Step 3: 手動驗證顯示**

在 `http://localhost:3000/list` 檢查每列作品：帶有 tags 的作品在標題下方、備註欄位上方出現分類 chip（配色與 catalog 頁一致，非互動、不可點擊）；沒有 tags 的作品不顯示這排。

- [ ] **Step 4: Commit**

```bash
git add frontend/app/components/ListItemRow.vue
git commit -m "$(cat <<'EOF'
feat: 清單每列作品顯示分類標籤

方便使用者在清單列表直接看到作品分類，不需要另外點進作品頁查看。
EOF
)"
```

---

## Task 5: 前端 — 完整測試套件與型別檢查

**Files:**
- 無新檔案，僅執行驗證指令

- [ ] **Step 1: 執行完整前端測試套件**

Run: `cd frontend && npm run test`
Expected: 全數 PASS，含 Task 2 新增的 `listFilters.test.ts`

- [ ] **Step 2: 執行前端 build/typecheck 確認無型別錯誤**

Run: `cd frontend && npm run build`
Expected: build 成功，無 TypeScript 錯誤

- [ ] **Step 3: 執行完整後端測試套件（再次確認 Task 1 改動穩定）**

Run: `docker compose exec backend php artisan test`
Expected: 全數 PASS

無需額外 commit（此任務僅為驗證）。

---

## Self-Review Notes

- **Spec 覆蓋確認**：後端 tags 欄位補充（Task 1）、分類篩選 AND/OR 邏輯（Task 2+3）、UI 位置在主區塊上方（Task 3 Step 3）、ListItemRow 分類 chip 顯示（Task 4）、public 分享清單自動帶 tags（Task 1 的 `formatItem` 為 `index`/`publicList` 共用，無需額外任務）—— spec 中列出的項目均已對應到具體任務。
- **型別一致性確認**：`TagOption { tag: string; count: number }`、`extractTagOptions(list: ListItem[])`、`matchesSelectedTags(item: ListItem, selectedTags: string[])` 在 Task 2 定義後，Task 3 的呼叫方式（`extractTagOptions(list.value)`、`matchesSelectedTags(i, selectedTags.value)`)一致，無簽章不符問題。
- **無 placeholder**：所有步驟均含完整程式碼與確切檔案路徑/行號。
