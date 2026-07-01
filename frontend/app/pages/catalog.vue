<script setup lang="ts">
import { normalizeAnime } from '../utils/normalize'
import type { Anime } from '../utils/normalize'

const api = useApi()
const { isAuthed } = useSession()
const { canManuallyCreateAnime } = useCatalogAccess()
const toast = useToast()

const query = ref('')
const catalog = ref<Anime[]>([])
const loading = ref(false)
const error = ref('')

// Year browsing mode: default to the current year so we never load the
// full 2016–2026 catalog at once. Searching (query non-empty) switches to
// an unscoped full-catalog search instead.
const currentYear = new Date().getFullYear()
const activeYear = ref(currentYear)
const isSearchMode = computed(() => query.value.trim() !== '')

// Cache per-year results for the session so switching back to a
// previously-viewed year doesn't re-hit the API.
const yearCache = new Map<number, Anime[]>()

const PAGE_SIZE = 40
const page = ref(1)
const totalPages = computed(() => Math.max(1, Math.ceil(catalog.value.length / PAGE_SIZE)))
const pagedCatalog = computed(() => {
  const start = (page.value - 1) * PAGE_SIZE
  return catalog.value.slice(start, start + PAGE_SIZE)
})

watch(page, () => {
  window.scrollTo({ top: 0, behavior: 'smooth' })
})

async function loadYear(year: number) {
  loading.value = true
  error.value = ''
  page.value = 1
  activeYear.value = year

  const cached = yearCache.get(year)
  if (cached) {
    catalog.value = cached
    loading.value = false
    return
  }

  try {
    const result = await api.searchAnime('', { year })
    const items = (result.items || []).map(normalizeAnime)
    yearCache.set(year, items)
    catalog.value = items
  } catch (err: any) {
    error.value = err.message || '載入失敗'
  } finally {
    loading.value = false
  }
}

function changeYear(year: number) {
  query.value = ''
  loadYear(year)
}

async function search() {
  const q = query.value.trim()
  if (q === '') {
    loadYear(activeYear.value)
    return
  }

  loading.value = true
  error.value = ''
  page.value = 1
  try {
    const result = await api.searchAnime(q)
    catalog.value = (result.items || []).map(normalizeAnime)
  } catch (err: any) {
    error.value = err.message || '搜尋失敗'
  } finally {
    loading.value = false
  }
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

async function createAnime(payload: { name: string; description: string; imageUrl: string }) {
  loading.value = true
  error.value = ''
  try {
    const result = await api.createAnime(payload)
    catalog.value = [normalizeAnime(result.item), ...catalog.value]
    if (!isSearchMode.value) yearCache.set(activeYear.value, catalog.value)
    page.value = 1
    toast.add({ title: '已建立作品資料', color: 'success' })
  } catch (err: any) {
    error.value = err.message || '建立失敗'
  } finally {
    loading.value = false
  }
}

onMounted(() => loadYear(activeYear.value))
</script>

<template>
  <div class="grid gap-6" :class="canManuallyCreateAnime ? 'md:grid-cols-[1fr_280px]' : ''">

    <!-- Left: main catalog -->
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
        <p class="mt-1 text-xs text-gray-400">換個關鍵字，或在右側手動建立資料</p>
      </div>

      <template v-else>
        <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
          <AnimeGridCard
            v-for="anime in pagedCatalog"
            :key="anime.id"
            :anime="anime"
            :in-list="false"
            :watched="false"
            :collections="[]"
            :popover-open="false"
            @add-to-list="addAnime"
            @mark-watched="addAnime"
          />
        </div>

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

    <!-- Right: manual create form (REDACTED_EMAIL only) -->
    <aside v-if="canManuallyCreateAnime" class="space-y-4">
      <div class="sticky top-24 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        <h2 class="mb-4 text-sm font-bold text-gray-900">手動建立</h2>
        <ManualAnimeForm :disabled="!isAuthed" :loading="loading" @submit="createAnime" />
      </div>
    </aside>

  </div>
</template>
