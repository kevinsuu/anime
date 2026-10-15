<script setup lang="ts">
import { isGenreTag } from '../../../composables/useSeasonalCatalog'
import { tagColor } from '../../../utils/normalize'
import { seasonMonthLabels } from '../../../utils/season'

const route = useRoute()
const api = useApi()

// SSR-fetched (public endpoint) so the shared collection link paints its
// content immediately instead of a client-side loading spinner.
const { data, pending: loading, error: fetchError } = await useAsyncData(
  `public-collection-${route.params.slug}`,
  async () => (await api.publicCollection(route.params.slug as string)).item
)

const error = computed(() => fetchError.value ? (fetchError.value.message || '找不到此清單') : '')
const selectedTags = ref<string[]>([])

const tagOptions = computed(() => {
  const counts = new Map<string, number>()

  for (const item of data.value?.list_items ?? []) {
    for (const tag of item.anime.tags) {
      if (isGenreTag(tag)) counts.set(tag, (counts.get(tag) ?? 0) + 1)
    }
  }

  return [...counts.entries()]
    .sort(([tagA, countA], [tagB, countB]) => countB - countA || tagA.localeCompare(tagB))
    .map(([tag, count]) => ({ tag, count }))
})

const filteredItems = computed(() => {
  const items = data.value?.list_items ?? []
  if (selectedTags.value.length === 0) return items

  return items.filter(item => selectedTags.value.some(tag => item.anime.tags.includes(tag)))
})

function toggleTag(tag: string) {
  selectedTags.value = selectedTags.value.includes(tag)
    ? selectedTags.value.filter(selected => selected !== tag)
    : [...selectedTags.value, tag]
}

watch(() => route.params.slug, () => {
  selectedTags.value = []
})
</script>

<template>
  <div class="space-y-6">
    <div v-if="loading" class="space-y-3">
      <div class="h-8 w-48 animate-pulse rounded bg-gray-200" />
      <div class="h-4 w-24 animate-pulse rounded bg-gray-200" />
    </div>

    <UAlert v-else-if="error" color="error" :title="error" />

    <template v-else-if="data">
      <header class="space-y-1">
        <p class="text-xs font-extrabold uppercase tracking-widest text-primary-600">公開清單</p>
        <h1 class="text-3xl font-extrabold tracking-tight text-gray-950">{{ data.name }}</h1>
        <p class="text-sm text-gray-500">共 {{ data.count }} 部作品</p>
      </header>

      <section
        v-if="tagOptions.length > 0"
        aria-label="作品分類篩選"
        class="space-y-3 rounded-xl border border-gray-200 bg-white p-4"
      >
        <div class="flex items-center justify-between gap-3">
          <p class="text-sm font-bold text-gray-900">作品分類</p>
          <button
            v-if="selectedTags.length > 0"
            type="button"
            class="min-h-9 rounded-lg px-2 text-sm font-semibold text-primary-700 hover:bg-primary-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
            @click="selectedTags = []"
          >
            清除分類
          </button>
        </div>
        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="min-h-9 rounded-full px-3 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
            :class="selectedTags.length === 0
              ? 'bg-primary-600 text-white'
              : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
            :aria-pressed="selectedTags.length === 0"
            @click="selectedTags = []"
          >
            全部 {{ data.list_items.length }}
          </button>
          <button
            v-for="option in tagOptions"
            :key="option.tag"
            type="button"
            class="min-h-9 rounded-full px-3 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
            :class="selectedTags.includes(option.tag) ? 'ring-2 ring-primary-500' : 'hover:opacity-80'"
            :style="{ backgroundColor: tagColor(option.tag).bg, color: tagColor(option.tag).text }"
            :aria-pressed="selectedTags.includes(option.tag)"
            @click="toggleTag(option.tag)"
          >
            {{ option.tag }}
            <span class="ml-1 text-xs opacity-60">{{ option.count }}</span>
          </button>
        </div>
        <p class="text-xs text-gray-500">
          顯示 {{ filteredItems.length }} / {{ data.list_items.length }} 部
        </p>
      </section>

      <p
        v-if="data.list_items.length === 0"
        class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500"
      >
        這份清單目前沒有作品。
      </p>
      <p
        v-else-if="filteredItems.length === 0"
        class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500"
      >
        沒有符合所選分類的作品。
      </p>

      <div
        v-else
        class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-5 md:gap-3 lg:grid-cols-6"
      >
        <NuxtLink
          v-for="li in filteredItems"
          :key="li.id"
          :to="`/anime/${li.anime.id}`"
          class="group space-y-1.5"
        >
          <div class="relative aspect-3/4 w-full overflow-hidden rounded-lg bg-gray-800">
            <img
              v-if="li.anime.image_url"
              :src="li.anime.image_url"
              :alt="li.anime.name"
              loading="lazy"
              decoding="async"
              width="300"
              height="400"
              class="h-full w-full object-cover transition-transform duration-200 group-hover:scale-105"
            />
            <div v-else class="grid h-full w-full place-items-center text-2xl font-bold text-white">
              {{ li.anime.name.slice(0, 1) }}
            </div>
            <span
              v-if="li.watched"
              class="absolute left-1 top-1 rounded-full bg-green-500 px-2 py-0.5 text-[10px] font-bold text-white"
            >已看</span>
          </div>
          <p
            v-if="li.rating !== null"
            role="img"
            :aria-label="`評分 ${li.rating} 星`"
            class="flex items-center gap-1 text-sm font-bold text-amber-600"
          >
            {{ li.rating }}
            <UIcon name="i-lucide-star" class="size-3.5 fill-amber-400 text-amber-500" aria-hidden="true" />
          </p>
          <p class="line-clamp-2 text-xs font-semibold text-gray-800 group-hover:text-primary-600">
            {{ li.anime.name }}
          </p>
          <p v-if="li.anime.season_year" class="text-[10px] text-gray-400">
            {{ li.anime.season_year }}年 {{ seasonMonthLabels[li.anime.season_code] ?? li.anime.season_code }}
          </p>
        </NuxtLink>
      </div>
    </template>
  </div>
</template>
