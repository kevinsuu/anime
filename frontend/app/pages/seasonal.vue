<script setup lang="ts">
import { weekdayTabs, useSeasonalCatalog, deriveFilterOptions } from '../composables/useSeasonalCatalog'
import { HIGH_PRIORITY_IMAGE_COUNT } from '../composables/useLazyLoad'
import { normalizeAnimeSummary, tagColor } from '../utils/normalize'
import type { AnimeSummary } from '../utils/normalize'
import { isSeasonSelection, seasonMonthLabels, seasonSelection, shiftSeason } from '../utils/season'
import type { SeasonSelection } from '../utils/season'

const props = withDefaults(defineProps<{
  homepage?: boolean
}>(), {
  homepage: false
})

const api = useApi()
const route = useRoute()
const router = useRouter()
const { state: filterState, filterSeasonal, activeFilterCount, resetFilters, toggleGenreTag } = useSeasonalCatalog()
const toast = useToast()

const initialSeason = seasonSelection(route.query.year, route.query.season)
const seasonalControls = reactive<SeasonSelection>({ ...initialSeason })

function prevSeason() {
  Object.assign(seasonalControls, shiftSeason(seasonalControls, -1))
}

function nextSeason() {
  Object.assign(seasonalControls, shiftSeason(seasonalControls, 1))
}

interface SeasonalPayload extends SeasonSelection {
  items: AnimeSummary[]
}

const seasonalDataKey = computed(() => `seasonal:${seasonalControls.year}:${seasonalControls.season}`)
const { data: seasonalPayload, pending: loading, error: fetchError } = await useAsyncData(
  seasonalDataKey,
  async () => {
    // Capture one immutable selection per request. A later arrow click must not
    // relabel an older response as if it belonged to the newly selected season.
    const requestedYear = seasonalControls.year
    const requestedSeason = seasonalControls.season
    const result = await api.searchAnimeSummaries('', {
      year: requestedYear,
      season: requestedSeason,
      perPage: 100
    })
    return {
      year: requestedYear,
      season: requestedSeason,
      items: (result.items || []).map(normalizeAnimeSummary)
    }
  },
  {
    default: (): SeasonalPayload => ({
      year: seasonalControls.year,
      season: seasonalControls.season,
      items: []
    })
  }
)

const seasonal = computed(() => {
  const payload = seasonalPayload.value
  return payload.year === seasonalControls.year && payload.season === seasonalControls.season
    ? payload.items
    : []
})

const seasonalAnimeIds = computed(() => seasonal.value.map((anime: AnimeSummary) => anime.id))
const {
  statusesByAnimeId,
  collections,
  isInList,
  isWatched,
  toggleAnimeInList,
  markWatched,
  toggleCollection
} = useAnimeCardStatuses(seasonalAnimeIds)

watch(fetchError, (err) => {
  if (err) toast.add({ title: err.message || '載入失敗', color: 'error' })
})

const filterOptions = computed(() => deriveFilterOptions(seasonal.value))

const filterPanelOpen = ref(false)
const activePopoverAnimeId = ref<number | null>(null)

const filteredSeasonal = computed(() => filterSeasonal(seasonal.value, statusesByAnimeId))

// Active filter chips to display inline
const activeChips = computed(() => {
  const chips: { label: string; clear: () => void; genre?: boolean }[] = []
  if (filterState.sourceTag) chips.push({ label: filterState.sourceTag, clear: () => { filterState.sourceTag = '' } })
  for (const tag of filterState.genreTags) {
    chips.push({ label: tag, clear: () => toggleGenreTag(tag), genre: true })
  }
  if (filterState.actor) chips.push({ label: filterState.actor, clear: () => { filterState.actor = '' } })
  if (filterState.seasonalStatus !== 'all') {
    const labels: Record<string, string> = { listed: '已加入清單', unlisted: '未加入清單', watched: '已觀看', queued: '待補', 'with-cover': '有封面' }
    chips.push({ label: labels[filterState.seasonalStatus] ?? filterState.seasonalStatus, clear: () => { filterState.seasonalStatus = 'all' } })
  }
  return chips
})

watch(() => [seasonalControls.year, seasonalControls.season], () => {
  resetFilters()
  const routeYear = typeof route.query.year === 'string' ? route.query.year : ''
  const routeSeason = typeof route.query.season === 'string' ? route.query.season : ''
  if (routeYear === String(seasonalControls.year) && routeSeason === seasonalControls.season) return
  void router.replace({ query: { ...route.query, year: String(seasonalControls.year), season: seasonalControls.season } })
})

watch(() => [route.query.year, route.query.season], () => {
  const selection = seasonSelection(route.query.year, route.query.season)
  if (selection.year === seasonalControls.year && selection.season === seasonalControls.season) return
  Object.assign(seasonalControls, selection)
})

const hasExplicitSeasonQuery = computed(() => isSeasonSelection(route.query.year, route.query.season))
const isBrandHomepage = computed(() => props.homepage && !hasExplicitSeasonQuery.value)
const seasonalTitle = computed(() => `${seasonalControls.year}年${seasonMonthLabels[seasonalControls.season]}新番表｜動畫新番、動漫庫`)
const seasonalDescription = computed(() => `${seasonalControls.year}年${seasonMonthLabels[seasonalControls.season]}季動畫新番總覽，追蹤最新動漫新番放送時間、角色資訊與觀看平台。`)
const pageTitle = computed(() => isBrandHomepage.value
  ? '動漫庫｜動畫新番表、動漫資料庫與追番收藏'
  : seasonalTitle.value)
const pageDescription = computed(() => isBrandHomepage.value
  ? '動漫庫提供每季動畫新番表、完整動漫資料庫、播出時間、角色聲優資訊與個人追番收藏功能。'
  : seasonalDescription.value)
const canonicalUrl = computed(() => hasExplicitSeasonQuery.value
  ? `https://anime.kaistarstudio.me/?year=${seasonalControls.year}&season=${seasonalControls.season}`
  : 'https://anime.kaistarstudio.me/')

useSeoMeta({
  title: () => pageTitle.value,
  description: () => pageDescription.value,
  ogTitle: () => pageTitle.value,
  ogDescription: () => pageDescription.value,
  ogSiteName: '動漫庫',
  ogType: 'website'
})
useHead({
  link: [{ rel: 'canonical', href: () => canonicalUrl.value }]
})
</script>

<template>
  <div class="space-y-3 md:space-y-4">
    <!-- Header: season navigator -->
    <header class="flex items-center justify-between gap-3 md:gap-4">
      <div class="space-y-0.5">
        <p class="text-xs font-extrabold uppercase tracking-widest text-primary-700">新番表</p>
        <component :is="props.homepage ? 'h2' : 'h1'" class="text-xl font-extrabold tracking-tight text-gray-950 md:text-2xl">
          {{ seasonalControls.year }}年 {{ seasonMonthLabels[seasonalControls.season] }} 新番表
        </component>
      </div>
      <!-- Season prev/next arrows -->
      <div class="flex items-center gap-1 shrink-0">
        <button
          :disabled="loading"
          class="flex h-11 w-11 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 disabled:opacity-40 md:h-9 md:w-9"
          aria-label="上一季"
          @click="prevSeason"
        >
          <UIcon name="i-lucide-chevron-left" class="size-4" />
        </button>
        <div class="hidden min-w-28 text-center md:block">
          <span class="text-sm font-bold text-gray-700">
            {{ seasonalControls.year }} · {{ seasonMonthLabels[seasonalControls.season] }}
          </span>
        </div>
        <button
          :disabled="loading"
          class="flex h-11 w-11 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 disabled:opacity-40 md:h-9 md:w-9"
          aria-label="下一季"
          @click="nextSeason"
        >
          <UIcon name="i-lucide-chevron-right" class="size-4" />
        </button>
      </div>
    </header>

    <!-- 篩選卡片：星期 tabs + 分類篩選 + 已選 chips 集中在一張白底卡片，
         與資料庫/我的清單頁的搜尋卡片一致，統一產品樣式 -->
    <div class="space-y-3 rounded-2xl border border-gray-200 bg-white p-3 shadow-sm md:p-4">
      <!-- Weekday tabs -->
      <div class="flex gap-1 overflow-x-auto pb-0.5" role="tablist" aria-label="依星期篩選">
        <button
          v-for="tab in weekdayTabs"
          :key="tab.key"
          role="tab"
          :aria-selected="filterState.weekday === tab.key"
          :disabled="loading"
          class="min-h-11 shrink-0 rounded-md px-3 py-1.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-50 md:min-h-0"
          :class="filterState.weekday === tab.key
            ? 'bg-primary-700 text-white shadow-sm'
            : 'bg-gray-100 text-gray-800 hover:bg-gray-200 hover:text-gray-950'"
          @click="filterState.weekday = tab.key"
        >{{ tab.label }}</button>
      </div>

      <div class="flex items-center justify-between gap-3 border-t border-gray-100 pt-3 md:hidden">
        <p class="text-sm text-gray-500">
          顯示 <strong class="font-extrabold text-gray-900">{{ filteredSeasonal.length }}</strong> / {{ seasonal.length }} 部
        </p>
        <button
          type="button"
          class="flex min-h-11 shrink-0 items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 text-sm font-bold text-gray-700 shadow-sm"
          @click="filterPanelOpen = true"
        >
          <UIcon name="i-lucide-sliders-horizontal" class="size-4 text-primary-600" />
          篩選
          <span
            v-if="activeFilterCount > 0"
            class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary-600 px-1.5 text-[11px] font-bold text-white"
          >{{ activeFilterCount }}</span>
        </button>
      </div>

      <!-- Genre filter + 更多篩選 on same row -->
      <div v-if="filterOptions.genres.length > 0" class="hidden items-start gap-2 border-t border-gray-100 pt-3 md:flex">
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
      <div v-if="activeChips.length > 0" class="flex flex-wrap items-center gap-1.5 overflow-x-auto border-t border-gray-100 pt-3 max-md:flex-nowrap">
        <button
          v-for="chip in activeChips"
          :key="chip.label"
          class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold transition-colors"
          :class="chip.genre ? 'hover:opacity-80' : 'bg-primary-50 text-primary-700 hover:bg-primary-100'"
          :style="chip.genre ? { backgroundColor: tagColor(chip.label).bg, color: tagColor(chip.label).text } : {}"
          @click="chip.clear()"
        >
          {{ chip.label }}
          <UIcon name="i-lucide-x" class="size-3" />
        </button>
        <button class="shrink-0 text-xs text-gray-400 underline underline-offset-2 hover:text-gray-700" @click="resetFilters()">清除全部</button>
        <span class="ml-auto text-xs text-gray-500 max-md:hidden">顯示 <strong class="text-gray-900">{{ filteredSeasonal.length }}</strong> / {{ seasonal.length }} 部</span>
      </div>
    </div>

    <!-- Loading skeleton: fills roughly one viewport at the widest (5-col) breakpoint -->
    <div v-if="loading" class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-5">
      <div v-for="i in 20" :key="i" class="aspect-3/4 w-full animate-pulse rounded-lg bg-gray-200" />
    </div>

    <div v-else-if="!loading && filteredSeasonal.length === 0" class="rounded-xl border border-dashed border-gray-200 p-8 text-center text-gray-500">
      <UIcon name="i-lucide-calendar-x" class="mx-auto mb-2 size-8 text-gray-300" />
      <p class="text-sm font-medium">這個篩選條件目前沒有資料</p>
      <p class="mt-1 text-xs text-gray-400">試試切換星期或清除篩選條件</p>
    </div>

    <template v-else>
      <AnimeVirtualGrid :items="filteredSeasonal">
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
    </template>

    <SeasonalFilterPanel
      v-model:open="filterPanelOpen"
      v-model:year="seasonalControls.year"
      v-model:season="seasonalControls.season"
      v-model:source-tag="filterState.sourceTag"
      v-model:genre-tags="filterState.genreTags"
      v-model:actor="filterState.actor"
      v-model:status="filterState.seasonalStatus"
      :anime-list="seasonal"
      @reset="resetFilters()"
    />
  </div>
</template>
