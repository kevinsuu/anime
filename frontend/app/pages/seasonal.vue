<script setup lang="ts">
import { weekdayTabs, useSeasonalCatalog, deriveFilterOptions } from '../composables/useSeasonalCatalog'
import { normalizeAnime, normalizeListItem, normalizeCollection, tagColor } from '../utils/normalize'
import type { Anime, ListItem, Collection } from '../utils/normalize'

const api = useApi()
const { session, isAuthed } = useSession()
const { state: filterState, filterSeasonal, activeFilterCount, resetFilters, toggleGenreTag } = useSeasonalCatalog()
const toast = useToast()

const seasonLabels: Record<string, string> = {
  winter: '1月', spring: '4月', summer: '7月', fall: '10月'
}

const SEASONS = ['winter', 'spring', 'summer', 'fall'] as const
type Season = typeof SEASONS[number]

const seasonalControls = reactive({
  year: new Date().getFullYear(),
  season: (() => {
    const month = new Date().getMonth() + 1
    return month <= 3 ? 'winter' : month <= 6 ? 'spring' : month <= 9 ? 'summer' : 'fall'
  })() as Season
})

function prevSeason() {
  const idx = SEASONS.indexOf(seasonalControls.season)
  if (idx === 0) { seasonalControls.season = 'fall'; seasonalControls.year-- }
  else seasonalControls.season = SEASONS[idx - 1]
}

function nextSeason() {
  const idx = SEASONS.indexOf(seasonalControls.season)
  if (idx === 3) { seasonalControls.season = 'winter'; seasonalControls.year++ }
  else seasonalControls.season = SEASONS[idx + 1]
}

const filterOptions = computed(() => deriveFilterOptions(seasonal.value))

const seasonal = ref<Anime[]>([])
const list = ref<ListItem[]>([])
const collections = ref<Collection[]>([])
const loading = ref(false)
const filterPanelOpen = ref(false)
const activePopoverAnimeId = ref<number | null>(null)

const listByAnimeId = computed(() => {
  const map = new Map<number, ListItem>()
  list.value.forEach(item => map.set(item.anime.id, item))
  return map
})

const filteredSeasonal = computed(() => filterSeasonal(seasonal.value, listByAnimeId.value))

// Active filter chips to display inline
const activeChips = computed(() => {
  const chips: { label: string; clear: () => void; genre?: boolean }[] = []
  if (filterState.sourceTag) chips.push({ label: filterState.sourceTag, clear: () => { filterState.sourceTag = '' } })
  for (const tag of filterState.genreTags) {
    chips.push({ label: tag, clear: () => toggleGenreTag(tag), genre: true })
  }
  if (filterState.actor) chips.push({ label: filterState.actor, clear: () => { filterState.actor = '' } })
  if (filterState.seasonalStatus !== 'all') {
    const labels: Record<string, string> = { listed: '已加入清單', unlisted: '未加入清單', watched: '已看', queued: '待補', 'with-cover': '有封面' }
    chips.push({ label: labels[filterState.seasonalStatus] ?? filterState.seasonalStatus, clear: () => { filterState.seasonalStatus = 'all' } })
  }
  return chips
})

async function loadSeasonal() {
  loading.value = true
  try {
    const result = await api.searchAnime('', { year: seasonalControls.year, season: seasonalControls.season })
    seasonal.value = (result.items || []).map(normalizeAnime)
  } catch (err: any) {
    toast.add({ title: err.message || '載入失敗', color: 'error' })
  } finally {
    loading.value = false
  }
}

async function loadMyList() {
  if (!session.token) return
  try {
    const [listRes, colRes] = await Promise.all([api.myList(), api.myCollections()])
    list.value = (listRes.items || []).map(normalizeListItem)
    collections.value = (colRes.items || []).map(normalizeCollection)
  } catch (err: any) {
    toast.add({ title: err.message || '載入清單失敗', color: 'error' })
  }
}

async function toggleCollection(animeId: number, col: Collection) {
  if (!isAuthed.value) return
  const listItem = listByAnimeId.value.get(animeId)
  if (!listItem) return
  const inCol = listItem.collections.some(c => c.id === col.id)
  try {
    if (inCol) {
      await api.removeFromCollection(col.id, listItem.id)
      listItem.collections = listItem.collections.filter(c => c.id !== col.id)
    } else {
      await api.addToCollection(col.id, listItem.id)
      listItem.collections = [...listItem.collections, { id: col.id, name: col.name }]
    }
    const idx = collections.value.findIndex(c => c.id === col.id)
    if (idx >= 0) collections.value[idx].count += inCol ? -1 : 1
  } catch (err: any) {
    toast.add({ title: err.message || '操作失敗', color: 'error' })
  }
}

async function addAnime(animeId: number) {
  if (!isAuthed.value) return navigateTo('/login')
  if (listByAnimeId.value.has(animeId)) return
  try {
    await api.addToList(animeId)
    await loadMyList()
    toast.add({ title: '已加入清單', color: 'success' })
  } catch (err: any) {
    toast.add({ title: err.message || '加入失敗', color: 'error' })
  }
}

async function markWatched(animeId: number) {
  if (!isAuthed.value) return navigateTo('/login')
  try {
    const existing = listByAnimeId.value.get(animeId)
    if (existing) {
      await api.updateListItem(existing.id, { watched: !existing.watched })
    } else {
      await api.addToList(animeId)
      // Item is now in the list even if the watched-flag update below fails,
      // so always reload afterwards regardless of outcome (see finally).
      const freshList = await api.myList()
      const freshItem = (freshList.items || []).find((i: any) => i.anime?.id === animeId)
      if (freshItem) await api.updateListItem(freshItem.id, { watched: true })
    }
    const item = listByAnimeId.value.get(animeId)
    toast.add({ title: item?.watched ? '已標記為看完' : '已取消已看', color: 'success' })
  } catch (err: any) {
    toast.add({ title: err.message || '操作失敗，清單狀態已重新整理', color: 'error' })
  } finally {
    await loadMyList()
  }
}

watch(() => [seasonalControls.year, seasonalControls.season], () => {
  resetFilters()
  loadSeasonal()
})

onMounted(async () => {
  await Promise.all([loadSeasonal(), loadMyList()])
})
</script>

<template>
  <div class="space-y-4">
    <!-- Header: season navigator -->
    <header class="flex items-center justify-between gap-4">
      <div class="space-y-0.5">
        <p class="text-xs font-extrabold uppercase tracking-widest text-primary-600">新番表</p>
        <h1 class="text-2xl font-extrabold tracking-tight text-gray-950">
          {{ seasonalControls.year }}年 {{ seasonLabels[seasonalControls.season] }} 新番表
        </h1>
      </div>
      <!-- Season prev/next arrows -->
      <div class="flex items-center gap-1 shrink-0">
        <button
          :disabled="loading"
          class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 disabled:opacity-40"
          aria-label="上一季"
          @click="prevSeason"
        >
          <UIcon name="i-lucide-chevron-left" class="size-4" />
        </button>
        <div class="min-w-28 text-center">
          <span class="text-sm font-bold text-gray-700">
            {{ seasonalControls.year }} · {{ seasonLabels[seasonalControls.season] }}
          </span>
        </div>
        <button
          :disabled="loading"
          class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 disabled:opacity-40"
          aria-label="下一季"
          @click="nextSeason"
        >
          <UIcon name="i-lucide-chevron-right" class="size-4" />
        </button>
      </div>
    </header>

    <!-- Weekday tabs -->
    <div class="flex gap-1 overflow-x-auto pb-0.5" role="tablist" aria-label="依星期篩選">
      <button
        v-for="tab in weekdayTabs"
        :key="tab.key"
        role="tab"
        :aria-selected="filterState.weekday === tab.key"
        :disabled="loading"
        class="shrink-0 rounded-md px-3 py-1.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-50"
        :class="filterState.weekday === tab.key
          ? 'bg-primary-600 text-white shadow-sm'
          : 'bg-gray-100 text-gray-800 hover:bg-gray-200 hover:text-gray-950'"
        @click="filterState.weekday = tab.key"
      >{{ tab.label }}</button>
    </div>

    <!-- Genre filter + 更多篩選 on same row -->
    <div v-if="filterOptions.genres.length > 0" class="flex items-start gap-2">
      <div class="flex flex-1 flex-wrap gap-1.5">
        <button
          class="rounded border px-2.5 py-1 text-xs font-semibold transition-colors"
          :class="filterState.genreTags.length === 0
            ? 'border-gray-900 bg-gray-900 text-white'
            : 'border-gray-300 bg-white text-gray-700 hover:border-gray-500 hover:text-gray-900'"
          @click="filterState.genreTags = []"
        >全部</button>
        <button
          v-for="item in filterOptions.genres"
          :key="item.tag"
          class="rounded border border-transparent px-2.5 py-1 text-xs font-semibold transition-colors"
          :class="filterState.genreTags.includes(item.tag)
            ? 'border-primary-600 bg-primary-600 text-white'
            : 'hover:border-gray-300'"
          :style="filterState.genreTags.includes(item.tag) ? {} : { backgroundColor: tagColor(item.tag).bg, color: tagColor(item.tag).text }"
          @click="toggleGenreTag(item.tag)"
        >{{ item.tag }}<span class="ml-1 opacity-50">({{ item.count }})</span></button>
      </div>

      <button
        class="flex shrink-0 items-center gap-1.5 rounded border border-gray-300 bg-white px-3 py-1 text-xs font-semibold text-gray-700 shadow-sm transition-colors hover:border-gray-500 hover:bg-gray-50"
        @click="filterPanelOpen = true"
      >
        <UIcon name="i-lucide-sliders-horizontal" class="size-3.5 text-gray-500" />
        更多篩選
        <span
          v-if="activeFilterCount > 0"
          class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-primary-600 text-[10px] font-bold text-white"
        >{{ activeFilterCount }}</span>
      </button>
    </div>

    <!-- Active filter chips -->
    <div v-if="activeChips.length > 0" class="flex flex-wrap items-center gap-1.5">
      <button
        v-for="chip in activeChips"
        :key="chip.label"
        class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold transition-colors"
        :class="chip.genre ? 'hover:opacity-80' : 'bg-primary-50 text-primary-700 hover:bg-primary-100'"
        :style="chip.genre ? { backgroundColor: tagColor(chip.label).bg, color: tagColor(chip.label).text } : {}"
        @click="chip.clear()"
      >
        {{ chip.label }}
        <UIcon name="i-lucide-x" class="size-3" />
      </button>
      <button class="text-xs text-gray-400 hover:text-gray-700 underline underline-offset-2" @click="resetFilters()">清除全部</button>
      <span class="ml-auto text-xs text-gray-500">顯示 <strong class="text-gray-900">{{ filteredSeasonal.length }}</strong> / {{ seasonal.length }} 部</span>
    </div>

    <!-- Loading skeleton: fills roughly one viewport at the widest (5-col) breakpoint -->
    <div v-if="loading" class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
      <div v-for="i in 20" :key="i" class="aspect-3/4 w-full animate-pulse rounded-lg bg-gray-200" />
    </div>

    <div v-else-if="!loading && filteredSeasonal.length === 0" class="rounded-xl border border-dashed border-gray-200 p-8 text-center text-gray-500">
      <UIcon name="i-lucide-calendar-x" class="mx-auto mb-2 size-8 text-gray-300" />
      <p class="text-sm font-medium">這個篩選條件目前沒有資料</p>
      <p class="mt-1 text-xs text-gray-400">試試切換星期或清除篩選條件</p>
    </div>

    <div v-else class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
      <AnimeGridCard
        v-for="anime in filteredSeasonal"
        :key="anime.id"
        :anime="anime"
        :in-list="listByAnimeId.has(anime.id)"
        :watched="Boolean(listByAnimeId.get(anime.id)?.watched)"
        :list-item="listByAnimeId.get(anime.id)"
        :collections="collections"
        :popover-open="activePopoverAnimeId === anime.id"
        @add-to-list="addAnime"
        @mark-watched="markWatched"
        @toggle-collection="(col) => toggleCollection(anime.id, col)"
        @open-popover="activePopoverAnimeId = anime.id"
        @close-popover="activePopoverAnimeId = null"
      />
    </div>

    <SeasonalFilterPanel
      v-model:open="filterPanelOpen"
      v-model:year="seasonalControls.year"
      v-model:season="seasonalControls.season"
      v-model:source-tag="filterState.sourceTag"
      v-model:actor="filterState.actor"
      v-model:status="filterState.seasonalStatus"
      :anime-list="seasonal"
      @reset="resetFilters()"
    />
  </div>
</template>
