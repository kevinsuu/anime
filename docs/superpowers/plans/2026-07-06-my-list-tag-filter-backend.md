# 我的清單分類篩選改為後端查詢 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把「我的清單」頁面的分類（tag）篩選從前端同步運算改為後端 API 查詢，讓點擊分類時能顯示 loading 狀態。

**Architecture:** 後端 `AnimeListController` 新增 `GET /my/anime-list/tags`（回傳分類選項與計數，基於使用者完整清單）、並讓既有 `GET /my/anime-list` 支援可選的 `tags` query 參數（OR 篩選）。前端 `/list` 頁面改用這兩支 API：`tagOptions` 於 mount 時抓一次，選擇分類時觸發重新查詢並顯示既有的 skeleton loading，用遞增請求編號捨棄過期回應。狀態 tab（全部/已看/未看/自訂清單）維持前端運算不變。

**Tech Stack:** Laravel（PHP）後端 + Nuxt 4 / Vue 3（TypeScript）前端，Vitest 前端測試，PHPUnit 後端測試。

---

## Task 1: 後端 — 新增 GenreTags 共用判斷邏輯

**Files:**
- Create: `backend/app/Services/Shared/GenreTags.php`
- Test: `backend/tests/Unit/GenreTagsTest.php`

- [ ] **Step 1: 寫失敗測試**

建立 `backend/tests/Unit/GenreTagsTest.php`：

```php
<?php

namespace Tests\Unit;

use App\Services\Shared\GenreTags;
use PHPUnit\Framework\TestCase;

final class GenreTagsTest extends TestCase
{
    public function test_returns_true_for_genre_tags(): void
    {
        $this->assertTrue(GenreTags::isGenreTag('戀愛'));
        $this->assertTrue(GenreTags::isGenreTag('戰鬥'));
    }

    public function test_returns_false_for_source_type_tags(): void
    {
        $this->assertFalse(GenreTags::isGenreTag('新作'));
        $this->assertFalse(GenreTags::isGenreTag('續作'));
        $this->assertFalse(GenreTags::isGenreTag('漫畫改編'));
        $this->assertFalse(GenreTags::isGenreTag('小說改編'));
        $this->assertFalse(GenreTags::isGenreTag('原創作品'));
        $this->assertFalse(GenreTags::isGenreTag('遊戲改編'));
        $this->assertFalse(GenreTags::isGenreTag('跨季續播'));
    }

    public function test_returns_false_for_season_count_tags(): void
    {
        $this->assertFalse(GenreTags::isGenreTag('2季度'));
        $this->assertFalse(GenreTags::isGenreTag('12季度'));
    }
}
```

- [ ] **Step 2: 執行測試確認失敗**

Run: `docker compose exec backend php artisan test --filter=GenreTagsTest`
Expected: FAIL，錯誤訊息為找不到 `App\Services\Shared\GenreTags` 類別

- [ ] **Step 3: 實作 GenreTags**

建立 `backend/app/Services/Shared/GenreTags.php`：

```php
<?php

namespace App\Services\Shared;

final class GenreTags
{
    // 與 frontend/app/composables/useSeasonalCatalog.ts 的 SOURCE_TAGS / isGenreTag() 保持一致
    // 若其中一份修改（新增/移除排除規則），需同步更新另一份
    private const SOURCE_TAGS = ['新作', '續作', '漫畫改編', '小說改編', '原創作品', '遊戲改編', '跨季續播'];

    public static function isGenreTag(string $tag): bool
    {
        return ! in_array($tag, self::SOURCE_TAGS, true) && ! preg_match('/^\d+季度/u', $tag);
    }
}
```

- [ ] **Step 4: 執行測試確認通過**

Run: `docker compose exec backend php artisan test --filter=GenreTagsTest`
Expected: PASS（3 個測試全過）

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Shared/GenreTags.php backend/tests/Unit/GenreTagsTest.php
git commit -m "feat: 新增後端分類標籤判斷邏輯 GenreTags"
```

---

## Task 2: 後端 — GET /my/anime-list/tags 端點

**Files:**
- Modify: `backend/routes/api.php:19`
- Modify: `backend/app/Http/Controllers/Api/AnimeListController.php`
- Test: `backend/tests/Feature/AnimeListTagsTest.php`

- [ ] **Step 1: 寫失敗測試**

建立 `backend/tests/Feature/AnimeListTagsTest.php`：

```php
<?php

namespace Tests\Feature;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnimeListTagsTest extends TestCase
{
    use RefreshDatabase;

    private function loginToken(): string
    {
        $login = $this->postJson('/auth/google', ['idToken' => 'dev:dev@example.com']);

        return $login->json('token');
    }

    private function addAnimeToList(string $token, string $name, array $tags): void
    {
        $anime = Anime::query()->create([
            'name' => $name,
            'description' => '簡介',
            'image_url' => 'https://example.com/anime.jpg',
            'source' => 'test',
            'tags' => $tags,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/my/anime-list', ['animeId' => $anime->id])
            ->assertCreated();
    }

    public function test_returns_deduped_counts_sorted_by_count_desc(): void
    {
        $token = $this->loginToken();
        $this->addAnimeToList($token, '作品A', ['戀愛', '戰鬥']);
        $this->addAnimeToList($token, '作品B', ['戀愛', '搞笑']);
        $this->addAnimeToList($token, '作品C', ['戰鬥']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list/tags')
            ->assertOk();

        $response->assertJsonPath('tags', [
            ['tag' => '戀愛', 'count' => 2],
            ['tag' => '戰鬥', 'count' => 2],
            ['tag' => '搞笑', 'count' => 1],
        ]);
    }

    public function test_excludes_non_genre_tags(): void
    {
        $token = $this->loginToken();
        $this->addAnimeToList($token, '作品A', ['新作', '漫畫改編', '2季度', '戀愛']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list/tags')
            ->assertOk();

        $response->assertJsonPath('tags', [
            ['tag' => '戀愛', 'count' => 1],
        ]);
    }

    public function test_returns_empty_array_when_list_is_empty(): void
    {
        $token = $this->loginToken();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list/tags')
            ->assertOk()
            ->assertJsonPath('tags', []);
    }
}
```

- [ ] **Step 2: 執行測試確認失敗**

Run: `docker compose exec backend php artisan test --filter=AnimeListTagsTest`
Expected: FAIL，`GET /my/anime-list/tags` 回傳 404（路由不存在）

- [ ] **Step 3: 新增路由**

修改 `backend/routes/api.php`，在第 19 行 `Route::get('/my/anime-list', [AnimeListController::class, 'index']);` 之後新增：

```php
    Route::get('/my/anime-list', [AnimeListController::class, 'index']);
    Route::get('/my/anime-list/tags', [AnimeListController::class, 'tags']);
    Route::post('/my/anime-list', [AnimeListController::class, 'store']);
```

- [ ] **Step 4: 實作 tags() 方法**

修改 `backend/app/Http/Controllers/Api/AnimeListController.php`，於檔案頂部新增 use：

```php
use App\Services\Shared\GenreTags;
```

在 `index()` 方法之後（第 31 行後）新增 `tags()` 方法：

```php
    public function tags(Request $request): JsonResponse
    {
        $userId = (int) $request->attributes->get('auth_user_id');

        $counts = [];
        UserAnimeListItem::query()
            ->where('user_id', $userId)
            ->with('anime:id,tags')
            ->get()
            ->each(function (UserAnimeListItem $item) use (&$counts): void {
                foreach ($item->anime->tags ?? [] as $tag) {
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

- [ ] **Step 5: 執行測試確認通過**

Run: `docker compose exec backend php artisan test --filter=AnimeListTagsTest`
Expected: PASS（3 個測試全過）

- [ ] **Step 6: Commit**

```bash
git add backend/routes/api.php backend/app/Http/Controllers/Api/AnimeListController.php backend/tests/Feature/AnimeListTagsTest.php
git commit -m "feat: 新增 GET /my/anime-list/tags 分類選項端點"
```

---

## Task 3: 後端 — GET /my/anime-list 支援 tags 篩選參數

**Files:**
- Modify: `backend/app/Http/Controllers/Api/AnimeListController.php:26-31,137-147`
- Test: `backend/tests/Feature/AnimeListTagFilterTest.php`

- [ ] **Step 1: 寫失敗測試**

建立 `backend/tests/Feature/AnimeListTagFilterTest.php`：

```php
<?php

namespace Tests\Feature;

use App\Models\Anime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnimeListTagFilterTest extends TestCase
{
    use RefreshDatabase;

    private function loginToken(): string
    {
        $login = $this->postJson('/auth/google', ['idToken' => 'dev:dev@example.com']);

        return $login->json('token');
    }

    private function addAnimeToList(string $token, string $name, array $tags): void
    {
        $anime = Anime::query()->create([
            'name' => $name,
            'description' => '簡介',
            'image_url' => 'https://example.com/anime.jpg',
            'source' => 'test',
            'tags' => $tags,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/my/anime-list', ['animeId' => $anime->id])
            ->assertCreated();
    }

    public function test_filters_by_single_tag(): void
    {
        $token = $this->loginToken();
        $this->addAnimeToList($token, '戀愛作品', ['戀愛']);
        $this->addAnimeToList($token, '戰鬥作品', ['戰鬥']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list?tags=戀愛')
            ->assertOk();

        $names = collect($response->json('items'))->pluck('anime.name')->all();
        $this->assertSame(['戀愛作品'], $names);
    }

    public function test_filters_by_multiple_tags_with_or_semantics(): void
    {
        $token = $this->loginToken();
        $this->addAnimeToList($token, '戀愛作品', ['戀愛']);
        $this->addAnimeToList($token, '戰鬥作品', ['戰鬥']);
        $this->addAnimeToList($token, '搞笑作品', ['搞笑']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list?tags=戀愛,戰鬥')
            ->assertOk();

        $names = collect($response->json('items'))->pluck('anime.name')->sort()->values()->all();
        $this->assertSame(['戀愛作品', '戰鬥作品'], $names);
    }

    public function test_no_tags_param_returns_full_list(): void
    {
        $token = $this->loginToken();
        $this->addAnimeToList($token, '戀愛作品', ['戀愛']);
        $this->addAnimeToList($token, '戰鬥作品', ['戰鬥']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/my/anime-list')
            ->assertOk();

        $this->assertCount(2, $response->json('items'));
    }
}
```

- [ ] **Step 2: 執行測試確認失敗**

Run: `docker compose exec backend php artisan test --filter=AnimeListTagFilterTest`
Expected: FAIL，`test_filters_by_single_tag` 與 `test_filters_by_multiple_tags_with_or_semantics` 失敗（回傳全部項目，未套用篩選）

- [ ] **Step 3: 修改 index() 與 listForUser()**

修改 `backend/app/Http/Controllers/Api/AnimeListController.php` 第 26-31 行，將：

```php
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'items' => $this->listForUser((int) $request->attributes->get('auth_user_id')),
        ]);
    }
```

改為：

```php
    public function index(Request $request): JsonResponse
    {
        $tags = array_values(array_filter(explode(',', (string) $request->query('tags', ''))));

        return response()->json([
            'items' => $this->listForUser((int) $request->attributes->get('auth_user_id'), $tags),
        ]);
    }
```

修改第 137-147 行的 `listForUser()`，將：

```php
    private function listForUser(int $userId): array
    {
        return UserAnimeListItem::query()
            ->with(['anime', 'collections:id,name'])
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (UserAnimeListItem $item): array => $this->formatItem($item))
            ->all();
    }
```

改為：

```php
    private function listForUser(int $userId, array $tags = []): array
    {
        return UserAnimeListItem::query()
            ->with(['anime', 'collections:id,name'])
            ->where('user_id', $userId)
            ->when($tags !== [], function ($query) use ($tags): void {
                $query->whereHas('anime', function ($q) use ($tags): void {
                    $q->where(function ($q2) use ($tags): void {
                        foreach ($tags as $tag) {
                            $q2->orWhereJsonContains('tags', $tag);
                        }
                    });
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (UserAnimeListItem $item): array => $this->formatItem($item))
            ->all();
    }
```

- [ ] **Step 4: 執行測試確認通過**

Run: `docker compose exec backend php artisan test --filter=AnimeListTagFilterTest`
Expected: PASS（3 個測試全過）

- [ ] **Step 5: 執行既有回歸測試確認未破壞**

Run: `docker compose exec backend php artisan test --filter=ApiTest`
Expected: PASS（`test_authenticated_user_can_manage_anime_list` 等既有測試不受影響）

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Api/AnimeListController.php backend/tests/Feature/AnimeListTagFilterTest.php
git commit -m "feat: GET /my/anime-list 支援 tags 篩選參數"
```

---

## Task 4: 前端 — useApi.ts 新增/調整 API 方法

**Files:**
- Modify: `frontend/app/composables/useApi.ts:120`

- [ ] **Step 1: 修改 myList 並新增 myListTags**

修改 `frontend/app/composables/useApi.ts` 第 120 行，將：

```ts
    myList: () => request('/my/anime-list'),
```

改為：

```ts
    myList: (params?: { tags?: string[] }) => {
      const qs = params?.tags?.length ? `?tags=${encodeURIComponent(params.tags.join(','))}` : ''
      return request(`/my/anime-list${qs}`)
    },
    myListTags: () => request('/my/anime-list/tags'),
```

- [ ] **Step 2: 確認型別檢查通過**

Run: `cd frontend && npx vue-tsc --noEmit`
Expected: 無新增錯誤（`myList()` 呼叫端目前為無參數呼叫，仍相容於新的 optional 參數簽章）

- [ ] **Step 3: Commit**

```bash
git add frontend/app/composables/useApi.ts
git commit -m "feat: myList 支援 tags 參數，新增 myListTags API"
```

---

## Task 5: 前端 — 簡化 listFilters.ts，移除前端 tag 篩選邏輯

**Files:**
- Modify: `frontend/app/utils/listFilters.ts`
- Modify: `frontend/test/listFilters.test.ts`

- [ ] **Step 1: 更新測試檔，移除 extractTagOptions/matchesSelectedTags 相關案例，簡化 applyListFilters 案例**

將 `frontend/test/listFilters.test.ts` 整份內容取代為：

```ts
import { describe, expect, it } from 'vitest'
import { applyListFilters } from '../app/utils/listFilters'
import { normalizeListItem } from '../app/utils/normalize'
import type { ListItem } from '../app/utils/normalize'

function makeListItem(opts: { watched?: boolean; collections?: { id: number; name: string }[] }): ListItem {
  return normalizeListItem({
    id: Math.random(),
    watched: opts.watched ?? false,
    collections: opts.collections ?? [],
    anime: { id: 1, name: '測試作品', tags: [] },
  })
}

describe('applyListFilters', () => {
  it('returns the full list for the "all" filter', () => {
    const list = [
      makeListItem({ watched: true }),
      makeListItem({ watched: false }),
    ]
    expect(applyListFilters(list, 'all')).toHaveLength(2)
  })

  it('filters to only watched items', () => {
    const list = [
      makeListItem({ watched: true }),
      makeListItem({ watched: false }),
    ]
    const result = applyListFilters(list, 'watched')
    expect(result).toHaveLength(1)
    expect(result[0].watched).toBe(true)
  })

  it('filters to only unwatched items', () => {
    const list = [
      makeListItem({ watched: true }),
      makeListItem({ watched: false }),
    ]
    const result = applyListFilters(list, 'unwatched')
    expect(result).toHaveLength(1)
    expect(result[0].watched).toBe(false)
  })

  it('filters to items within a given collection', () => {
    const col = { id: 1, name: '我的最愛' }
    const list = [
      makeListItem({ collections: [col] }),
      makeListItem({ collections: [] }),
    ]
    const result = applyListFilters(list, 'col:1')
    expect(result).toHaveLength(1)
    expect(result[0].collections).toEqual([col])
  })
})
```

- [ ] **Step 2: 執行測試確認失敗**

Run: `cd frontend && npx vitest run listFilters.test.ts`
Expected: FAIL，`applyListFilters` 呼叫仍是舊簽章 `(list, statusFilter, selectedTags)`，但 import 找不到 `extractTagOptions`/`matchesSelectedTags`（因為還沒改實作檔），或型別/邏輯不符

- [ ] **Step 3: 簡化 listFilters.ts**

將 `frontend/app/utils/listFilters.ts` 整份內容取代為：

```ts
import type { ListItem } from './normalize'

export interface TagOption {
  tag: string
  count: number
}

// Composes with the tag filter (now server-side, see useApi.ts myList()) —
// this only applies the status filter (all/watched/unwatched/col:{id}).
export function applyListFilters(list: ListItem[], statusFilter: string): ListItem[] {
  if (statusFilter === 'watched') return list.filter(i => i.watched)
  if (statusFilter === 'unwatched') return list.filter(i => !i.watched)
  if (statusFilter.startsWith('col:')) {
    const colId = Number(statusFilter.slice(4))
    return list.filter(i => i.collections.some(c => c.id === colId))
  }
  return list
}
```

- [ ] **Step 4: 執行測試確認通過**

Run: `cd frontend && npx vitest run listFilters.test.ts`
Expected: PASS（4 個測試全過）

- [ ] **Step 5: Commit**

```bash
git add frontend/app/utils/listFilters.ts frontend/test/listFilters.test.ts
git commit -m "refactor: 移除 listFilters 前端分類篩選邏輯，改由後端負責"
```

---

## Task 6: 前端 — /list 頁面改用後端分類 API 並顯示 loading

**Files:**
- Modify: `frontend/app/pages/list/index.vue`

- [ ] **Step 1: 修改 import 與新增 state**

修改 `frontend/app/pages/list/index.vue` 第 1-18 行，將：

```ts
<script setup lang="ts">
import { normalizeListItem, normalizeCollection } from '../../utils/normalize'
import type { ListItem, Collection } from '../../utils/normalize'
import { applyListFilters, extractTagOptions } from '../../utils/listFilters'
import { tagColor } from '../../utils/normalize'

definePageMeta({ middleware: 'auth' })

useSeoMeta({ robots: 'noindex, nofollow' })

const api = useApi()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const list = ref<ListItem[]>([])
const collections = ref<Collection[]>([])
const loading = ref(false)
```

改為：

```ts
<script setup lang="ts">
import { normalizeListItem, normalizeCollection } from '../../utils/normalize'
import type { ListItem, Collection } from '../../utils/normalize'
import { applyListFilters } from '../../utils/listFilters'
import type { TagOption } from '../../utils/listFilters'
import { tagColor } from '../../utils/normalize'

definePageMeta({ middleware: 'auth' })

useSeoMeta({ robots: 'noindex, nofollow' })

const api = useApi()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const list = ref<ListItem[]>([])
const collections = ref<Collection[]>([])
const tagOptions = ref<TagOption[]>([])
const loading = ref(false)
const tagLoading = ref(false)
let tagRequestId = 0
```

- [ ] **Step 2: 移除前端 tagOptions computed，改用 fetch + watcher**

修改第 59-61 行（在移除 import 後，行號可能已偏移一行，以文字比對為準），將：

```ts
const tagOptions = computed(() => extractTagOptions(list.value))

const filteredList = computed(() => applyListFilters(list.value, activeFilter.value, selectedTags.value))
```

改為：

```ts
const filteredList = computed(() => applyListFilters(list.value, activeFilter.value))

watch(selectedTags, async (tags) => {
  const requestId = ++tagRequestId
  tagLoading.value = true
  try {
    const result = tags.length > 0
      ? await api.myList({ tags })
      : await api.myList()
    if (requestId !== tagRequestId) return
    list.value = (result.items || []).map(normalizeListItem)
  } catch (err: any) {
    if (requestId !== tagRequestId) return
    toast.add({ title: err.message || '載入失敗', color: 'error' })
  } finally {
    if (requestId === tagRequestId) tagLoading.value = false
  }
})
```

- [ ] **Step 3: 修改 loadAll() 一併抓取 tagOptions**

找到 `loadAll()` 函式（原第 64-73 行左右）：

```ts
async function loadAll() {
  loading.value = true
  try {
    const [listRes, colRes] = await Promise.all([api.myList(), api.myCollections()])
    list.value = (listRes.items || []).map(normalizeListItem)
    collections.value = (colRes.items || []).map(normalizeCollection)
  } catch (err: any) {
    toast.add({ title: err.message || '載入失敗', color: 'error' })
  } finally {
    loading.value = false
  }
}
```

改為：

```ts
async function loadAll() {
  loading.value = true
  try {
    const [listRes, colRes, tagsRes] = await Promise.all([api.myList(), api.myCollections(), api.myListTags()])
    list.value = (listRes.items || []).map(normalizeListItem)
    collections.value = (colRes.items || []).map(normalizeCollection)
    tagOptions.value = tagsRes.tags || []
  } catch (err: any) {
    toast.add({ title: err.message || '載入失敗', color: 'error' })
  } finally {
    loading.value = false
  }
}
```

- [ ] **Step 4: 更新 loading skeleton 顯示條件**

修改 `frontend/app/pages/list/index.vue` 第 308 行，將：

```html
      <div v-if="loading" class="space-y-3">
```

改為：

```html
      <div v-if="loading || tagLoading" class="space-y-3">
```

- [ ] **Step 5: 執行前端型別檢查**

Run: `cd frontend && npx vue-tsc --noEmit`
Expected: 無錯誤（`applyListFilters` 呼叫已改為單參數，`TagOption` 型別匯入正確，`api.myListTags()`/`api.myList({ tags })` 與 Task 4 的簽章相符）

- [ ] **Step 6: 執行前端測試套件**

Run: `cd frontend && npm run test`
Expected: PASS，所有既有測試（含 Task 5 更新過的 `listFilters.test.ts`）通過

- [ ] **Step 7: Commit**

```bash
git add frontend/app/pages/list/index.vue
git commit -m "feat: /list 頁面分類篩選改用後端查詢並顯示 loading 狀態"
```

---

## Task 7: 手動驗證（Docker 環境）

**Files:** 無程式碼異動，僅驗證。

- [ ] **Step 1: 啟動服務**

Run: `docker compose up -d`
Expected: `mysql`、`backend`、`frontend` 等容器皆為 healthy/running

- [ ] **Step 2: 執行完整後端測試**

Run: `docker compose exec backend php artisan test`
Expected: 全數 PASS，包含 Task 1-3 新增的測試與既有的 `ApiTest`

- [ ] **Step 3: 執行完整前端測試**

Run: `cd frontend && npm run test`
Expected: 全數 PASS

- [ ] **Step 4: 瀏覽器手動驗證**

開啟 `http://localhost:3000/list`，以開發登入方式登入後：
1. 確認頁面上方分類 chip（如「戀愛」「戰鬥」）與計數正常顯示
2. 點擊任一分類 chip，確認畫面短暫顯示灰色骨架 loading（即使延遲很短也應可觀察到 `v-if` 分支切換），接著顯示篩選後的清單
3. 開啟瀏覽器 DevTools Network 分頁，確認點擊分類會發出 `GET /my/anime-list?tags=...` 請求
4. 快速連續點擊兩個不同分類，確認畫面最終停在「最後點擊的那個分類」對應的篩選結果（無錯亂閃爍回舊資料）
5. 點擊「清除分類」，確認清單還原為未篩選的完整清單
6. 切換「已看/未看/自訂清單」tab，確認狀態篩選仍正常運作（前端運算，無需 loading）

- [ ] **Step 5: 若驗證通過，無需額外 commit（本任務僅驗證）**
