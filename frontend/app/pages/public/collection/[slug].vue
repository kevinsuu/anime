<script setup lang="ts">
import { seasonMonthLabels } from '../../../utils/season'

const route = useRoute()
const api = useApi()

// SSR-fetched (public endpoint) so the shared collection link paints its
// content immediately instead of a client-side loading spinner.
const { data, pending: loading, error: fetchError } = await useAsyncData(
  `public-collection-${route.params.slug}`,
  async () => (await api.publicCollection(route.params.slug as string)).item as {
    name: string; count: number; list_items: any[]
  }
)

const error = computed(() => fetchError.value ? (fetchError.value.message || '找不到此清單') : '')
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

      <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6">
        <NuxtLink
          v-for="li in data.list_items"
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
