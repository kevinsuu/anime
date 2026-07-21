<script setup lang="ts">
import { normalizeAnimeSummary, tagColor } from '../utils/normalize'
import type { AnimeSummary } from '../utils/normalize'
import { apiErrorMessage } from '../utils/apiError'
import { HIGH_PRIORITY_IMAGE_COUNT } from '../composables/useLazyLoad'

const api = useApi()
const toast = useToast()
const route = useRoute()
const router = useRouter()

function routeString(value: unknown): string {
  return typeof value === 'string' ? value : ''
}

function routePositiveInt(value: unknown, fallback: number): number {
  const parsed = Number(routeString(value))
  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback
}

const query = ref(routeString(route.query.q))
const page = ref(routePositiveInt(route.query.page, 1))
const error = ref('')

const currentYear = new Date().getFullYear()
// activeYear = null → 近期模式（不限年份，air_date 新到舊，50 筆）
// activeYear = 數字 → 年份瀏覽模式
const routeYear = routePositiveInt(route.query.year, 0)
const activeYear = ref<number | null>(routeYear >= 1900 && routeYear <= 2100 ? routeYear : null)
const selectedTags = ref<string[]>(routeString(route.query.tags).split(',').filter(Boolean))
const isSearchMode = computed(() => query.value.trim() !== '')
const isRecentMode = computed(() => activeYear.value === null && !isSearchMode.value)

// 分類清單（全庫前 20 高頻）
const tagOptions = ref<{ tag: string; count: number }[]>([])
const tagsLoading = ref(true)
onMounted(async () => {
  try {
    const res = await api.catalogTags()
    tagOptions.value = (res.tags || []).slice(0, 20)
  } catch {
    tagOptions.value = []
  } finally {
    tagsLoading.value = false
  }
})

// 主要資料來源：依 activeYear / query / selectedTags 向後端查詢
const loading = ref(false)
let requestId = 0
let updatingRoute = false
const PAGE_SIZE = 40

function filtersForRequest(requestedPage = page.value) {
  const filters: { year?: number; tags?: string[]; page: number; perPage: number } = {
    page: requestedPage,
    perPage: PAGE_SIZE
  }
  if (activeYear.value !== null && !isSearchMode.value) filters.year = activeYear.value
  if (selectedTags.value.length > 0) filters.tags = selectedTags.value
  return filters
}

function catalogRouteQuery(): Record<string, string> {
  const next: Record<string, string> = {}
  const q = query.value.trim()
  if (q) next.q = q
  if (!q && activeYear.value !== null) next.year = String(activeYear.value)
  if (selectedTags.value.length > 0) next.tags = selectedTags.value.join(',')
  if (page.value > 1) next.page = String(page.value)
  return next
}

async function syncRoute(mode: 'push' | 'replace' = 'push') {
  updatingRoute = true
  try {
    await router[mode]({ path: '/catalog', query: catalogRouteQuery() })
  } finally {
    updatingRoute = false
  }
}

async function loadCatalog() {
  const id = ++requestId
  loading.value = true
  error.value = ''
  try {
    const result = await api.searchAnimeSummaries(query.value.trim(), filtersForRequest())
    if (id !== requestId) return
    const responseMeta = result.meta
    if (page.value > responseMeta.last_page) {
      page.value = responseMeta.last_page
      await syncRoute('replace')
      await loadCatalog()
      return
    }
    catalog.value = (result.items || []).map(normalizeAnimeSummary)
    catalogMeta.value = responseMeta
  } catch (err: unknown) {
    if (id !== requestId) return
    error.value = apiErrorMessage(err, '載入失敗')
    catalog.value = []
  } finally {
    if (id === requestId) loading.value = false
  }
}

// 進站載入近期模式：讓 useAsyncData 直接接管查詢結果，回傳值進 payload、
// hydration 後仍在，避免 client 端重跑跳過導致 catalog 落空的空狀態 bug。
const { data: initialData, pending: initialPending } = await useAsyncData(
  `catalog-initial:${query.value.trim()}:${activeYear.value ?? 'recent'}:${selectedTags.value.join(',')}:${page.value}`,
  async () => {
    let result = await api.searchAnimeSummaries(query.value.trim(), filtersForRequest())
    const lastPage = Math.max(1, Number(result.meta?.last_page ?? 1))
    if (page.value > lastPage) {
      result = await api.searchAnimeSummaries(query.value.trim(), filtersForRequest(lastPage))
    }
    return {
      items: result.items,
      meta: result.meta
    }
  }
)

// catalog 以初始資料為基礎；後續互動由 loadCatalog 直接覆寫。
const catalog = ref<AnimeSummary[]>((initialData.value?.items || []).map(normalizeAnimeSummary))
const catalogMeta = ref(initialData.value?.meta ?? {
  page: page.value,
  per_page: PAGE_SIZE,
  total: catalog.value.length,
  last_page: 1,
  has_more: false
})
page.value = catalogMeta.value.page
const resultTotal = computed(() => catalogMeta.value.total)

const catalogAnimeIds = computed(() => catalog.value.map(anime => anime.id))
const {
  statusesByAnimeId,
  collections,
  isInList,
  isWatched,
  toggleAnimeInList,
  markWatched,
  toggleCollection
} = useAnimeCardStatuses(catalogAnimeIds)

// 使用者清單狀態：用來讓卡片的 ❤️（已收藏）/ ✅（已看）正確反映實際狀態
const activePopoverAnimeId = ref<number | null>(null)
onMounted(async () => {
  if (routePositiveInt(route.query.page, 1) !== page.value) await syncRoute('replace')
})

const totalPages = computed(() => Math.max(1, catalogMeta.value.last_page))

async function changePage(nextPage: number) {
  const normalizedPage = Math.min(Math.max(nextPage, 1), totalPages.value)
  if (normalizedPage === page.value) return
  page.value = normalizedPage
  await syncRoute()
  await loadCatalog()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

async function changeYear(year: number | null) {
  query.value = ''
  selectedTags.value = []
  page.value = 1
  activeYear.value = year
  await syncRoute()
  await loadCatalog()
}

async function toggleTag(tag: string) {
  const idx = selectedTags.value.indexOf(tag)
  if (idx >= 0) selectedTags.value.splice(idx, 1)
  else selectedTags.value.push(tag)
  page.value = 1
  await syncRoute()
  await loadCatalog()
}

async function clearTags() {
  if (selectedTags.value.length === 0) return
  selectedTags.value = []
  page.value = 1
  await syncRoute()
  await loadCatalog()
}

async function search() {
  page.value = 1
  // 搜尋時脫離年份模式（回到不限年份），保留 selectedTags
  if (query.value.trim() !== '') activeYear.value = null
  await syncRoute()
  await loadCatalog()
}

watch(() => route.query, async () => {
  if (updatingRoute) return
  query.value = routeString(route.query.q)
  page.value = routePositiveInt(route.query.page, 1)
  const year = routePositiveInt(route.query.year, 0)
  activeYear.value = year >= 1900 && year <= 2100 ? year : null
  selectedTags.value = routeString(route.query.tags).split(',').filter(Boolean)
  await loadCatalog()
}, { deep: true })

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

<template>
  <div class="space-y-5">
    <header class="space-y-1">
      <p class="text-xs font-extrabold uppercase tracking-widest text-primary-700">資料庫</p>
      <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-extrabold tracking-tight text-gray-950 md:text-3xl">搜尋動漫資料庫</h1>
        <span class="shrink-0 rounded-full bg-gray-100 px-3 py-1 text-sm font-semibold text-gray-700">
          {{ resultTotal }} 筆
        </span>
      </div>
    </header>

    <UAlert v-if="error" color="error" :title="error" />

    <!-- 手機版只保留搜尋與篩選入口，年份及分類放進 bottom sheet，
         避免大量 chip 將首批作品推到首屏之外。 -->
    <div class="space-y-3 rounded-2xl border border-gray-200 bg-white p-3 shadow-sm md:hidden">
      <form class="flex min-w-0 gap-2" @submit.prevent="search">
        <div class="relative min-w-0 flex-1">
          <label for="catalog-search-mobile" class="sr-only">搜尋動漫資料庫</label>
          <UIcon
            name="i-lucide-search"
            class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400"
          />
          <input
            id="catalog-search-mobile"
            v-model="query"
            type="search"
            placeholder="搜尋動漫名稱"
            class="min-h-11 w-full rounded-lg border border-gray-200 bg-white py-2 pl-9 pr-3 text-sm text-gray-900 placeholder:text-gray-400 shadow-sm outline-none transition focus:border-primary-400 focus:ring-2 focus:ring-primary-100"
          />
        </div>
        <button
          type="submit"
          :disabled="loading"
          class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg bg-primary-700 px-4 text-sm font-semibold text-white shadow-sm transition active:bg-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-60"
        >
          <UIcon v-if="loading" name="i-lucide-loader-circle" class="size-4 animate-spin" />
          <span v-else>搜尋</span>
        </button>
      </form>

      <div class="flex min-h-11 items-center justify-between gap-3 border-t border-gray-100 pt-3">
        <p class="min-w-0 truncate text-xs font-medium text-gray-500">
          <span>{{ isSearchMode ? '搜尋結果' : activeYear === null ? '近期作品' : `${activeYear} 年` }}</span>
          <span v-if="selectedTags.length > 0"> · {{ selectedTags.length }} 個分類</span>
        </p>
        <CatalogFilterPanel
          :active-year="activeYear"
          :current-year="currentYear"
          :selected-tags="selectedTags"
          :tag-options="tagOptions"
          :tags-loading="tagsLoading"
          :loading="loading"
          :result-count="resultTotal"
          @change-year="changeYear"
          @toggle-tag="toggleTag"
          @clear-tags="clearTags"
        />
      </div>
    </div>

    <!-- 桌機版沿用既有 inline 篩選 DOM 與外觀。 -->
    <div class="hidden space-y-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm md:block">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <!-- 近期 / 年份切換（搜尋中隱藏） -->
        <div v-if="!isSearchMode" class="flex shrink-0 items-center gap-2">
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

        <form class="flex flex-1 gap-2" @submit.prevent="search">
          <div class="relative flex-1">
            <label for="catalog-search" class="sr-only">搜尋動漫資料庫</label>
            <UIcon
              name="i-lucide-search"
              class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400 pointer-events-none"
            />
            <input
              id="catalog-search"
              v-model="query"
              type="search"
              placeholder="例如：芙莉蓮、Bocchi、排球少年"
              class="w-full rounded-lg border border-gray-200 bg-white py-2.5 pl-9 pr-4 text-sm text-gray-900 placeholder:text-gray-400 shadow-sm outline-none transition focus:border-primary-400 focus:ring-2 focus:ring-primary-100"
            />
          </div>
          <button
            type="submit"
            :disabled="loading"
            class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-60"
          >
            <UIcon v-if="loading" name="i-lucide-loader-circle" class="size-4 animate-spin" />
            <span v-else>搜尋</span>
          </button>
        </form>
      </div>

      <!-- 分類 chip 骨架：載入分類清單期間顯示灰色脈動方塊，避免 chip 突然
           跳出（寬度不一，貼近真實 chip 長短不同的排列） -->
      <div v-if="!isSearchMode && tagsLoading" class="flex flex-wrap items-center gap-1.5 border-t border-gray-100 pt-3">
        <div
          v-for="w in ['w-12', 'w-10', 'w-14', 'w-10', 'w-16', 'w-12', 'w-10', 'w-14', 'w-12', 'w-16']"
          :key="w"
          class="h-6 animate-pulse rounded-full bg-gray-200"
          :class="w"
        />
      </div>

      <!-- 分類 chip 列（近期／年份模式皆可用，OR 多選，走後端篩選；搜尋中隱藏） -->
      <div v-if="!isSearchMode && !tagsLoading && tagOptions.length > 0" class="flex flex-wrap items-center gap-1.5 border-t border-gray-100 pt-3">
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
          :style="{ backgroundColor: tagColor(item.tag).bg, color: tagColor(item.tag).text }"
          @click="toggleTag(item.tag)"
        >
          {{ item.tag }}
        </button>
      </div>
    </div>

    <!-- Loading skeleton: matches PAGE_SIZE so the layout doesn't jump when real content arrives -->
    <div v-if="loading || initialPending" class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-5">
      <div v-for="i in PAGE_SIZE" :key="i" class="aspect-3/4 w-full animate-pulse rounded-md bg-gray-200" />
    </div>

    <div
      v-else-if="catalog.length === 0 && !error"
      class="rounded-xl border border-dashed border-gray-200 bg-white p-10 text-center"
    >
      <UIcon name="i-lucide-search-x" class="mx-auto mb-2 size-8 text-gray-300" />
      <p class="text-sm font-medium text-gray-500">沒有找到符合的作品</p>
      <p class="mt-1 text-xs text-gray-400">換個關鍵字試試</p>
    </div>

    <template v-else>
      <AnimeVirtualGrid :items="catalog">
        <template #default="{ item: anime, index }">
          <AnimeGridCard
            :key="anime.id"
            :anime="anime"
            :in-list="isInList(anime.id)"
            :watched="isWatched(anime.id)"
            :status="statusesByAnimeId.get(anime.id)"
            :collections="collections"
            :popover-open="activePopoverAnimeId === anime.id"
            :eager-load="index < HIGH_PRIORITY_IMAGE_COUNT"
            @add-to-list="toggleAnimeInList"
            @mark-watched="markWatched"
            @toggle-collection="(col) => toggleCollection(anime.id, col)"
            @open-popover="activePopoverAnimeId = anime.id"
            @close-popover="activePopoverAnimeId = null"
          />
        </template>
      </AnimeVirtualGrid>

      <!-- Pagination -->
      <div v-if="totalPages > 1" class="flex items-center justify-center gap-2 pt-2">
        <button
          type="button"
          :disabled="page === 1"
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
          :disabled="page === totalPages"
          class="flex h-11 w-11 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 disabled:opacity-40 md:h-9 md:w-9"
          aria-label="下一頁"
          @click="changePage(page + 1)"
        >
          <UIcon name="i-lucide-chevron-right" class="size-4" />
        </button>
      </div>
    </template>
  </div>
</template>
