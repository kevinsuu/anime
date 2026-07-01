<script setup lang="ts">
import type { Anime } from '../utils/normalize'

const props = defineProps<{
  anime: Anime
  inList: boolean
  watched: boolean
}>()

defineEmits<{ add: [animeId: number] }>()

const badgeColors = ['warning', 'info', 'success', 'error'] as const

function badgeColorFor(animeId: number) {
  return badgeColors[animeId % badgeColors.length]
}

function timeLabel(airDate: string | null): string {
  if (!airDate) return '未定'
  const match = airDate.match(/(?:T|\s)(\d{1,2}:\d{2})/)
  return match ? match[1] : '首播'
}
</script>

<template>
  <button
    type="button"
    class="group relative aspect-[3/4] w-full overflow-hidden rounded-md bg-gray-800 text-left"
    @click="$emit('add', anime.id)"
  >
    <img
      v-if="anime.imageUrl"
      :src="anime.imageUrl"
      :alt="anime.name"
      loading="lazy"
      class="h-full w-full object-cover transition-transform duration-200 group-hover:scale-105"
    />
    <div v-else class="grid h-full w-full place-items-center bg-primary-700 text-3xl font-bold text-white">
      {{ anime.name.slice(0, 1) }}
    </div>

    <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/80 via-black/10 to-transparent" />

    <UBadge
      :color="badgeColorFor(anime.id)"
      class="absolute right-1 top-1"
      size="sm"
    >
      {{ timeLabel(anime.airDate) }}
    </UBadge>

    <UBadge
      v-if="inList"
      :color="watched ? 'success' : 'neutral'"
      class="absolute left-1 top-1"
      size="sm"
    >
      {{ watched ? '已看' : '已加入' }}
    </UBadge>

    <UBadge
      v-if="anime.streams.length > 0"
      color="primary"
      variant="solid"
      icon="i-lucide-play"
      class="absolute bottom-1 right-1"
      size="sm"
    >
      {{ anime.streams.length }}
    </UBadge>

    <div class="absolute inset-x-1 bottom-1 pr-8">
      <h3 class="line-clamp-2 text-xs font-bold text-white drop-shadow">
        {{ anime.name }}
      </h3>
      <p v-if="anime.titleJa" class="line-clamp-1 text-[10px] text-gray-300 drop-shadow">
        {{ anime.titleJa }}
      </p>
    </div>
  </button>
</template>
