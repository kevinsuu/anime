<script setup lang="ts">
import { normalizeListItem, normalizeCollection } from '../../utils/normalize'
import type { ListItem, Collection } from '../../utils/normalize'
import type { TagOption } from '../../utils/listFilters'
import { tagColor } from '../../utils/normalize'
import type { AnimeListFilters, AnimeListSort, AnimeSummaryMeta, ListItemPatch } from '../../types/api'
import { apiErrorMessage } from '../../utils/apiError'

definePageMeta({ middleware: 'auth' })

useSeoMeta({ robots: 'noindex, nofollow' })

const api = useApi()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const list = ref<ListItem[]>([])
const collections = ref<Collection[]>([])
const tagOptions = ref<TagOption[]>([])
const loading = ref(true)
const PAGE_SIZE = 50
const listMeta = ref<AnimeSummaryMeta>({
  page: 1,
  per_page: PAGE_SIZE,
  total: 0,
  last_page: 1,
  has_more: false
})
const listCounts = ref({ all: 0, watched: 0, unwatched: 0 })

function routeString(value: unknown): string {
  return typeof value === 'string' ? value : ''
}

function routePositiveInteger(value: unknown, fallback = 1): number {
  const parsed = Number(routeString(value))
  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback
}

function routeSort(value: unknown): AnimeListSort {
  const sort = routeString(value)
  return sort === 'added' || sort === 'year' || sort === 'airDate' ? sort : 'airDate'
}

// Active filter: 'all' | 'watched' | 'unwatched' | 'col:{id}'
const activeFilter = computed(() => routeString(route.query.filter) || 'all')
const page = computed(() => routePositiveInteger(route.query.page))
const totalPages = computed(() => Math.max(1, listMeta.value.last_page))

const filterTabs = [
  { value: 'all', label: '全部收藏' },
  { value: 'watched', label: '已看完' },
  { value: 'unwatched', label: '收藏未看' },
]

const mobileCollectionsOpen = ref(false)
const collectionPendingDelete = ref<Collection | null>(null)
const deletingCollectionId = ref<number | null>(null)
const deleteConfirmationRef = ref<HTMLElement | null>(null)

const activeCollection = computed(() => {
  if (!activeFilter.value.startsWith('col:')) return null
  const collectionId = Number(activeFilter.value.slice(4))
  return collections.value.find(collection => collection.id === collectionId) ?? null
})

function setFilter(value: string) {
  const query = { ...route.query }
  if (value === 'all') delete query.filter
  else query.filter = value
  delete query.page
  router.push({ path: '/list', query })
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
  delete query.page

  router.push({ path: '/list', query })
}

function clearTags() {
  const query = { ...route.query }
  delete query.tags
  delete query.page
  router.push({ path: '/list', query })
}

const searchQuery = ref(routeString(route.query.q))

// 預設以實際播出日期由新到舊；分頁前由後端完成排序。
const sortKey = ref<AnimeListSort>(routeSort(route.query.sort))

const filteredList = computed(() => list.value)

// 卡片內是否有任何可清除的篩選（搜尋詞或已選分類），用來顯示「清除全部篩選」。
const hasActiveFilters = computed(() => routeString(route.query.q).trim() !== '' || selectedTags.value.length > 0)

// 一鍵清掉搜尋詞與已選分類（狀態切換 all/watched/unwatched 不在此範圍）。
function clearAllFilters() {
  searchQuery.value = ''
  const query = { ...route.query }
  delete query.q
  delete query.tags
  delete query.page
  router.push({ path: '/list', query })
}

// ── List operations ──
let listRequestId = 0

function listRequestFilters(): AnimeListFilters {
  const filters: AnimeListFilters = {
    page: page.value,
    q: routeString(route.query.q).trim() || undefined,
    tags: selectedTags.value,
    sort: sortKey.value
  }
  if (activeFilter.value === 'watched' || activeFilter.value === 'unwatched') {
    filters.status = activeFilter.value
  } else if (activeFilter.value.startsWith('col:')) {
    const collectionId = Number(activeFilter.value.slice(4))
    if (Number.isInteger(collectionId) && collectionId > 0) filters.collectionId = collectionId
  }
  return filters
}

async function loadList() {
  const requestId = ++listRequestId
  loading.value = true
  try {
    const result = await api.myList(listRequestFilters())
    if (requestId !== listRequestId) return
    if (page.value > result.meta.last_page) {
      const query = { ...route.query }
      if (result.meta.last_page > 1) query.page = String(result.meta.last_page)
      else delete query.page
      await router.replace({ path: '/list', query })
      return
    }
    list.value = (result.items || []).map(normalizeListItem)
    listMeta.value = result.meta
  } catch (err: unknown) {
    if (requestId !== listRequestId) return
    list.value = []
    toast.add({ title: apiErrorMessage(err, '載入失敗'), color: 'error' })
  } finally {
    if (requestId === listRequestId) loading.value = false
  }
}

async function loadCounts() {
  const result = await api.myListCounts()
  listCounts.value = result.counts
}

async function loadCollections() {
  const result = await api.myCollections()
  collections.value = (result.items || []).map(normalizeCollection)
}

async function loadAll() {
  loading.value = true
  try {
    const [listRes, countsRes, colRes, tagsRes] = await Promise.all([
      api.myList(listRequestFilters()),
      api.myListCounts(),
      api.myCollections(),
      api.myListTags()
    ])
    list.value = (listRes.items || []).map(normalizeListItem)
    listMeta.value = listRes.meta
    listCounts.value = countsRes.counts
    collections.value = (colRes.items || []).map(normalizeCollection)
    tagOptions.value = tagsRes.tags || []
    if (page.value > listRes.meta.last_page) {
      const query = { ...route.query }
      if (listRes.meta.last_page > 1) query.page = String(listRes.meta.last_page)
      else delete query.page
      await router.replace({ path: '/list', query })
    }
  } catch (err: unknown) {
    toast.add({ title: apiErrorMessage(err, '載入失敗'), color: 'error' })
  } finally {
    loading.value = false
  }
}

async function updateItem(item: ListItem, patch: ListItemPatch) {
  const previous: ListItemPatch = {}
  if (patch.watched !== undefined) previous.watched = item.watched
  if ('rating' in patch) previous.rating = item.rating
  if (patch.note !== undefined) previous.note = item.note
  Object.assign(item, patch)
  try {
    const result = await api.updateListItem(item.id, patch)
    const normalized = normalizeListItem(result.item)
    const index = list.value.findIndex(e => e.id === item.id)
    if (index >= 0) list.value[index] = normalized
    if (patch.watched !== undefined && previous.watched !== normalized.watched) {
      const watchedDelta = normalized.watched ? 1 : -1
      listCounts.value.watched += watchedDelta
      listCounts.value.unwatched -= watchedDelta
      if (activeFilter.value === 'watched' || activeFilter.value === 'unwatched') await loadList()
    }
    toast.add({ title: '清單已更新', color: 'success' })
  } catch (err: unknown) {
    Object.assign(item, previous)
    toast.add({ title: apiErrorMessage(err, '更新失敗'), color: 'error' })
  }
}

async function removeItem(item: ListItem) {
  try {
    await api.deleteListItem(item.id)
    list.value = list.value.filter(entry => entry.id !== item.id)
    listMeta.value.total = Math.max(0, listMeta.value.total - 1)
    listCounts.value.all = Math.max(0, listCounts.value.all - 1)
    const statusKey = item.watched ? 'watched' : 'unwatched'
    listCounts.value[statusKey] = Math.max(0, listCounts.value[statusKey] - 1)
    for (const membership of item.collections) {
      const collection = collections.value.find(entry => entry.id === membership.id)
      if (collection) collection.count = Math.max(0, collection.count - 1)
    }
    toast.add({ title: '已從清單移除', color: 'neutral' })
    await Promise.allSettled([loadList(), loadCounts(), loadCollections()])
  } catch (err: unknown) {
    toast.add({ title: apiErrorMessage(err, '移除失敗'), color: 'error' })
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
  } catch (err: unknown) {
    toast.add({ title: apiErrorMessage(err, '建立失敗'), color: 'error' })
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
  } catch (err: unknown) {
    toast.add({ title: apiErrorMessage(err, '刪除失敗'), color: 'error' })
  }
}

function selectMobileCollection(col: Collection) {
  setFilter(`col:${col.id}`)
  mobileCollectionsOpen.value = false
}

function requestCollectionDelete(col: Collection) {
  collectionPendingDelete.value = col
  nextTick(() => deleteConfirmationRef.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }))
}

async function confirmCollectionDelete() {
  const collection = collectionPendingDelete.value
  if (!collection || deletingCollectionId.value !== null) return

  deletingCollectionId.value = collection.id
  try {
    await deleteCollection(collection)
    collectionPendingDelete.value = null
  } finally {
    deletingCollectionId.value = null
  }
}

async function togglePublic(col: Collection) {
  try {
    const result = await api.updateCollection(col.id, { is_public: !col.isPublic })
    const idx = collections.value.findIndex(c => c.id === col.id)
    if (idx >= 0) collections.value[idx] = normalizeCollection(result.item)
    return true
  } catch (err: unknown) {
    toast.add({ title: apiErrorMessage(err, '更新失敗'), color: 'error' })
    return false
  }
}

async function toggleItemInCollection(item: ListItem, col: Collection) {
  const inCol = item.collections.some(c => c.id === col.id)
  const previousCollections = [...item.collections]
  const targetCollection = collections.value.find(entry => entry.id === col.id)
  const previousCount = targetCollection?.count ?? null

  item.collections = inCol
    ? item.collections.filter(c => c.id !== col.id)
    : [...item.collections, { id: col.id, name: col.name }]
  if (targetCollection) targetCollection.count += inCol ? -1 : 1

  try {
    if (inCol) {
      await api.removeFromCollection(col.id, item.id)
    } else {
      await api.addToCollection(col.id, item.id)
    }
    if (activeFilter.value === `col:${col.id}` && inCol) await loadList()
  } catch (err: unknown) {
    item.collections = previousCollections
    if (targetCollection && previousCount !== null) targetCollection.count = previousCount
    toast.add({ title: apiErrorMessage(err, '操作失敗'), color: 'error' })
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

async function applySearch() {
  const query = { ...route.query }
  const value = searchQuery.value.trim()
  if (value) query.q = value
  else delete query.q
  delete query.page
  await router.push({ path: '/list', query })
}

async function changeSort() {
  const query = { ...route.query }
  if (sortKey.value === 'airDate') delete query.sort
  else query.sort = sortKey.value
  delete query.page
  await router.push({ path: '/list', query })
}

async function changePage(nextPage: number) {
  const normalizedPage = Math.min(Math.max(nextPage, 1), totalPages.value)
  if (normalizedPage === page.value) return
  const query = { ...route.query }
  if (normalizedPage === 1) delete query.page
  else query.page = String(normalizedPage)
  await router.push({ path: '/list', query })
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

onMounted(loadAll)

watch(() => route.query, async () => {
  searchQuery.value = routeString(route.query.q)
  sortKey.value = routeSort(route.query.sort)
  await loadList()
}, { deep: true })

watch(mobileCollectionsOpen, (open) => {
  if (!open) collectionPendingDelete.value = null
})
</script>

<template>
  <div class="relative grid w-full min-w-0 max-w-full gap-6 lg:grid-cols-[clamp(180px,20vw,220px)_minmax(0,1fr)]">
    <div
      v-if="loading"
      class="fixed inset-0 z-40 grid place-items-center bg-white/80 backdrop-blur-sm"
      role="status"
      aria-live="polite"
      aria-label="正在載入我的清單"
    >
      <div class="list-loading-card flex min-w-64 flex-col items-center rounded-3xl border border-emerald-100 bg-white/95 px-9 py-8 shadow-2xl shadow-emerald-950/10">
        <div class="list-loading-orbit relative grid size-20 place-items-center" aria-hidden="true">
          <span class="absolute inset-0 rounded-full border-2 border-emerald-100" />
          <span class="list-loading-ring absolute inset-0 rounded-full border-2 border-transparent border-t-emerald-500 border-r-emerald-300" />
          <span class="list-loading-ring-reverse absolute inset-2 rounded-full border border-transparent border-b-emerald-300" />
          <img src="/favicon-180.png" alt="" class="size-11 rounded-xl object-cover shadow-lg shadow-emerald-500/20" />
          <span class="list-loading-dot absolute -right-0.5 top-8 size-2.5 rounded-full bg-emerald-500 ring-4 ring-white" />
        </div>
        <p class="mt-5 text-base font-extrabold tracking-tight text-gray-900">正在整理你的收藏櫃</p>
        <p class="mt-1 text-xs font-medium text-gray-400">同步收藏、觀看進度與收藏分類</p>
        <div class="mt-4 flex gap-1.5" aria-hidden="true">
          <span v-for="i in 3" :key="i" class="list-loading-pip size-1.5 rounded-full bg-emerald-400" :style="{ animationDelay: `${(i - 1) * 160}ms` }" />
        </div>
      </div>
    </div>

    <!-- ── Left: Collections sidebar ── -->
    <aside class="hidden min-w-0 space-y-4 md:block">
      <div class="sticky top-24 min-w-0 space-y-3">
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
              {{ listCounts[tab.value as keyof typeof listCounts] }}
            </span>
          </button>
        </nav>

        <!-- Divider -->
        <div class="border-t border-gray-100 pt-3">
          <p class="mb-2 text-xs font-extrabold uppercase tracking-widest text-gray-400">收藏分類</p>

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
              <button class="flex min-w-0 flex-1 items-center justify-between" @click="setFilter(`col:${col.id}`)">
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
                  title="刪除收藏分類"
                  :aria-label="`刪除收藏分類「${col.name}」`"
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
            <label for="new-collection-name" class="sr-only">新增收藏分類名稱</label>
            <input
              id="new-collection-name"
              v-model="newColName"
              maxlength="80"
              placeholder="新增收藏分類名稱…"
              class="min-w-0 flex-1 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-900 placeholder:text-gray-400 outline-none transition focus:border-primary-400 focus:ring-2 focus:ring-primary-100"
            />
            <button
              type="submit"
              :disabled="!newColName.trim() || creatingCol"
              class="shrink-0 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-bold text-white transition hover:bg-primary-500 disabled:opacity-40"
            >
              新增
            </button>
          </form>
        </div>
      </div>
    </aside>

    <!-- ── Right: List items ── -->
    <div class="min-w-0 space-y-5">
      <header class="space-y-1">
        <p class="text-xs font-extrabold uppercase tracking-widest text-primary-600">追番清單</p>
        <div class="flex min-w-0 items-center justify-between gap-4">
          <h1 class="min-w-0 truncate text-3xl font-extrabold tracking-tight text-gray-950">
            {{ activeFilter.startsWith('col:')
              ? collections.find(c => c.id === Number(activeFilter.slice(4)))?.name ?? '清單'
              : '我的清單' }}
          </h1>
          <span class="shrink-0 text-sm text-gray-500">共 {{ listMeta.total }} 部</span>
        </div>
      </header>

      <!-- Mobile list navigation: keep the primary watch states one tap away and
           move collection management into an accessible bottom sheet. -->
      <div class="space-y-3 md:hidden">
        <nav
          aria-label="清單觀看狀態"
          class="grid grid-cols-3 rounded-xl border border-gray-200 bg-gray-50 p-1 shadow-sm"
        >
          <button
            v-for="tab in filterTabs"
            :key="tab.value"
            type="button"
            class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg px-2 text-sm font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-1"
            :class="activeFilter === tab.value
              ? 'bg-white text-primary-700 shadow-sm ring-1 ring-gray-200'
              : 'text-gray-500 active:bg-white/70'"
            :aria-pressed="activeFilter === tab.value"
            @click="setFilter(tab.value)"
          >
            <span>{{ tab.label }}</span>
            <span class="text-[11px] opacity-60">
              {{ listCounts[tab.value as keyof typeof listCounts] }}
            </span>
          </button>
        </nav>

        <USlideover
          v-model:open="mobileCollectionsOpen"
          side="bottom"
          title="收藏分類"
          description="將收藏作品整理到自訂分類，並管理公開分享與刪除設定。"
          :ui="{
            content: 'max-h-[88dvh] rounded-t-3xl',
            body: 'overflow-y-auto px-4 sm:px-6',
            close: 'size-11',
            footer: 'border-t border-gray-100 px-4 pb-[calc(1rem+env(safe-area-inset-bottom))] pt-3 sm:px-6'
          }"
        >
          <button
            type="button"
            class="flex min-h-11 w-full items-center gap-3 rounded-xl border border-gray-200 bg-white px-3 text-left text-sm font-semibold text-gray-700 shadow-sm transition active:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
            aria-haspopup="dialog"
          >
            <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-primary-50 text-primary-700">
              <UIcon name="i-lucide-library" class="size-4" />
            </span>
            <span class="min-w-0 flex-1 truncate">
              {{ activeCollection ? `收藏分類：${activeCollection.name}` : '選擇與管理收藏分類' }}
            </span>
            <span class="shrink-0 text-xs font-bold text-gray-400">{{ collections.length }}</span>
            <UIcon name="i-lucide-chevron-up" class="size-4 shrink-0 text-gray-400" />
          </button>

          <template #body>
            <div class="space-y-5">
              <nav v-if="collections.length > 0" aria-label="收藏分類" class="space-y-2">
                <div
                  v-for="col in collections"
                  :key="col.id"
                  class="grid min-w-0 grid-cols-[minmax(0,1fr)_44px_44px] overflow-hidden rounded-xl border bg-white shadow-sm"
                  :class="activeFilter === `col:${col.id}` ? 'border-primary-300 ring-1 ring-primary-100' : 'border-gray-200'"
                >
                  <button
                    type="button"
                    class="flex min-h-11 min-w-0 items-center gap-3 px-3 text-left transition active:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500"
                    :aria-current="activeFilter === `col:${col.id}` ? 'page' : undefined"
                    @click="selectMobileCollection(col)"
                  >
                    <UIcon
                      :name="activeFilter === `col:${col.id}` ? 'i-lucide-folder-open' : 'i-lucide-folder'"
                      class="size-4 shrink-0 text-primary-600"
                    />
                    <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900">{{ col.name }}</span>
                    <span class="shrink-0 text-xs font-bold text-gray-400">{{ col.count }}</span>
                  </button>
                  <button
                    type="button"
                    :title="col.isPublic ? '公開中（點擊設為私人）' : '設為公開並複製連結'"
                    :aria-label="col.isPublic ? `${col.name}：公開中，點擊設為私人` : `${col.name}：設為公開並複製連結`"
                    class="grid min-h-11 place-items-center border-l border-gray-100 text-gray-500 transition active:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500"
                    @click="copyCollectionLink(col)"
                  >
                    <UIcon :name="col.isPublic ? 'i-lucide-globe' : 'i-lucide-link'" class="size-4" />
                  </button>
                  <button
                    type="button"
                    :disabled="deletingCollectionId !== null"
                    :aria-label="`刪除收藏分類「${col.name}」`"
                    class="grid min-h-11 place-items-center border-l border-gray-100 text-red-500 transition active:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-red-500 disabled:opacity-40"
                    @click="requestCollectionDelete(col)"
                  >
                    <UIcon name="i-lucide-trash-2" class="size-4" />
                  </button>
                </div>
              </nav>

              <p v-else class="rounded-xl bg-gray-50 px-4 py-4 text-sm text-gray-500">
                尚未建立收藏分類。你可以先在下方新增一個。
              </p>

              <section
                v-if="collectionPendingDelete"
                ref="deleteConfirmationRef"
                aria-labelledby="mobile-delete-collection-title"
                class="rounded-xl border border-red-200 bg-red-50 p-4"
                role="alert"
              >
                <h3 id="mobile-delete-collection-title" class="text-sm font-bold text-red-800">
                  刪除「{{ collectionPendingDelete.name }}」？
                </h3>
                <p class="mt-1 text-xs leading-5 text-red-700">分類會被刪除，但作品仍會保留在「我的清單」。</p>
                <div class="mt-3 grid grid-cols-2 gap-2">
                  <button
                    type="button"
                    :disabled="deletingCollectionId !== null"
                    class="min-h-11 rounded-lg border border-red-200 bg-white px-3 text-sm font-bold text-red-700 transition active:bg-red-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 disabled:opacity-40"
                    @click="collectionPendingDelete = null"
                  >
                    取消
                  </button>
                  <button
                    type="button"
                    :disabled="deletingCollectionId !== null"
                    class="min-h-11 rounded-lg bg-red-600 px-3 text-sm font-bold text-white transition active:bg-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:opacity-40"
                    @click="confirmCollectionDelete"
                  >
                    {{ deletingCollectionId === collectionPendingDelete.id ? '刪除中…' : '確認刪除' }}
                  </button>
                </div>
              </section>

              <form class="border-t border-gray-100 pt-5" @submit.prevent="createCollection">
                <label for="mobile-new-collection-name" class="mb-2 block text-sm font-bold text-gray-900">新增收藏分類</label>
                <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-2">
                  <input
                    id="mobile-new-collection-name"
                    v-model="newColName"
                    maxlength="80"
                    placeholder="輸入收藏分類名稱"
                    class="min-h-11 min-w-0 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-primary-400 focus:ring-2 focus:ring-primary-100"
                  />
                  <button
                    type="submit"
                    :disabled="!newColName.trim() || creatingCol"
                    class="min-h-11 rounded-lg bg-primary-600 px-4 text-sm font-bold text-white transition active:bg-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-40"
                  >
                    新增
                  </button>
                </div>
              </form>
            </div>
          </template>

          <template #footer="{ close }">
            <button
              type="button"
              class="min-h-11 w-full rounded-lg bg-primary-600 px-4 text-sm font-bold text-white shadow-sm transition active:bg-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
              @click="close"
            >
              完成
            </button>
          </template>
        </USlideover>
      </div>

      <!-- 搜尋 + 排序 + 分類卡片：查詢交由後端在分頁前套用，避免只篩選當頁資料。 -->
      <div class="min-w-0 max-w-full space-y-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-center">
          <!-- 排序下拉（對齊 catalog 左控制項位置） -->
          <div class="shrink-0">
            <label for="list-sort" class="sr-only">排序方式</label>
            <select
              id="list-sort"
              v-model="sortKey"
              @change="changeSort"
              class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm font-semibold text-gray-700 shadow-sm outline-none transition focus:border-primary-400 focus:ring-2 focus:ring-primary-100 sm:w-auto"
            >
              <option value="airDate">播出日期</option>
              <option value="year">年份</option>
              <option value="added">加入日期</option>
            </select>
          </div>

          <!-- 搜尋框 + 綠色搜尋鈕 -->
          <form class="flex min-w-0 flex-1 gap-2" @submit.prevent="applySearch">
            <div class="relative min-w-0 flex-1">
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
                class="min-w-0 w-full rounded-lg border border-gray-200 bg-white py-2.5 pl-9 pr-4 text-sm text-gray-900 placeholder:text-gray-400 shadow-sm outline-none transition focus:border-primary-400 focus:ring-2 focus:ring-primary-100"
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

        <div v-if="tagOptions.length > 0" class="flex min-w-0 flex-wrap items-center gap-1.5 border-t border-gray-100 pt-3">
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

      <div v-if="filteredList.length === 0 && routeString(route.query.q).trim() !== ''" class="rounded-xl border border-dashed border-gray-200 p-8 text-center text-gray-500">
        <UIcon name="i-lucide-search-x" class="mx-auto mb-2 size-8 text-gray-300" />
        <p class="text-sm font-medium">找不到符合「{{ routeString(route.query.q).trim() }}」的作品</p>
        <button type="button" class="mt-3 inline-block text-xs font-semibold text-primary-600 hover:underline" @click="clearAllFilters">清除搜尋</button>
      </div>

      <div v-else-if="filteredList.length === 0" class="rounded-xl border border-dashed border-gray-200 p-8 text-center text-gray-500">
        <UIcon name="i-lucide-inbox" class="mx-auto mb-2 size-8 text-gray-300" />
        <p class="text-sm font-medium">這裡還沒有作品</p>
        <NuxtLink to="/" class="mt-3 inline-block text-xs font-semibold text-primary-600 hover:underline">去新番表加入作品</NuxtLink>
      </div>

      <TransitionGroup v-else tag="div" name="list-item" class="min-w-0 space-y-3">
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

      <nav v-if="totalPages > 1" aria-label="清單分頁" class="flex items-center justify-center gap-2 pt-2">
        <button
          type="button"
          :disabled="page === 1 || loading"
          class="flex h-11 w-11 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 disabled:opacity-40 md:h-9 md:w-9"
          aria-label="上一頁"
          @click="changePage(page - 1)"
        >
          <UIcon name="i-lucide-chevron-left" class="size-4" />
        </button>
        <span class="min-w-20 text-center text-sm font-semibold text-gray-700">
          {{ page }} / {{ totalPages }}
        </span>
        <button
          type="button"
          :disabled="page === totalPages || loading"
          class="flex h-11 w-11 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 disabled:opacity-40 md:h-9 md:w-9"
          aria-label="下一頁"
          @click="changePage(page + 1)"
        >
          <UIcon name="i-lucide-chevron-right" class="size-4" />
        </button>
      </nav>
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

.list-loading-card {
  animation: loading-card-arrive 0.35s ease-out both;
}

.list-loading-ring {
  animation: list-loading-spin 1s linear infinite;
}

.list-loading-ring-reverse {
  animation: list-loading-spin 1.5s linear infinite reverse;
}

.list-loading-dot {
  animation: list-loading-pulse 1.2s ease-in-out infinite;
}

.list-loading-pip {
  animation: list-loading-pip 1s ease-in-out infinite;
}

@keyframes list-loading-spin {
  to { transform: rotate(360deg); }
}

@keyframes list-loading-pulse {
  50% { transform: scale(0.72); opacity: 0.55; }
}

@keyframes list-loading-pip {
  0%, 100% { transform: translateY(0); opacity: 0.35; }
  50% { transform: translateY(-3px); opacity: 1; }
}

@keyframes loading-card-arrive {
  from { transform: translateY(8px) scale(0.98); opacity: 0; }
  to { transform: translateY(0) scale(1); opacity: 1; }
}

@media (prefers-reduced-motion: reduce) {
  .list-loading-card,
  .list-loading-ring,
  .list-loading-ring-reverse,
  .list-loading-dot,
  .list-loading-pip {
    animation-duration: 0.01ms;
    animation-iteration-count: 1;
  }
}
</style>
