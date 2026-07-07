<script setup lang="ts">
import { normalizeListItem, normalizeCollection } from '../../utils/normalize'
import type { ListItem, Collection } from '../../utils/normalize'
import { applyListFilters, applyTitleSearch, applyListSort } from '../../utils/listFilters'
import type { ListSortKey } from '../../utils/listFilters'
import type { TagOption } from '../../utils/listFilters'
import { tagColor } from '../../utils/normalize'

definePageMeta({ middleware: 'auth' })

useSeoMeta({ robots: 'noindex, nofollow' })

const api = useApi()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const list = ref<ListItem[]>([])
const fullList = ref<ListItem[]>([])
const collections = ref<Collection[]>([])
const tagOptions = ref<TagOption[]>([])
const loading = ref(false)
const tagLoading = ref(false)
let tagRequestId = 0

// Active filter: 'all' | 'watched' | 'unwatched' | 'col:{id}'
const activeFilter = computed(() => (route.query.filter as string) || 'all')

const filterTabs = [
  { value: 'all', label: '全部' },
  { value: 'watched', label: '已看' },
  { value: 'unwatched', label: '未看' },
]

function setFilter(value: string) {
  router.push({ path: '/list', query: value === 'all' ? {} : { filter: value } })
}

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

// 標題搜尋：只存本地狀態（不進 URL），重整理即清空。
const searchQuery = ref('')

// 排序：只存本地狀態。預設「加入日期」。
const sortKey = ref<ListSortKey>('added')

const filteredList = computed(() =>
  applyListSort(
    applyTitleSearch(applyListFilters(list.value, activeFilter.value), searchQuery.value),
    sortKey.value
  )
)

// 卡片內是否有任何可清除的篩選（搜尋詞或已選分類），用來顯示「清除全部篩選」。
const hasActiveFilters = computed(() => searchQuery.value.trim() !== '' || selectedTags.value.length > 0)

// 一鍵清掉搜尋詞與已選分類（狀態切換 all/watched/unwatched 不在此範圍）。
function clearAllFilters() {
  searchQuery.value = ''
  clearTags()
}

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

// ── List operations ──
async function loadAll() {
  loading.value = true
  try {
    const initialTags = selectedTags.value
    // Fire every initial request in parallel — including the tag-filtered list
    // when a `tags` query param is present — instead of waiting for the full
    // list before kicking off the filtered fetch.
    const [listRes, colRes, tagsRes, filteredRes] = await Promise.all([
      api.myList(),
      api.myCollections(),
      api.myListTags(),
      initialTags.length > 0 ? api.myList({ tags: initialTags }) : Promise.resolve(null),
    ])
    const normalizedFullList = (listRes.items || []).map(normalizeListItem)
    fullList.value = normalizedFullList
    collections.value = (colRes.items || []).map(normalizeCollection)
    tagOptions.value = tagsRes.tags || []
    list.value = filteredRes
      ? (filteredRes.items || []).map(normalizeListItem)
      : normalizedFullList
  } catch (err: any) {
    toast.add({ title: err.message || '載入失敗', color: 'error' })
  } finally {
    loading.value = false
  }
}

async function updateItem(item: ListItem, patch: Record<string, any>) {
  try {
    const result = await api.updateListItem(item.id, patch)
    const normalized = normalizeListItem(result.item)
    const index = list.value.findIndex(e => e.id === item.id)
    if (index >= 0) list.value[index] = normalized
    const fullIndex = fullList.value.findIndex(e => e.id === item.id)
    if (fullIndex >= 0) fullList.value[fullIndex] = normalized
    toast.add({ title: '清單已更新', color: 'success' })
  } catch (err: any) {
    toast.add({ title: err.message || '更新失敗', color: 'error' })
  }
}

async function removeItem(item: ListItem) {
  try {
    await api.deleteListItem(item.id)
    list.value = list.value.filter(e => e.id !== item.id)
    fullList.value = fullList.value.filter(e => e.id !== item.id)
    toast.add({ title: '已從清單移除', color: 'neutral' })
  } catch (err: any) {
    toast.add({ title: err.message || '移除失敗', color: 'error' })
  }
}

// ── Collection operations ──
const newColName = ref('')
const creatingCol = ref(false)

async function createCollection() {
  const name = newColName.value.trim()
  if (!name) return
  creatingCol.value = true
  try {
    const result = await api.createCollection(name)
    collections.value.push(normalizeCollection(result.item))
    newColName.value = ''
    toast.add({ title: `已建立「${name}」`, color: 'success' })
  } catch (err: any) {
    toast.add({ title: err.message || '建立失敗', color: 'error' })
  } finally {
    creatingCol.value = false
  }
}

async function deleteCollection(col: Collection) {
  try {
    await api.deleteCollection(col.id)
    collections.value = collections.value.filter(c => c.id !== col.id)
    if (activeFilter.value === `col:${col.id}`) setFilter('all')
    toast.add({ title: `已刪除「${col.name}」`, color: 'neutral' })
  } catch (err: any) {
    toast.add({ title: err.message || '刪除失敗', color: 'error' })
  }
}

async function togglePublic(col: Collection) {
  try {
    const result = await api.updateCollection(col.id, { is_public: !col.isPublic })
    const idx = collections.value.findIndex(c => c.id === col.id)
    if (idx >= 0) collections.value[idx] = normalizeCollection(result.item)
    return true
  } catch (err: any) {
    toast.add({ title: err.message || '更新失敗', color: 'error' })
    return false
  }
}

async function toggleItemInCollection(item: ListItem, col: Collection) {
  const inCol = item.collections.some(c => c.id === col.id)
  try {
    if (inCol) {
      await api.removeFromCollection(col.id, item.id)
      item.collections = item.collections.filter(c => c.id !== col.id)
    } else {
      await api.addToCollection(col.id, item.id)
      item.collections = [...item.collections, { id: col.id, name: col.name }]
    }
    // `item` may be a different object reference than the corresponding entry
    // in `fullList` (populated from a separate fetch/normalize pass), so sync
    // the collections membership there too to keep sidebar/tag data consistent.
    const fullItem = fullList.value.find(e => e.id === item.id)
    if (fullItem && fullItem !== item) fullItem.collections = item.collections
    // Update collection count locally instead of refetching the full list
    const idx = collections.value.findIndex(c => c.id === col.id)
    if (idx >= 0) {
      collections.value[idx].count += inCol ? -1 : 1
    }
  } catch (err: any) {
    toast.add({ title: err.message || '操作失敗', color: 'error' })
  }
}

// Copy share link for a collection
async function copyCollectionLink(col: Collection) {
  // Already public: clicking again switches it back to private, matching
  // the button's tooltip ("公開中，點擊設為私人"). No link to copy in that case.
  if (col.isPublic) {
    const ok = await togglePublic(col)
    if (ok) toast.add({ title: '已設為私人', color: 'neutral' })
    return
  }

  const ok = await togglePublic(col)
  if (!ok) return
  const url = `${window.location.origin}/public/collection/${col.publicSlug}`
  await navigator.clipboard.writeText(url)
  toast.add({ title: '已設為公開，分享連結已複製', color: 'success' })
}

onMounted(loadAll)
</script>

<template>
  <div class="grid gap-6 lg:grid-cols-[220px_1fr]">

    <!-- ── Left: Collections sidebar ── -->
    <aside class="space-y-4">
      <div class="sticky top-24 space-y-3">
        <p class="text-xs font-extrabold uppercase tracking-widest text-gray-400">我的清單</p>

        <!-- Built-in filters -->
        <nav class="space-y-0.5">
          <button
            v-for="tab in filterTabs"
            :key="tab.value"
            class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-semibold transition-colors"
            :class="activeFilter === tab.value
              ? 'bg-primary-50 text-primary-700'
              : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'"
            @click="setFilter(tab.value)"
          >
            <span>{{ tab.label }}</span>
            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-bold text-gray-500">
              {{ tab.value === 'all' ? fullList.length : tab.value === 'watched' ? fullList.filter(i=>i.watched).length : fullList.filter(i=>!i.watched).length }}
            </span>
          </button>
        </nav>

        <!-- Divider -->
        <div class="border-t border-gray-100 pt-3">
          <p class="mb-2 text-xs font-extrabold uppercase tracking-widest text-gray-400">自訂清單</p>

          <!-- Collection list -->
          <nav class="space-y-0.5">
            <div
              v-for="col in collections"
              :key="col.id"
              class="group flex w-full items-center gap-1 rounded-lg px-3 py-2 text-sm font-semibold transition-colors"
              :class="activeFilter === `col:${col.id}`
                ? 'bg-primary-50 text-primary-700'
                : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'"
            >
              <button class="flex flex-1 items-center justify-between" @click="setFilter(`col:${col.id}`)">
                <span class="truncate">{{ col.name }}</span>
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-bold text-gray-500">{{ col.count }}</span>
              </button>
              <!-- Actions -->
              <div class="flex shrink-0 items-center gap-0.5 opacity-0 transition-opacity group-hover:opacity-100">
                <button
                  :title="col.isPublic ? '公開中（點擊設為私人）' : '設為公開並複製連結'"
                  :aria-label="col.isPublic ? `${col.name}：公開中，點擊設為私人` : `${col.name}：設為公開並複製連結`"
                  class="rounded p-1 hover:bg-gray-200"
                  @click.stop="copyCollectionLink(col)"
                >
                  <UIcon :name="col.isPublic ? 'i-lucide-globe' : 'i-lucide-link'" class="size-3.5 text-gray-500" />
                </button>
                <button
                  title="刪除清單"
                  :aria-label="`刪除清單「${col.name}」`"
                  class="rounded p-1 hover:bg-red-50"
                  @click.stop="deleteCollection(col)"
                >
                  <UIcon name="i-lucide-trash-2" class="size-3.5 text-gray-400 hover:text-red-500" />
                </button>
              </div>
            </div>
          </nav>

          <!-- New collection input -->
          <form class="mt-2 flex gap-1.5" @submit.prevent="createCollection">
            <label for="new-collection-name" class="sr-only">新增清單名稱</label>
            <input
              id="new-collection-name"
              v-model="newColName"
              maxlength="80"
              placeholder="新增清單…"
              class="min-w-0 flex-1 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-900 placeholder:text-gray-400 outline-none transition focus:border-primary-400 focus:ring-2 focus:ring-primary-100"
            />
            <button
              type="submit"
              :disabled="!newColName.trim() || creatingCol"
              class="rounded-lg bg-primary-600 px-2.5 py-1.5 text-sm font-bold text-white transition hover:bg-primary-500 disabled:opacity-40"
            >
              <UIcon name="i-lucide-plus" class="size-4" />
            </button>
          </form>
        </div>
      </div>
    </aside>

    <!-- ── Right: List items ── -->
    <div class="space-y-5">
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

      <!-- 搜尋 + 排序 + 分類卡片：與資料庫頁模板一致——左排序下拉、中搜尋框、
           右綠色搜尋鈕；分類 chip 以分隔線區隔。搜尋為即時過濾，搜尋鈕純裝飾對齊
           （submit 不觸發查詢）。「清除全部篩選」一鍵清搜尋＋分類。 -->
      <div class="space-y-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
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
        </div>
      </div>

      <div v-if="loading || tagLoading" class="space-y-3">
        <div v-for="i in 5" :key="i" class="h-20 w-full animate-pulse rounded-xl bg-gray-200" />
      </div>

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

      <TransitionGroup v-else tag="div" name="list-item" class="space-y-3">
        <ListItemRow
          v-for="item in filteredList"
          :key="item.id"
          :item="item"
          :collections="collections"
          :disabled="loading"
          @update="patch => updateItem(item, patch)"
          @remove="removeItem(item)"
          @toggle-collection="(col) => toggleItemInCollection(item, col)"
        />
      </TransitionGroup>
    </div>
  </div>
</template>

<style scoped>
.list-item-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
  position: absolute;
  width: 100%;
}
.list-item-leave-to {
  opacity: 0;
  transform: translateX(16px);
}
.list-item-move {
  transition: transform 0.2s ease;
}
</style>
