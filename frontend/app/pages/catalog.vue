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

<template>
  <div class="space-y-5">
    <header class="space-y-1">
      <p class="text-xs font-extrabold uppercase tracking-widest text-primary-600">資料庫</p>
      <div class="flex items-center justify-between gap-4">
        <h1 class="text-3xl font-extrabold tracking-tight text-gray-950">搜尋動漫資料庫</h1>
        <span class="shrink-0 rounded-full bg-gray-100 px-3 py-1 text-sm font-semibold text-gray-700">
          {{ catalog.length }} 筆
        </span>
      </div>
    </header>

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

    <!-- 分類 chip 列（近期／年份模式皆可用，OR 多選，走後端篩選；搜尋中隱藏） -->
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
        :style="{ backgroundColor: tagColor(item.tag).bg, color: tagColor(item.tag).text }"
        @click="toggleTag(item.tag)"
      >
        {{ item.tag }}
      </button>
    </div>

    <UAlert v-if="error" color="error" :title="error" />

    <form class="flex gap-2" @submit.prevent="search">
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

    <!-- Loading skeleton: matches PAGE_SIZE so the layout doesn't jump when real content arrives -->
    <div v-if="loading" class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
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
      <AnimeVirtualGrid :items="pagedCatalog">
        <template #default="{ item: anime, index }">
          <AnimeGridCard
            :key="anime.id"
            :anime="anime"
            :in-list="false"
            :watched="false"
            :eager-load="index < 10"
            @add-to-list="addAnime"
            @mark-watched="addAnime"
          />
        </template>
      </AnimeVirtualGrid>

      <!-- Pagination -->
      <div v-if="totalPages > 1" class="flex items-center justify-center gap-2 pt-2">
        <button
          type="button"
          :disabled="page === 1"
          class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 disabled:opacity-40"
          aria-label="上一頁"
          @click="page--"
        >
          <UIcon name="i-lucide-chevron-left" class="size-4" />
        </button>
        <span class="min-w-20 text-center text-sm font-semibold text-gray-700">
          {{ page }} / {{ totalPages }}
        </span>
        <button
          type="button"
          :disabled="page === totalPages"
          class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 disabled:opacity-40"
          aria-label="下一頁"
          @click="page++"
        >
          <UIcon name="i-lucide-chevron-right" class="size-4" />
        </button>
      </div>
    </template>
  </div>
</template>
