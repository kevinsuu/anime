<script setup lang="ts">
import { weekdayTabs, useSeasonalCatalog } from '../composables/useSeasonalCatalog'
import { normalizeAnime, normalizeListItem } from '../utils/normalize'
import type { Anime, ListItem } from '../utils/normalize'

// ref/computed/reactive/onMounted 由 Nuxt 自動匯入，不需要手動 import 'vue'
const api = useApi()
const { session, isAuthed } = useSession()
const { state: filterState, filterSeasonal, activeFilterCount } = useSeasonalCatalog()

const seasonalControls = reactive({
  year: new Date().getFullYear(),
  season: (() => {
    const month = new Date().getMonth() + 1
    return month <= 3 ? 'winter' : month <= 6 ? 'spring' : month <= 9 ? 'summer' : 'fall'
  })()
})

const seasonal = ref<Anime[]>([])
const list = ref<ListItem[]>([])
const loading = ref(false)
const error = ref('')
const notice = ref('')
const filterPanelOpen = ref(false)

const listByAnimeId = computed(() => {
  const map = new Map<number, ListItem>()
  list.value.forEach(item => map.set(item.anime.id, item))
  return map
})

const filteredSeasonal = computed(() => filterSeasonal(seasonal.value, listByAnimeId.value))

async function loadSeasonal() {
  loading.value = true
  error.value = ''
  try {
    const result = await api.searchAnime('', { year: seasonalControls.year, season: seasonalControls.season })
    seasonal.value = (result.items || []).map(normalizeAnime)
  } catch (err: any) {
    error.value = err.message || '載入失敗'
  } finally {
    loading.value = false
  }
}

async function loadMyList() {
  if (!session.token) return
  try {
    const result = await api.myList()
    list.value = (result.items || []).map(normalizeListItem)
  } catch {
    // 清單載入失敗不阻擋新番表瀏覽，沿用既有行為（不顯示清單狀態即可）
  }
}

async function addAnime(animeId: number) {
  if (!isAuthed.value) return navigateTo('/login')
  try {
    await api.addToList(animeId)
    await loadMyList()
    notice.value = '已加入你的清單'
  } catch (err: any) {
    error.value = err.message || '加入失敗'
  }
}

onMounted(async () => {
  await loadSeasonal()
  await loadMyList()
})
</script>

<template>
  <div class="space-y-4">
    <header>
      <p class="text-xs font-bold uppercase text-amber-600">新番表</p>
      <h1 class="text-3xl font-bold">{{ seasonalControls.year }}年 {{ seasonalControls.season }} 新番表</h1>
    </header>

    <UAlert v-if="error" color="error" :title="error" />
    <UAlert v-if="notice && !error" color="success" :title="notice" />

    <div class="flex gap-1 overflow-x-auto border-b border-gray-200 pb-2">
      <UButton
        v-for="tab in weekdayTabs"
        :key="tab.key"
        size="sm"
        :color="filterState.weekday === tab.key ? 'primary' : 'neutral'"
        :variant="filterState.weekday === tab.key ? 'solid' : 'ghost'"
        @click="filterState.weekday = tab.key"
      >
        {{ tab.label }}
      </UButton>
    </div>

    <div class="flex justify-end">
      <UButton color="neutral" variant="outline" icon="i-lucide-sliders-horizontal" @click="filterPanelOpen = true">
        篩選<template v-if="activeFilterCount > 0"> ({{ activeFilterCount }})</template>
      </UButton>
    </div>

    <div v-if="filteredSeasonal.length === 0 && !error" class="rounded-md border border-dashed border-gray-300 p-6 text-center text-gray-500">
      這個篩選條件目前沒有資料，試試切換星期。
    </div>

    <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-7">
      <AnimeGridCard
        v-for="anime in filteredSeasonal"
        :key="anime.id"
        :anime="anime"
        :in-list="listByAnimeId.has(anime.id)"
        :watched="Boolean(listByAnimeId.get(anime.id)?.watched)"
        @add="addAnime"
      />
    </div>

    <SeasonalFilterPanel
      v-model:open="filterPanelOpen"
      v-model:year="seasonalControls.year"
      v-model:season="seasonalControls.season"
      v-model:category="filterState.seasonalCategory"
      v-model:status="filterState.seasonalStatus"
    />
  </div>
</template>
