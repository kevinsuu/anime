<script setup lang="ts">
import { normalizeListItem, normalizeCollection } from '../../utils/normalize'
import type { ListItem, Collection } from '../../utils/normalize'
import { applyListFilters, applyTagFilters, applyTitleSearch, applyListSort } from '../../utils/listFilters'
import type { ListSortKey } from '../../utils/listFilters'
import type { TagOption } from '../../utils/listFilters'
import { tagColor } from '../../utils/normalize'
import type { ListItemPatch } from '../../types/api'
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

// Active filter: 'all' | 'watched' | 'unwatched' | 'col:{id}'
const activeFilter = computed(() => (route.query.filter as string) || 'all')

const filterTabs = [
  { value: 'all', label: '全部' },
  { value: 'watched', label: '已看' },
  { value: 'unwatched', label: '未看' },
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
    applyTitleSearch(
      applyListFilters(applyTagFilters(list.value, selectedTags.value), activeFilter.value),
      searchQuery.value
    ),
    sortKey.value
  )
)

const listCounts = computed(() => ({
  all: list.value.length,
  watched: list.value.filter(item => item.watched).length,
  unwatched: list.value.filter(item => !item.watched).length
}))

// 卡片內是否有任何可清除的篩選（搜尋詞或已選分類），用來顯示「清除全部篩選」。
const hasActiveFilters = computed(() => searchQuery.value.trim() !== '' || selectedTags.value.length > 0)

// 一鍵清掉搜尋詞與已選分類（狀態切換 all/watched/unwatched 不在此範圍）。
function clearAllFilters() {
  searchQuery.value = ''
  clearTags()
}

// ── List operations ──
async function loadAll() {
  loading.value = true
  try {
    const [listRes, colRes, tagsRes] = await Promise.all([
      api.myList(),
      api.myCollections(),
      api.myListTags()
    ])
    list.value = (listRes.items || []).map(normalizeListItem)
    collections.value = (colRes.items || []).map(normalizeCollection)
    tagOptions.value = tagsRes.tags || []
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
    toast.add({ title: '清單已更新', color: 'success' })
  } catch (err: unknown) {
    Object.assign(item, previous)
    toast.add({ title: apiErrorMessage(err, '更新失敗'), color: 'error' })
  }
}

async function removeItem(item: ListItem) {
  try {
    await api.deleteListItem(item.id)
    list.value = list.value.filter(e => e.id !== item.id)
    toast.add({ title: '已從清單移除', color: 'neutral' })
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

onMounted(loadAll)

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
        <p class="mt-1 text-xs font-medium text-gray-400">同步收藏、觀看進度與自訂清單</p>
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
    <div class="min-w-0 space-y-5">
      <header class="space-y-1">
        <p class="text-xs font-extrabold uppercase tracking-widest text-primary-600">追番清單</p>
        <div class="flex min-w-0 items-center justify-between gap-4">
          <h1 class="min-w-0 truncate text-3xl font-extrabold tracking-tight text-gray-950">
            {{ activeFilter.startsWith('col:')
              ? collections.find(c => c.id === Number(activeFilter.slice(4)))?.name ?? '清單'
              : '我的清單' }}
          </h1>
          <span class="shrink-0 text-sm text-gray-500">共 {{ filteredList.length }} 部</span>
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
          title="自訂清單"
          description="選擇清單，或管理公開分享與刪除設定。"
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
              {{ activeCollection ? `自訂清單：${activeCollection.name}` : '選擇與管理自訂清單' }}
            </span>
            <span class="shrink-0 text-xs font-bold text-gray-400">{{ collections.length }}</span>
            <UIcon name="i-lucide-chevron-up" class="size-4 shrink-0 text-gray-400" />
          </button>

          <template #body>
            <div class="space-y-5">
              <nav v-if="collections.length > 0" aria-label="自訂清單" class="space-y-2">
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
                    :aria-label="`刪除清單「${col.name}」`"
                    class="grid min-h-11 place-items-center border-l border-gray-100 text-red-500 transition active:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-red-500 disabled:opacity-40"
                    @click="requestCollectionDelete(col)"
                  >
                    <UIcon name="i-lucide-trash-2" class="size-4" />
                  </button>
                </div>
              </nav>

              <p v-else class="rounded-xl bg-gray-50 px-4 py-4 text-sm text-gray-500">
                尚未建立自訂清單。你可以先在下方新增一個。
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
                <p class="mt-1 text-xs leading-5 text-red-700">清單會被刪除，但作品仍會保留在「我的清單」。</p>
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
                <label for="mobile-new-collection-name" class="mb-2 block text-sm font-bold text-gray-900">新增自訂清單</label>
                <div class="grid grid-cols-[minmax(0,1fr)_44px] gap-2">
                  <input
                    id="mobile-new-collection-name"
                    v-model="newColName"
                    maxlength="80"
                    placeholder="輸入清單名稱"
                    class="min-h-11 min-w-0 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-primary-400 focus:ring-2 focus:ring-primary-100"
                  />
                  <button
                    type="submit"
                    :disabled="!newColName.trim() || creatingCol"
                    aria-label="新增自訂清單"
                    class="grid min-h-11 place-items-center rounded-lg bg-primary-600 text-white transition active:bg-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-40"
                  >
                    <UIcon name="i-lucide-plus" class="size-5" />
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

      <!-- 搜尋 + 排序 + 分類卡片：與資料庫頁模板一致——左排序下拉、中搜尋框、
           右綠色搜尋鈕；分類 chip 以分隔線區隔。搜尋為即時過濾，搜尋鈕純裝飾對齊
           （submit 不觸發查詢）。「清除全部篩選」一鍵清搜尋＋分類。 -->
      <div class="min-w-0 max-w-full space-y-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-center">
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
          <form class="flex min-w-0 flex-1 gap-2" @submit.prevent>
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

      <div v-if="filteredList.length === 0 && searchQuery.trim() !== ''" class="rounded-xl border border-dashed border-gray-200 p-8 text-center text-gray-500">
        <UIcon name="i-lucide-search-x" class="mx-auto mb-2 size-8 text-gray-300" />
        <p class="text-sm font-medium">找不到符合「{{ searchQuery.trim() }}」的作品</p>
        <button type="button" class="mt-3 inline-block text-xs font-semibold text-primary-600 hover:underline" @click="searchQuery = ''">清除搜尋</button>
      </div>

      <div v-else-if="filteredList.length === 0" class="rounded-xl border border-dashed border-gray-200 p-8 text-center text-gray-500">
        <UIcon name="i-lucide-inbox" class="mx-auto mb-2 size-8 text-gray-300" />
        <p class="text-sm font-medium">這裡還沒有作品</p>
        <NuxtLink to="/seasonal" class="mt-3 inline-block text-xs font-semibold text-primary-600 hover:underline">去新番表加入作品</NuxtLink>
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
