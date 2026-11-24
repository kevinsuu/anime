<script setup lang="ts">
import { weekdayTabs, useSeasonalCatalog, deriveFilterOptions } from '../composables/useSeasonalCatalog'
import {
  HIGH_PRIORITY_IMAGE_COUNT,
  INITIAL_EAGER_IMAGE_COUNT
} from '../composables/useLazyLoad'
import { normalizeAnimeSummary, tagColor } from '../utils/normalize'
import type { AnimeSummary } from '../utils/normalize'
import { isSeasonSelection, seasonMonthLabels, seasonSelection, shiftSeason } from '../utils/season'
import type { SeasonSelection } from '../utils/season'
import { serializeJsonLd, SITE_URL } from '../utils/seo'
import {
  buildSeasonalFaq,
  relatedSeasons,
  seasonHeading,
  seasonNameLabels,
  summarizeSeason
} from '../utils/seasonalSeo'

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

function seasonRoute(offset: number) {
  const selection = shiftSeason(seasonalControls, offset)
  return {
    path: '/',
    query: {
      ...route.query,
      year: String(selection.year),
      season: selection.season
    }
  }
}

function preventSeasonNavigationWhileLoading(event: MouseEvent) {
  if (loading.value) event.preventDefault()
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
  bootstrapLoading,
  bootstrapError,
  isInList,
  isWatched,
  retryCardStatuses,
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
    const labels: Record<string, string> = { listed: '已加入清單', unlisted: '未加入清單', watched: '已看完', queued: '未看完', 'with-cover': '有封面' }
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
const seasonLabel = computed(() => `${seasonalControls.year}年${seasonMonthLabels[seasonalControls.season]}`)
const seasonName = computed(() => seasonNameLabels[seasonalControls.season])
const seasonalSummary = computed(() => summarizeSeason(seasonal.value, seasonalControls))
const seasonalFaq = computed(() => buildSeasonalFaq(seasonalControls, seasonalSummary.value))
// The upcoming season is the furthest one worth linking: its line-up is
// usually announced a few weeks before it airs.
const upcomingSeason = shiftSeason(seasonSelection(undefined, undefined), 1)
const relatedSeasonLinks = computed(() => relatedSeasons(seasonalControls, upcomingSeason).map(selection => ({
  key: `${selection.year}-${selection.season}`,
  label: `${seasonHeading(selection)}新番`,
  to: { path: '/', query: { year: String(selection.year), season: selection.season } }
})))
const countText = computed(() => seasonal.value.length > 0 ? `共${seasonal.value.length}部` : '')
const topGenresText = computed(() => seasonalSummary.value.genres.slice(0, 4).map(item => item.tag).join('、'))
const seasonalTitle = computed(() => `${seasonLabel.value}新番表（${seasonName.value}新番）${countText.value}｜播出時間與觀看平台－動漫庫`)
const seasonalDescription = computed(() => [
  `${seasonHeading(seasonalControls)}動畫新番${seasonal.value.length > 0 ? `共 ${seasonal.value.length} 部` : '總覽'}，`,
  '整理每週播出時間表、首播日期、題材分類、聲優與觀看平台。',
  topGenresText.value ? `熱門題材：${topGenresText.value}。` : ''
].join(''))
const pageTitle = computed(() => isBrandHomepage.value
  ? `動漫庫｜${seasonLabel.value}動漫新番表、動畫資料庫與追番收藏`
  : seasonalTitle.value)
const pageDescription = computed(() => isBrandHomepage.value
  ? `動漫庫提供每季動漫新番表：本季${seasonHeading(seasonalControls)}新番${seasonal.value.length > 0 ? `共 ${seasonal.value.length} 部` : ''}，含播出時間、角色聲優、觀看平台，以及完整動漫資料庫與個人追番收藏。`
  : seasonalDescription.value)
const canonicalUrl = computed(() => hasExplicitSeasonQuery.value
  ? `${SITE_URL}/?year=${seasonalControls.year}&season=${seasonalControls.season}`
  : `${SITE_URL}/`)
// A season with no announced titles yet is a thin page; keep it crawlable but
// unindexed. Only decide this from a successful response so a transient API
// error never tells search engines to drop the page.
const isIndexableSeason = computed(() => Boolean(fetchError.value) || loading.value || seasonal.value.length > 0)
const seasonalStructuredData = computed(() => {
  const pageId = `${canonicalUrl.value}#page`
  const itemListId = `${canonicalUrl.value}#anime-list`

  return {
    '@context': 'https://schema.org',
    '@graph': [
      {
        '@type': 'CollectionPage',
        '@id': pageId,
        url: canonicalUrl.value,
        name: pageTitle.value,
        description: pageDescription.value,
        inLanguage: 'zh-Hant',
        isPartOf: { '@id': `${SITE_URL}/#website` },
        mainEntity: { '@id': itemListId }
      },
      ...(seasonalFaq.value.length > 0
        ? [{
            '@type': 'FAQPage',
            '@id': `${canonicalUrl.value}#faq`,
            inLanguage: 'zh-Hant',
            mainEntity: seasonalFaq.value.map(item => ({
              '@type': 'Question',
              name: item.question,
              acceptedAnswer: { '@type': 'Answer', text: item.answer }
            }))
          }]
        : []),
      {
        '@type': 'ItemList',
        '@id': itemListId,
        name: `${seasonHeading(seasonalControls)}新番表`,
        numberOfItems: seasonal.value.length,
        itemListElement: seasonal.value.map((item, index) => ({
          '@type': 'ListItem',
          position: index + 1,
          url: `${SITE_URL}/anime/${item.id}`,
          name: item.name
        }))
      }
    ]
  }
})

useSeoMeta({
  title: () => pageTitle.value,
  description: () => pageDescription.value,
  ogTitle: () => pageTitle.value,
  ogDescription: () => pageDescription.value,
  ogSiteName: '動漫庫',
  ogType: 'website',
  robots: () => isIndexableSeason.value ? 'index, follow' : 'noindex, follow'
})
useHead({
  link: [{ rel: 'canonical', href: () => canonicalUrl.value }],
  script: [{
    key: 'seasonal-anime-list',
    type: 'application/ld+json',
    innerHTML: () => serializeJsonLd(seasonalStructuredData.value)
  }]
})
</script>

<template>
  <div class="space-y-3 md:space-y-4">
    <!-- Header: season navigator -->
    <header class="flex items-center justify-between gap-3 md:gap-4">
      <div class="space-y-0.5">
        <p class="text-xs font-extrabold uppercase tracking-widest text-primary-700">新番表</p>
        <!-- Font size follows the viewport so the whole title fits on one line on
             phones; each part is nowrap so a wrap never splits「（秋季）」. -->
        <h1 class="text-[clamp(1rem,4.8vw,1.25rem)] leading-7 font-extrabold tracking-tight text-gray-950 md:text-2xl md:leading-8">
          <span class="whitespace-nowrap">{{ seasonalControls.year }}年 {{ seasonMonthLabels[seasonalControls.season] }} 新番表</span>
          <span class="whitespace-nowrap text-[0.8em] leading-none font-bold text-gray-500">（{{ seasonName }}）</span>
        </h1>
      </div>
      <!-- Season prev/next arrows -->
      <div class="flex items-center gap-1 shrink-0">
        <NuxtLink
          :to="seasonRoute(-1)"
          replace
          :aria-disabled="loading"
          :tabindex="loading ? -1 : undefined"
          class="flex h-11 w-11 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 md:h-9 md:w-9"
          :class="loading ? 'pointer-events-none opacity-40' : undefined"
          aria-label="上一季"
          @click="preventSeasonNavigationWhileLoading"
        >
          <UIcon name="i-lucide-chevron-left" class="size-4" />
        </NuxtLink>
        <div class="hidden min-w-28 text-center md:block">
          <span class="text-sm font-bold text-gray-700">
            {{ seasonalControls.year }} · {{ seasonMonthLabels[seasonalControls.season] }}
          </span>
        </div>
        <NuxtLink
          :to="seasonRoute(1)"
          replace
          :aria-disabled="loading"
          :tabindex="loading ? -1 : undefined"
          class="flex h-11 w-11 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 md:h-9 md:w-9"
          :class="loading ? 'pointer-events-none opacity-40' : undefined"
          aria-label="下一季"
          @click="preventSeasonNavigationWhileLoading"
        >
          <UIcon name="i-lucide-chevron-right" class="size-4" />
        </NuxtLink>
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
          class="min-h-11 min-w-fit flex-1 basis-0 rounded-md px-2 py-1.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-50 md:min-h-0 md:flex-none md:basis-auto md:px-3"
          :class="filterState.weekday === tab.key
            ? 'bg-primary-700 text-white shadow-sm'
            : 'bg-gray-100 text-gray-800 hover:bg-gray-200 hover:text-gray-950'"
          @click="filterState.weekday = tab.key"
        >{{ tab.label }}</button>
      </div>

      <div class="flex items-center justify-between gap-3 border-t border-gray-100 pt-3 md:hidden">
        <div class="min-w-0 space-y-2">
          <p class="text-sm text-gray-500">
            顯示 <strong class="font-extrabold text-gray-900">{{ filteredSeasonal.length }}</strong> / {{ seasonal.length }} 部
          </p>
          <MobileCardGestureHint v-if="!bootstrapError" />
        </div>
        <button
          type="button"
          class="flex min-h-11 shrink-0 items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 text-sm font-bold text-gray-700 shadow-sm"
          @click="filterPanelOpen = true"
        >
          <UIcon name="i-lucide-sliders-horizontal" class="size-4 text-primary-600" />
          篩選
          <span
            v-if="activeFilterCount > 0"
            class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary-700 px-1.5 text-xs font-bold text-white"
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
              ? 'border-primary-700 bg-primary-700 text-white'
              : 'hover:border-gray-300'"
            :style="filterState.genreTags.includes(item.tag) ? {} : { backgroundColor: tagColor(item.tag).bg, color: tagColor(item.tag).text }"
            @click="toggleGenreTag(item.tag)"
          >{{ item.tag }}<span class="ml-1 opacity-80">({{ item.count }})</span></button>
        </div>

        <button
          class="flex shrink-0 items-center gap-1.5 rounded border border-gray-300 bg-white px-3 py-1 text-xs font-semibold text-gray-700 shadow-sm transition-colors hover:border-gray-500 hover:bg-gray-50"
          @click="filterPanelOpen = true"
        >
          <UIcon name="i-lucide-sliders-horizontal" class="size-3.5 text-gray-500" />
          更多篩選
          <span
            v-if="activeFilterCount > 0"
            class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary-700 px-1 text-xs font-bold text-white"
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
        <button class="shrink-0 text-xs text-gray-500 underline underline-offset-2 hover:text-gray-700" @click="resetFilters()">清除全部</button>
        <span class="ml-auto text-xs text-gray-500 max-md:hidden">顯示 <strong class="text-gray-900">{{ filteredSeasonal.length }}</strong> / {{ seasonal.length }} 部</span>
      </div>
    </div>

    <!-- The catalog can render while personal status bootstrap syncs in the background. -->
    <div
      v-if="loading"
      data-anime-card-grid-loading
      class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-5 md:gap-3"
    >
      <div v-for="i in 20" :key="i" class="aspect-3/4 w-full animate-pulse rounded-lg bg-gray-200" />
    </div>

    <template v-else>
      <!-- Card titles are h3; this keeps the outline h1 → h2 → h3 for screen
           readers and heading navigation. -->
      <h2 class="sr-only">{{ seasonHeading(seasonalControls) }}新番作品列表</h2>

      <AnimeCardStatusError
        v-if="bootstrapError"
        :message="bootstrapError"
        @retry="retryCardStatuses"
      />

      <!-- No page-level image gate: like other image-heavy grids, each card owns
           its placeholder and covers appear progressively as they arrive, so the
           SSR-rendered covers paint before hydration instead of waiting for the
           slowest first-screen request. -->
      <div v-if="filteredSeasonal.length === 0" class="rounded-xl border border-dashed border-gray-200 p-8 text-center text-gray-500">
        <UIcon name="i-lucide-calendar-x" class="mx-auto mb-2 size-8 text-gray-300" />
        <p class="text-sm font-medium">這個篩選條件目前沒有資料</p>
        <p class="mt-1 text-xs text-gray-500">試試切換星期或清除篩選條件</p>
      </div>

      <AnimeVirtualGrid v-else :items="filteredSeasonal">
        <template #default="{ item: anime, index }">
          <AnimeGridCard
            :key="anime.id"
            :anime="anime"
            :in-list="isInList(anime.id)"
            :watched="isWatched(anime.id)"
            :status-pending="bootstrapLoading"
            :status="statusesByAnimeId.get(anime.id)"
            :collections="collections"
            :popover-open="activePopoverAnimeId === anime.id"
            :eager-load="index < INITIAL_EAGER_IMAGE_COUNT"
            :high-priority="index < HIGH_PRIORITY_IMAGE_COUNT"
            :show-actions="!bootstrapError"
            @add-to-list="toggleAnimeInList"
            @mark-watched="markWatched"
            @toggle-collection="(col) => toggleCollection(anime.id, col)"
            @open-popover="activePopoverAnimeId = anime.id"
            @close-popover="activePopoverAnimeId = null"
          />
        </template>
      </AnimeVirtualGrid>
    </template>

    <!-- Answer-first season overview for search and answer engines. It sits
         below the grid so the browsing UI stays compact, and every sentence is
         derived from the listed titles (mirrored by the FAQPage JSON-LD). -->
    <section
      v-if="!loading && seasonalFaq.length > 0"
      aria-labelledby="seasonal-answer-title"
      class="space-y-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm md:p-5"
    >
      <h2 id="seasonal-answer-title" class="text-base font-extrabold text-gray-950 md:text-lg">
        {{ seasonHeading(seasonalControls) }}新番總覽
      </h2>
      <dl class="space-y-3">
        <div v-for="item in seasonalFaq" :key="item.question">
          <dt class="text-sm font-bold text-gray-900">{{ item.question }}</dt>
          <dd class="mt-1 text-sm leading-6 text-gray-600">{{ item.answer }}</dd>
        </div>
      </dl>
    </section>

    <nav aria-label="其他季度新番" class="flex flex-wrap gap-x-4 gap-y-2 text-sm">
      <NuxtLink
        v-for="link in relatedSeasonLinks"
        :key="link.key"
        :to="link.to"
        class="inline-flex min-h-6 items-center font-semibold text-primary-700 underline-offset-2 hover:underline"
      >
        {{ link.label }}
      </NuxtLink>
      <NuxtLink to="/catalog" class="inline-flex min-h-6 items-center font-semibold text-primary-700 underline-offset-2 hover:underline">
        動漫資料庫
      </NuxtLink>
    </nav>

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
