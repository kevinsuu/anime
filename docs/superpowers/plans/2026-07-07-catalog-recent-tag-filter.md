# 資料庫頁：近期作品 + 後端分類篩選 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把 `/catalog` 資料庫頁升級為「不限年份的近期 50 筆（air_date 新到舊）為預設 + 後端分類篩選 + 搜尋」，年份切換器保留。

**Architecture:** 後端 `AnimeController@index` 新增 `tags`（OR 篩選）與「近期模式」，並新增 `GET /anime/tags` 分類清單端點（比照 `AnimeListController@tags`）。前端 `catalog.vue` 三模式（近期／年份／搜尋）互斥切換，頂部前 20 高頻分類 chip 列，`useApi.searchAnime` 擴充 `tags`。

**Tech Stack:** Laravel（PHP，Docker 內執行）、Nuxt 4 SPA、Vitest。

**Docker 注意：** 後端指令一律用 `docker compose exec backend php artisan ...`，前端測試在 host `cd frontend && npm run test`。

---

## File Structure

- `backend/app/Http/Controllers/Api/AnimeController.php` — 改 `index()`（加 tags + 近期模式），新增 `tags()` 方法。
- `backend/routes/api.php` — 在 `/anime/{id}` 之前註冊 `/anime/tags`。
- `backend/tests/Feature/AnimeCatalogRecentTest.php` — 新測試檔（近期模式、tags 篩選、tags 端點）。
- `frontend/app/composables/useApi.ts` — `searchAnime` 加 `tags`，新增 `catalogTags()`。
- `frontend/app/pages/catalog.vue` — 三模式狀態機 + 分類 chip 列 UI。

---

## Task 1: 後端 — `/anime/tags` 分類清單端點

**Files:**
- Modify: `backend/app/Http/Controllers/Api/AnimeController.php`（新增 `tags()` 方法）
- Modify: `backend/routes/api.php:13-14`
- Test: `backend/tests/Feature/AnimeCatalogRecentTest.php`

- [ ] **Step 1: 寫失敗測試**

建立 `backend/tests/Feature/AnimeCatalogRecentTest.php`：

```php
<?php

namespace Tests\Feature;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnimeCatalogRecentTest extends TestCase
{
    use RefreshDatabase;

    private function makeAnime(string $name, array $attrs = []): Anime
    {
        return Anime::query()->create(array_merge([
            'name' => $name,
            'description' => '簡介',
            'image_url' => 'https://example.com/a.jpg',
            'source' => 'test',
            'tags' => [],
        ], $attrs));
    }

    public function test_tags_endpoint_returns_genre_tags_with_counts_excluding_source_tags(): void
    {
        $this->makeAnime('A', ['tags' => ['戀愛', '漫畫改編']]);
        $this->makeAnime('B', ['tags' => ['戀愛', '戰鬥']]);
        $this->makeAnime('C', ['tags' => ['原創作品']]); // source tag only

        $response = $this->getJson('/anime/tags')->assertOk();

        $tags = collect($response->json('tags'));
        // 排除 source tag（漫畫改編/原創作品）
        $this->assertFalse($tags->contains('tag', '漫畫改編'));
        $this->assertFalse($tags->contains('tag', '原創作品'));
        // 戀愛出現 2 次，排最前
        $this->assertSame('戀愛', $tags->first()['tag']);
        $this->assertSame(2, $tags->first()['count']);
    }
}
```

- [ ] **Step 2: 跑測試確認失敗**

Run: `docker compose exec backend php artisan test --filter=test_tags_endpoint_returns_genre_tags_with_counts_excluding_source_tags`
Expected: FAIL（route `/anime/tags` 尚未存在，或被 `{id}` 攔截回 404/非預期）

- [ ] **Step 3: 加 `tags()` 方法**

在 `AnimeController` 類別內、`show()` 之後新增（比照 `AnimeListController@tags`，import 已有 `App\Models\Anime`；新增 `use App\Services\Shared\GenreTags;` 於檔案頂部 use 區）：

```php
    public function tags(): JsonResponse
    {
        $counts = [];
        Anime::query()
            ->select(['id', 'tags'])
            ->get()
            ->each(function (Anime $anime) use (&$counts): void {
                foreach ($anime->tags ?? [] as $tag) {
                    if (! GenreTags::isGenreTag($tag)) {
                        continue;
                    }
                    $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                }
            });

        $tags = collect($counts)
            ->map(fn (int $count, string $tag) => ['tag' => $tag, 'count' => $count])
            ->values()
            ->sortBy([['count', 'desc'], ['tag', 'asc']])
            ->values()
            ->all();

        return response()->json(['tags' => $tags]);
    }
```

於檔案頂部 use 區加入（若尚未存在）：

```php
use App\Services\Shared\GenreTags;
```

- [ ] **Step 4: 註冊路由（放在 `{id}` 之前）**

修改 `backend/routes/api.php`，把第 13-14 行：

```php
Route::get('/anime', [AnimeController::class, 'index']);
Route::get('/anime/{id}', [AnimeController::class, 'show']);
```

改為：

```php
Route::get('/anime', [AnimeController::class, 'index']);
Route::get('/anime/tags', [AnimeController::class, 'tags']);
Route::get('/anime/{id}', [AnimeController::class, 'show']);
```

- [ ] **Step 5: 跑測試確認通過**

Run: `docker compose exec backend php artisan test --filter=test_tags_endpoint_returns_genre_tags_with_counts_excluding_source_tags`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Api/AnimeController.php backend/routes/api.php backend/tests/Feature/AnimeCatalogRecentTest.php
git commit -m "feat: 新增 /anime/tags 全庫分類清單端點

比照我的清單作法，回傳全庫 genre tag 與 count（排除 source tag），
供資料庫頁分類篩選使用。路由放在 /anime/{id} 之前避免被攔截。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: 後端 — 近期模式 + `tags` 篩選

**Files:**
- Modify: `backend/app/Http/Controllers/Api/AnimeController.php`（`index()` 方法）
- Test: `backend/tests/Feature/AnimeCatalogRecentTest.php`

- [ ] **Step 1: 寫失敗測試（近期模式排序 + 上限）**

在 `AnimeCatalogRecentTest` 類別內新增：

```php
    public function test_recent_mode_orders_by_air_date_desc_with_null_last(): void
    {
        $this->makeAnime('舊番', ['air_date' => '2020-01-01']);
        $this->makeAnime('新番', ['air_date' => '2026-01-01']);
        $this->makeAnime('無日期', ['air_date' => null]);

        $names = collect($this->getJson('/anime')->assertOk()->json('items'))
            ->pluck('name')->all();

        $this->assertSame('新番', $names[0]);
        $this->assertSame('舊番', $names[1]);
        $this->assertSame('無日期', $names[2]); // null 排最後
    }

    public function test_recent_mode_caps_at_50(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->makeAnime("番{$i}", ['air_date' => sprintf('2026-01-%02d', ($i % 28) + 1)]);
        }

        $items = $this->getJson('/anime')->assertOk()->json('items');
        $this->assertCount(50, $items);
    }
```

- [ ] **Step 2: 跑測試確認失敗**

Run: `docker compose exec backend php artisan test --filter=test_recent_mode`
Expected: FAIL（目前無 year 時無上限且排序為 air_date asc）

- [ ] **Step 3: 改 `index()` 加近期模式**

在 `backend/app/Http/Controllers/Api/AnimeController.php` 的 `index()` 中，
`$isYearScoped = $year !== null;` 這行之後新增近期模式判定：

```php
        $isYearScoped = $year !== null;
        $isRecentMode = $year === null && $season === '' && $query === '';
```

接著把 query builder 的排序與 limit 段落改寫。找到現有這段：

```php
            ->when($year !== null, fn ($builder) => $builder->where('season_year', (int) $year))
            ->when($season !== '', fn ($builder) => $builder->where('season_code', $season))
            ->orderByRaw('air_date is null')
            ->orderBy('air_date')
            ->orderBy('name')
            ->when(! $isYearScoped, fn ($builder) => $builder->limit(200))
```

改為：

```php
            ->when($year !== null, fn ($builder) => $builder->where('season_year', (int) $year))
            ->when($season !== '', fn ($builder) => $builder->where('season_code', $season))
            ->orderByRaw('air_date is null')
            ->when(
                $isRecentMode,
                fn ($builder) => $builder->orderByDesc('air_date'),
                fn ($builder) => $builder->orderBy('air_date'),
            )
            ->orderBy('name')
            ->when($isRecentMode, fn ($builder) => $builder->limit(50))
            ->when(! $isRecentMode && ! $isYearScoped, fn ($builder) => $builder->limit(200))
```

- [ ] **Step 4: 跑測試確認通過**

Run: `docker compose exec backend php artisan test --filter=test_recent_mode`
Expected: PASS（兩個 test）

- [ ] **Step 5: 寫失敗測試（tags OR 篩選）**

在 `AnimeCatalogRecentTest` 類別內新增：

```php
    public function test_filters_by_tags_with_or_semantics(): void
    {
        $this->makeAnime('戀愛番', ['tags' => ['戀愛'], 'air_date' => '2026-01-01']);
        $this->makeAnime('戰鬥番', ['tags' => ['戰鬥'], 'air_date' => '2026-01-02']);
        $this->makeAnime('搞笑番', ['tags' => ['搞笑'], 'air_date' => '2026-01-03']);

        $names = collect($this->getJson('/anime?tags=戀愛,戰鬥')->assertOk()->json('items'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['戀愛番', '戰鬥番'], $names);
    }
```

- [ ] **Step 6: 跑測試確認失敗**

Run: `docker compose exec backend php artisan test --filter=test_filters_by_tags_with_or_semantics`
Expected: FAIL（`tags` 參數尚未被處理，回傳全部 3 筆）

- [ ] **Step 7: 加 `tags` 參數解析與篩選**

在 `index()` 方法開頭、`$term = "%{$query}%";` 之後加入解析：

```php
        $tags = array_values(array_filter(
            array_map('trim', explode(',', (string) $request->query('tags', ''))),
            fn (string $t): bool => $t !== ''
        ));
```

在 query builder 中，`->when($season !== '', ...)` 這行之後、`->orderByRaw(...)` 之前插入 tags 篩選：

```php
            ->when($tags !== [], function ($builder) use ($tags): void {
                $builder->where(function ($where) use ($tags): void {
                    foreach ($tags as $tag) {
                        $where->orWhereJsonContains('tags', $tag);
                    }
                });
            })
```

- [ ] **Step 8: 跑測試確認通過**

Run: `docker compose exec backend php artisan test --filter=test_filters_by_tags_with_or_semantics`
Expected: PASS

- [ ] **Step 9: 跑全部後端測試確認無 regression**

Run: `docker compose exec backend php artisan test`
Expected: 全綠（現有 year/season/搜尋測試維持通過）

- [ ] **Step 10: Commit**

```bash
git add backend/app/Http/Controllers/Api/AnimeController.php backend/tests/Feature/AnimeCatalogRecentTest.php
git commit -m "feat: 資料庫查詢新增近期模式與 tags 分類篩選

無 year/season/q 時走近期模式：air_date 由新到舊（null 排最後）、上限 50 筆。
新增 tags 參數（逗號分隔、OR 邏輯），比照我的清單以 orWhereJsonContains 篩選。
既有年份瀏覽、season、搜尋行為不變。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: 前端 — `useApi` 擴充 tags 與 catalogTags

**Files:**
- Modify: `frontend/app/composables/useApi.ts:110-121`

- [ ] **Step 1: 擴充 `searchAnime` 與新增 `catalogTags`**

修改 `frontend/app/composables/useApi.ts`，把現有 `searchAnime`：

```typescript
    searchAnime: (query: string, filters: { year?: number | string; season?: string } = {}) => {
      const params = new URLSearchParams()
      if (query) params.set('q', query)
      if (filters.year) params.set('year', String(filters.year))
      if (filters.season) params.set('season', filters.season)
      const queryString = params.toString()
      return request(`/anime${queryString ? `?${queryString}` : ''}`)
    },
```

改為：

```typescript
    searchAnime: (query: string, filters: { year?: number | string; season?: string; tags?: string[] } = {}) => {
      const params = new URLSearchParams()
      if (query) params.set('q', query)
      if (filters.year) params.set('year', String(filters.year))
      if (filters.season) params.set('season', filters.season)
      if (filters.tags?.length) params.set('tags', filters.tags.join(','))
      const queryString = params.toString()
      return request(`/anime${queryString ? `?${queryString}` : ''}`)
    },
    catalogTags: () => request('/anime/tags'),
```

- [ ] **Step 2: 型別檢查通過**

Run: `cd frontend && npx nuxi typecheck 2>&1 | tail -20`
Expected: 無新增 type error（若專案 typecheck 本來就有既有錯誤，只需確認未新增與 useApi 相關的錯）

> 若 `nuxi typecheck` 太慢或環境未裝，可跳過，改由 Task 5 的 build 檢查覆蓋。

- [ ] **Step 3: Commit**

```bash
git add frontend/app/composables/useApi.ts
git commit -m "feat: useApi.searchAnime 支援 tags 參數並新增 catalogTags

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: 前端 — `catalog.vue` 三模式狀態機 + 分類 chip 列

**Files:**
- Modify: `frontend/app/pages/catalog.vue`（整檔 `<script setup>` 與 template）

**設計要點：**
- 三模式互斥：近期（`activeYear = null`）／年份（`activeYear = 某年`）／搜尋（`query` 非空）。
- 近期模式與搜尋模式可疊加 `selectedTags`；切到年份模式時清空 tags。
- tags 或 query 變動時向後端重查，用 `requestId` 防 race。

- [ ] **Step 1: 改寫 `<script setup>`**

把 `frontend/app/pages/catalog.vue` 的整段 `<script setup lang="ts"> ... </script>` 替換為：

```vue
<script setup lang="ts">
import { normalizeAnime, tagColor } from '../utils/normalize'
import type { Anime } from '../utils/normalize'

const api = useApi()
const { isAuthed } = useSession()
const toast = useToast()

const query = ref('')
const page = ref(1)
const error = ref('')

const currentYear = new Date().getFullYear()
// activeYear = null → 近期模式（不限年份，air_date 新到舊，50 筆）
// activeYear = 數字 → 年份瀏覽模式
const activeYear = ref<number | null>(null)
const selectedTags = ref<string[]>([])
const isSearchMode = computed(() => query.value.trim() !== '')
const isRecentMode = computed(() => activeYear.value === null && !isSearchMode.value)

// 分類清單（全庫前 20 高頻）
const tagOptions = ref<{ tag: string; count: number }[]>([])
onMounted(async () => {
  try {
    const res = await api.catalogTags()
    tagOptions.value = (res.tags || []).slice(0, 20)
  } catch {
    tagOptions.value = []
  }
})

// 主要資料來源：依 activeYear / query / selectedTags 向後端查詢
const catalog = ref<Anime[]>([])
const loading = ref(false)
let requestId = 0

async function loadCatalog() {
  const id = ++requestId
  loading.value = true
  error.value = ''
  try {
    const q = query.value.trim()
    const filters: { year?: number; tags?: string[] } = {}
    if (activeYear.value !== null && !isSearchMode.value) filters.year = activeYear.value
    if (selectedTags.value.length > 0) filters.tags = selectedTags.value
    const result = await api.searchAnime(q, filters)
    if (id !== requestId) return
    catalog.value = (result.items || []).map(normalizeAnime)
  } catch (err: any) {
    if (id !== requestId) return
    error.value = err.message || '載入失敗'
    catalog.value = []
  } finally {
    if (id === requestId) loading.value = false
  }
}

// 進站載入近期模式
await useAsyncData('catalog-initial', async () => {
  await loadCatalog()
  return true
}, { default: () => true })

const PAGE_SIZE = 40
const totalPages = computed(() => Math.max(1, Math.ceil(catalog.value.length / PAGE_SIZE)))
const pagedCatalog = computed(() => {
  const start = (page.value - 1) * PAGE_SIZE
  return catalog.value.slice(start, start + PAGE_SIZE)
})
watch(page, () => {
  window.scrollTo({ top: 0, behavior: 'smooth' })
})

function changeYear(year: number | null) {
  query.value = ''
  selectedTags.value = []
  page.value = 1
  activeYear.value = year
  loadCatalog()
}

function toggleTag(tag: string) {
  const idx = selectedTags.value.indexOf(tag)
  if (idx >= 0) selectedTags.value.splice(idx, 1)
  else selectedTags.value.push(tag)
  page.value = 1
  loadCatalog()
}

function clearTags() {
  if (selectedTags.value.length === 0) return
  selectedTags.value = []
  page.value = 1
  loadCatalog()
}

async function search() {
  page.value = 1
  // 搜尋時脫離年份模式（回到不限年份），保留 selectedTags
  if (query.value.trim() !== '') activeYear.value = null
  await loadCatalog()
}

async function addAnime(animeId: number) {
  if (!isAuthed.value) return navigateTo('/login')
  try {
    await api.addToList(animeId)
    toast.add({ title: '已加入清單', color: 'success' })
  } catch (err: any) {
    toast.add({ title: err.message || '加入失敗', color: 'error' })
  }
}

useSeoMeta({
  title: () => isSearchMode.value
    ? `搜尋「${query.value}」的結果｜動漫庫`
    : activeYear.value !== null
      ? `${activeYear.value}年動漫作品列表｜動畫資料庫、動漫庫`
      : '近期動漫作品｜動畫資料庫、動漫庫',
  description: () => isSearchMode.value
    ? `在動漫庫搜尋「${query.value}」相關動畫作品。`
    : activeYear.value !== null
      ? `瀏覽${activeYear.value}年度動畫作品完整列表，探索動漫新番與經典動畫資料庫。`
      : '瀏覽近期動畫作品，依播出日期排序，探索最新動漫新番。',
  ogType: 'website'
})
useHead({
  link: [{ rel: 'canonical', href: () => isSearchMode.value || activeYear.value === null
    ? 'https://anime.kaistarstudio.me/catalog'
    : `https://anime.kaistarstudio.me/catalog?year=${activeYear.value}` }]
})
</script>
```

- [ ] **Step 2: 改寫 template 的年份切換器與新增分類 chip 列**

在 `frontend/app/pages/catalog.vue` template 中，把現有「Year switcher」整段：

```vue
    <!-- Year switcher (hidden while a keyword search is active) -->
    <div v-if="!isSearchMode" class="flex items-center gap-1">
      <button
        type="button"
        :disabled="loading"
        class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 disabled:opacity-40"
        aria-label="上一年"
        @click="changeYear(activeYear - 1)"
      >
        <UIcon name="i-lucide-chevron-left" class="size-4" />
      </button>
      <span class="min-w-16 text-center text-sm font-bold text-gray-700">{{ activeYear }} 年</span>
      <button
        type="button"
        :disabled="loading || activeYear >= currentYear"
        class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 disabled:opacity-40"
        aria-label="下一年"
        @click="changeYear(activeYear + 1)"
      >
        <UIcon name="i-lucide-chevron-right" class="size-4" />
      </button>
    </div>
```

替換為（近期按鈕 + 年份切換器並存）：

```vue
    <!-- 近期 / 年份切換（搜尋中隱藏） -->
    <div v-if="!isSearchMode" class="flex items-center gap-2">
      <button
        type="button"
        :disabled="loading"
        class="rounded-lg border px-3 py-1.5 text-sm font-semibold shadow-sm transition disabled:opacity-40"
        :class="isRecentMode
          ? 'border-primary-500 bg-primary-50 text-primary-700'
          : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'"
        @click="changeYear(null)"
      >
        近期
      </button>
      <div class="flex items-center gap-1">
        <button
          type="button"
          :disabled="loading"
          class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 disabled:opacity-40"
          aria-label="上一年"
          @click="changeYear((activeYear ?? currentYear) - 1)"
        >
          <UIcon name="i-lucide-chevron-left" class="size-4" />
        </button>
        <span class="min-w-16 text-center text-sm font-bold text-gray-700">
          {{ activeYear !== null ? `${activeYear} 年` : '選擇年份' }}
        </span>
        <button
          type="button"
          :disabled="loading || (activeYear !== null && activeYear >= currentYear)"
          class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 disabled:opacity-40"
          aria-label="下一年"
          @click="changeYear((activeYear ?? currentYear - 1) + 1)"
        >
          <UIcon name="i-lucide-chevron-right" class="size-4" />
        </button>
      </div>
    </div>

    <!-- 分類 chip 列（搜尋中仍可用；年份模式下隱藏，避免與年份上限混淆） -->
    <div v-if="!isSearchMode && tagOptions.length > 0" class="flex flex-wrap items-center gap-1.5">
      <button
        type="button"
        class="rounded-full px-3 py-1 text-xs font-semibold transition"
        :class="selectedTags.length === 0
          ? 'bg-primary-600 text-white'
          : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
        @click="clearTags()"
      >
        全部
      </button>
      <button
        v-for="item in tagOptions"
        :key="item.tag"
        type="button"
        class="rounded-full px-3 py-1 text-xs font-semibold transition"
        :class="selectedTags.includes(item.tag) ? 'ring-2 ring-primary-500' : 'hover:opacity-80'"
        :style="selectedTags.includes(item.tag)
          ? { backgroundColor: tagColor(item.tag).bg, color: tagColor(item.tag).text }
          : { backgroundColor: tagColor(item.tag).bg, color: tagColor(item.tag).text }"
        @click="toggleTag(item.tag)"
      >
        {{ item.tag }}
      </button>
    </div>
```

> 註：`tagColor` 已在 Step 1 的 import 中加入。selectedTags 選中態用 `ring` 標示（沿用 seasonal 頁 genre chip 的視覺語彙）。

- [ ] **Step 3: 啟動前端確認頁面可載入**

Run: `docker compose up -d frontend`（若尚未啟動）
手動開 `localhost:3000/catalog` 確認：進站顯示近期作品、分類 chip 可點、年份切換可用、搜尋可用。

- [ ] **Step 4: Commit**

```bash
git add frontend/app/pages/catalog.vue
git commit -m "feat: 資料庫頁改為近期作品預設並加入分類篩選

進站顯示不限年份的近期 50 筆（air_date 由新到舊）；新增前 20 高頻分類
chip 列（後端篩選、OR 多選）；保留年份切換器與搜尋，三模式互斥切換。

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: 驗證與收尾

**Files:** 無新增

- [ ] **Step 1: 後端全測試**

Run: `docker compose exec backend php artisan test`
Expected: 全綠

- [ ] **Step 2: 前端測試 + build 檢查**

Run: `cd frontend && npm run test && npm run build`
Expected: 測試通過、build 成功（無 type error）

- [ ] **Step 3: 端到端手動驗證（用 verify skill 或手動）**

在 `localhost:3000/catalog` 驗證四條路徑：
1. 進站 → 顯示近期作品（最新在前），筆數 ≤ 50。
2. 點分類 chip（如「戀愛」）→ 列表更新為該分類、可多選、可清除。
3. 點年份切換 → 進年份模式（tags 清空），可上下年瀏覽。
4. 輸入關鍵字搜尋 → 全庫搜尋，可與 tags 併用。

- [ ] **Step 4: 若有未提交變更則收尾 commit**

```bash
git status
# 如有殘留變更再 git add / commit
```

---

## Self-Review 對照

- **spec 涵蓋**：tags 後端篩選（Task 2）、近期模式排序+50 上限（Task 2）、/anime/tags 端點（Task 1）、前端三模式+chip 列（Task 4）、年份切換保留（Task 4）、前 20 分類（Task 4 Step 1 slice(0,20)）、searchAnime 擴充（Task 3）。全部對應。
- **型別一致**：`searchAnime` filters 型別在 Task 3 定為 `{ year?; season?; tags? }`，Task 4 呼叫 `{ year?; tags? }` 為其子集，一致。`catalogTags()`／`toggleTag`／`clearTags`／`changeYear(number | null)` 命名前後一致。
- **無 placeholder**：所有 step 皆含實際程式碼與指令。
