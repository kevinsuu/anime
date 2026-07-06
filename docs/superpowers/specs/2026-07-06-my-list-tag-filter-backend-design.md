# 我的清單分類篩選改為後端查詢 — 設計

## 背景與目標

[2026-07-03-my-list-tag-filter-design.md](2026-07-03-my-list-tag-filter-design.md) 實作了「我的清單」（`/list`）的分類（tag）篩選，當時刻意選擇純前端方案：`tags` 資料隨 `myList()` 一次性載入，篩選與分類選項統計都在前端用 `Array.filter`／`computed` 完成，不新增 API。

目前使用者清單約 761 筆，前端 filter 是同步運算、耗時可忽略不計，因此點擊分類時畫面「瞬間」切換，沒有 loading 過場。使用者希望點擊分類後有明確的 loading 狀態，選擇的解法是把分類篩選這件事改為真正的後端查詢（而非在前端加一個假的過場動畫），讓資料量成長後也有一致的架構可以延伸。

**範圍界定**：只有「分類（tag）篩選」這一路徑改為後端查詢。狀態 tab（全部/已看/未看/自訂清單）維持前端 client-side 過濾，行為不變。

## 範圍

**要做：**
- 後端：`GET /my/anime-list` 新增可選的 `tags` query 參數，依 tag 做 OR 篩選（符合任一 tag）
- 後端：新增 `GET /my/anime-list/tags` 端點，回傳使用者清單的分類選項與計數（來源：使用者的完整清單，不受目前狀態 tab 或已選 tag 影響）
- 前端：`/list` 頁面改用上述兩支 API，點擊分類時顯示 loading skeleton，回應中若有較舊的請求後到達則捨棄
- 前端：移除 `listFilters.ts` 中原本的 `extractTagOptions`／`matchesSelectedTags` 前端計算邏輯（改由後端負責）

**不做：**
- 不改動狀態 tab（全部/已看/未看/自訂清單）的篩選方式，維持前端運算
- 不改變 `tags` 資料本身的來源、格式或爬蟲/匯入邏輯
- 不改動 `ListItemRow.vue` 分類 chip 顯示邏輯（該部分沿用既有 `anime.tags`，本次改動不影響）
- 不做 debounce；快速切換分類時單純以「只採用最新請求結果」處理競態

## 後端改動

### 1. 分類選項統計：新增 genre-tag 判斷的共用來源

前端 `isGenreTag()`（`useSeasonalCatalog.ts`）目前是排除 source/type/季度集數等標籤（例："新作"、"漫畫改編"、"12集"）、只保留劇情類型 tag（例："戀愛"、"戰鬥"）的唯一依據。後端要統計分類選項，需要相同的排除邏輯，但目前後端沒有對應實作。

前端目前的實作（`useSeasonalCatalog.ts` 第 15-21 行）：

```ts
export const SOURCE_TAGS = new Set(['新作', '續作', '漫畫改編', '小說改編', '原創作品', '遊戲改編', '跨季續播'])

export function isGenreTag(tag: string): boolean {
  return !SOURCE_TAGS.has(tag) && !tag.match(/^\d+季度/)
}
```

後端逐條對應搬移為 `backend/app/Services/Shared/GenreTags.php`：

```php
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

> 注意：這是把同一份規則在兩個語言中各維護一份，屬已知的重複成本；用註解互相標註來源檔案，降低未來修改時漏改一邊的風險。

### 2. 新增 `GET /my/anime-list/tags`

檔案：`backend/routes/api.php`，於 `/my/anime-list` 路由群組新增：

```php
Route::get('/my/anime-list/tags', [AnimeListController::class, 'tags']);
```

檔案：`backend/app/Http/Controllers/Api/AnimeListController.php` 新增方法：

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
                if (! GenreTags::isGenreTag($tag)) continue;
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

回應形狀：`{ "tags": [{ "tag": "戀愛", "count": 68 }, ...] }`，永遠基於使用者「完整清單」統計，不接受任何篩選參數。

### 3. `GET /my/anime-list` 支援 `tags` 篩選參數

檔案：`backend/app/Http/Controllers/Api/AnimeListController.php`

`index()` 讀取 `tags` query 參數（逗號分隔字串），傳入 `listForUser()`：

```php
public function index(Request $request): JsonResponse
{
    $tags = array_values(array_filter(explode(',', (string) $request->query('tags', ''))));

    return response()->json([
        'items' => $this->listForUser((int) $request->attributes->get('auth_user_id'), $tags),
    ]);
}

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

- 無 `tags` 參數或空字串 → 行為與現況完全相同（無篩選）。
- `tags=戀愛,戰鬥` → 回傳 `anime.tags` 中「含戀愛或含戰鬥」的清單項目（OR 語意，對應現行 `matchesSelectedTags` 的 `.some()` 行為）。
- `publicList()` 沿用 `listForUser()` 但不傳 `tags`，行為不變（公開分享清單不支援分類篩選，非本次範圍）。

## 前端改動

### `useApi.ts`

```ts
myList: (params?: { tags?: string[] }) => {
  const qs = params?.tags?.length ? `?tags=${encodeURIComponent(params.tags.join(','))}` : ''
  return request(`/my/anime-list${qs}`)
},
myListTags: () => request('/my/anime-list/tags'),
```

### `/list` 頁面（`frontend/app/pages/list/index.vue`）

1. **`tagOptions` 改為後端來源**：
   ```ts
   const tagOptions = ref<TagOption[]>([])
   ```
   在 `loadAll()` 中與 `myList()`／`myCollections()` 一併平行呼叫 `api.myListTags()`，結果存入 `tagOptions.value`。此後不再於前端從 `list.value` 計算。`tagOptions` 只在 mount 時抓取一次，不隨狀態 tab 或已選 tag 重新計算（維持"基於完整清單"的既定行為）。

2. **`filteredList` 拆分**：`applyListFilters()` 移除 tag 篩選部分，只保留狀態 tab 判斷（all/watched/unwatched/col:id），並移除 `selectedTags` 參數：
   ```ts
   const filteredList = computed(() => applyListFilters(list.value, activeFilter.value))
   ```
   `list.value` 本身在有 tag 篩選時，內容就是後端已篩選過的結果（見下）。

3. **新增 tag 觸發的重新查詢**：
   ```ts
   const tagLoading = ref(false)
   let tagRequestId = 0

   watch(selectedTags, async (tags) => {
     const requestId = ++tagRequestId
     tagLoading.value = true
     try {
       const result = tags.length > 0
         ? await api.myList({ tags })
         : await api.myList()
       if (requestId !== tagRequestId) return // 已有更新的請求，捨棄本次結果
       list.value = (result.items || []).map(normalizeListItem)
     } catch (err: any) {
       if (requestId !== tagRequestId) return
       toast.add({ title: err.message || '載入失敗', color: 'error' })
     } finally {
       if (requestId === tagRequestId) tagLoading.value = false
     }
   })
   ```
   - 清除分類（`clearTags()`）會讓 `selectedTags` 變空陣列，觸發同一個 watcher，重新呼叫不帶 `tags` 的 `myList()`，等同還原成完整清單。
   - 初次載入（`selectedTags` 初始為空陣列）不觸發此 watcher 的多餘請求——`watch` 預設不會在初始化時立即執行（無 `immediate: true`），初始資料由 `loadAll()` 負責。

4. **Loading 顯示**：既有 skeleton 區塊的條件從 `v-if="loading"` 改為 `v-if="loading || tagLoading"`，沿用原本 5 條灰色骨架列，不新增樣式。

5. **錯誤處理**：沿用既有 toast 模式，請求失敗時保留畫面上目前顯示的清單不清空。

### `listFilters.ts`

- 移除 `extractTagOptions()`、`matchesSelectedTags()` 匯出（邏輯搬移至後端）。
- `applyListFilters()` 簽章簡化為 `(list: ListItem[], statusFilter: string) => ListItem[]`，只保留原本的 all/watched/unwatched/col:id 判斷，刪除函式尾端的 tag 過濾那行。
- `TagOption` 型別保留（後端回應與前端 `tagOptions` state 仍使用相同形狀 `{ tag: string; count: number }`）。

### 型別

無需新增型別；`TagOption` 沿用既有定義。

## 錯誤處理

- `myListTags()` 於 mount 時失敗 → 沿用 `loadAll()` 既有的 catch，toast 顯示錯誤；`tagOptions` 維持空陣列，分類列（`v-if="tagOptions.length > 0"`）自然不顯示，不影響清單其餘功能。
- 帶 `tags` 參數的 `myList()` 失敗 → 顯示 toast，`list.value` 維持上一次成功的內容（不清空、不跳回未篩選狀態），讓使用者可重新點擊重試。
- 競態：只採用最新發出請求的回應，透過遞增的 `tagRequestId` 比對，較舊回應到達時直接捨棄（含成功與失敗兩種情況）。

## 測試

**後端**（Feature test，`AnimeListControllerTest` 或新檔）：
- `GET /my/anime-list?tags=戀愛` 只回傳 tags 含「戀愛」的項目
- `GET /my/anime-list?tags=戀愛,戰鬥` 回傳 tags 含「戀愛」或「戰鬥」任一者（OR，非 AND）
- `GET /my/anime-list`（無參數）行為與現況相同（回歸測試）
- `GET /my/anime-list/tags` 回傳正確的去重計數，且排除非 genre tag（例如集數、來源類型標籤不出現在結果中）
- `GET /my/anime-list/tags` 排序為 count desc、同 count 時 tag 字母序

**前端**（Vitest）：
- `listFilters.test.ts`：更新／移除 `extractTagOptions`、`matchesSelectedTags` 相關測試案例；新增／保留 `applyListFilters()`（簡化後簽章）的狀態 tab 測試
- 若有針對 `/list` 頁面 watcher 邏輯的測試，補上「選擇 tag 觸發重新查詢」與「快速切換 tag 時只採用最新回應」的案例（可用 mock `api.myList` 搭配可控 resolve 順序驗證競態處理）
