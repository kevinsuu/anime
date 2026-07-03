<script setup lang="ts">
import type { Anime, ListItem, Collection } from '../utils/normalize'

const props = withDefaults(defineProps<{
  anime: Anime
  inList: boolean
  watched: boolean
  listItem?: ListItem
  collections: Collection[]
  popoverOpen: boolean
  eagerLoad?: boolean
}>(), {
  eagerLoad: false
})

const emit = defineEmits<{
  addToList: [animeId: number]
  markWatched: [animeId: number]
  toggleCollection: [col: Collection]
  openPopover: []
  closePopover: []
}>()

const weekdayColors: Record<string, string> = {
  '一': 'bg-red-500',
  '二': 'bg-orange-500',
  '三': 'bg-yellow-500',
  '四': 'bg-green-500',
  '五': 'bg-teal-500',
  '六': 'bg-blue-500',
  '日': 'bg-purple-500',
}

interface AirInfo {
  dateLabel: string
  weekday: string
  time: string
  weekdayColor: string
}

function parseAirInfo(text: string | null, airDate: string | null): AirInfo {
  const fallback: AirInfo = {
    dateLabel: airDate ? formatDate(airDate) + '首播' : '未定首播',
    weekday: '', time: '', weekdayColor: 'bg-gray-500',
  }
  if (!text) return fallback

  const dateMatch = text.match(/(\d{1,2})月(\d{1,2})日/)
  const dateLabel = dateMatch ? `${dateMatch[1]}月${dateMatch[2]}日首播` : (airDate ? formatDate(airDate) + '首播' : '未定首播')

  const wdMatch = text.match(/每週([一二三四五六日])|週([一二三四五六日])/)
  const weekday = wdMatch ? (wdMatch[1] || wdMatch[2]) : ''

  const timeMatch = text.match(/(\d{1,2})時(\d{0,2})分?/)
  let time = ''
  if (timeMatch) {
    time = `${timeMatch[1].padStart(2, '0')}:${(timeMatch[2] || '0').padStart(2, '0')}`
  }

  return { dateLabel, weekday, time, weekdayColor: weekdayColors[weekday] ?? 'bg-gray-500' }
}

function formatDate(airDate: string): string {
  const m = airDate.match(/^\d{4}-(\d{2})-(\d{2})/)
  return m ? `${parseInt(m[1])}月${parseInt(m[2])}日` : ''
}

const toast = useToast()

function isInCollection(col: Collection): boolean {
  return props.listItem?.collections.some(c => c.id === col.id) ?? false
}

async function onCardClick() {
  try {
    await navigator.clipboard.writeText(props.anime.name)
    toast.add({ title: '已複製動漫名稱', description: props.anime.name, color: 'success' })
  } catch {
    // Clipboard API unavailable (e.g. insecure context) — navigation still proceeds
  }
}

function onAddToList(e: Event) {
  e.preventDefault()
  e.stopPropagation()
  emit('addToList', props.anime.id)
  // Only show collection popover for heart (add), not for check (watched)
  if (props.collections.length > 0) {
    props.popoverOpen ? emit('closePopover') : emit('openPopover')
  }
}

function onMarkWatched(e: Event) {
  e.preventDefault()
  e.stopPropagation()
  emit('markWatched', props.anime.id)
  // Don't open popover on mark-watched — just toggle watched state silently
  emit('closePopover')
}

function onToggleCollection(e: Event, col: Collection) {
  e.preventDefault()
  e.stopPropagation()
  emit('toggleCollection', col)
  // 不關閉 popover，讓用戶繼續勾選其他清單
}

const airInfo = computed(() => parseAirInfo(props.anime.airDateText, props.anime.airDate))

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && props.popoverOpen) emit('closePopover')
}

watch(() => props.popoverOpen, (open) => {
  if (open) window.addEventListener('keydown', onKeydown)
  else window.removeEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="relative">
    <NuxtLink
      :to="`/anime/${anime.id}`"
      class="group relative block aspect-3/4 w-full overflow-hidden rounded-lg bg-gray-800 transition-all"
      :class="watched ? 'ring-2 ring-green-400 ring-offset-1' : ''"
      @click="onCardClick"
    >
      <img
        v-if="anime.imageUrl"
        :src="anime.imageUrl"
        :alt="anime.name"
        :loading="eagerLoad ? 'eager' : 'lazy'"
        :fetchpriority="eagerLoad ? 'high' : 'auto'"
        width="300"
        height="400"
        class="h-full w-full object-cover transition-transform duration-200 group-hover:scale-105"
      />
      <div v-else class="grid h-full w-full place-items-center bg-primary-700 text-3xl font-bold text-white">
        {{ anime.name.slice(0, 1) }}
      </div>

      <!-- Gradient overlay -->
      <div class="pointer-events-none absolute inset-0 bg-linear-to-b from-black/60 via-transparent to-black/80" />

      <!-- Top-left: date label -->
      <div class="absolute left-0 top-0 p-1.5">
        <span class="block rounded-sm bg-black/50 px-1.5 py-0.5 text-[10px] font-bold leading-tight text-white backdrop-blur-sm">
          {{ airInfo.dateLabel }}
        </span>
      </div>

      <!-- Top-right: weekday + time -->
      <div
        v-if="airInfo.weekday"
        class="absolute right-0 top-0 flex flex-col items-center rounded-bl-lg p-1.5 text-white"
        :class="airInfo.weekdayColor"
      >
        <span class="text-[11px] font-extrabold leading-tight">{{ airInfo.weekday }}</span>
        <span class="text-[10px] font-bold leading-tight">{{ airInfo.time }}</span>
      </div>

      <!-- Top-right, below the date badge: episode count -->
      <div
        v-if="anime.episodeCount"
        class="absolute right-1.5 rounded-sm bg-black/50 px-1.5 py-0.5 text-[10px] font-bold leading-tight text-white backdrop-blur-sm"
        :class="airInfo.weekday ? 'top-11' : 'top-1.5'"
      >
        全{{ anime.episodeCount }}集
      </div>

      <!-- In-list status badge -->
      <UBadge
        v-if="inList && !watched"
        color="neutral"
        class="absolute left-1.5 top-9"
        size="sm"
      >
        已加入
      </UBadge>
      <!-- Watched badge (only when no green ring is enough) -->
      <UBadge
        v-if="watched"
        color="success"
        class="absolute left-1.5 top-9"
        size="sm"
      >
        已看
      </UBadge>

      <!-- Streams badge -->
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

      <!-- Title -->
      <div class="absolute inset-x-1.5 bottom-1 pr-7">
        <h3 class="line-clamp-3 text-xs font-bold leading-snug text-white drop-shadow">
          {{ anime.name }}
        </h3>
      </div>

      <!-- Action buttons -->
      <div
        class="absolute inset-x-0 bottom-0 flex translate-y-full justify-end gap-1 p-1.5 transition-transform duration-150 group-hover:translate-y-0"
      >
        <!-- Heart -->
        <button
          type="button"
          class="group/btn relative flex h-7 w-7 items-center justify-center rounded-full shadow-md transition-transform active:scale-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
          :class="inList ? 'bg-rose-500 text-white' : 'bg-white/90 text-gray-700 hover:bg-rose-500 hover:text-white'"
          :aria-label="inList ? '已收藏' : '加入清單'"
          @click="onAddToList"
        >
          <UIcon name="i-lucide-heart" class="size-3.5" :class="inList ? 'fill-current' : ''" />
          <span class="pointer-events-none absolute bottom-full right-0 mb-1.5 whitespace-nowrap rounded bg-gray-900 px-2 py-0.5 text-[11px] font-semibold text-white opacity-0 transition-opacity group-hover/btn:opacity-100">
            {{ inList ? '已收藏' : '加入清單' }}
          </span>
        </button>

        <!-- Check -->
        <button
          type="button"
          class="group/btn relative flex h-7 w-7 items-center justify-center rounded-full shadow-md transition-transform active:scale-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
          :class="watched ? 'bg-green-500 text-white' : 'bg-white/90 text-gray-700 hover:bg-green-500 hover:text-white'"
          :aria-label="watched ? '已看完' : '標記已看'"
          @click="onMarkWatched"
        >
          <UIcon name="i-lucide-check" class="size-3.5" />
          <span class="pointer-events-none absolute bottom-full right-0 mb-1.5 whitespace-nowrap rounded bg-gray-900 px-2 py-0.5 text-[11px] font-semibold text-white opacity-0 transition-opacity group-hover/btn:opacity-100">
            {{ watched ? '取消已看' : '標記已看' }}
          </span>
        </button>
      </div>
    </NuxtLink>

    <!-- Collection popover: absolute below card, right-aligned -->
    <Transition name="pop-fade">
      <div
        v-if="popoverOpen && collections.length > 0 && inList"
        class="absolute right-0 top-full z-30 mt-1 w-56 rounded-xl border border-gray-200 bg-white shadow-xl overflow-hidden"
        @click.stop
      >
        <div class="flex items-center justify-between border-b border-gray-100 px-3 py-2">
          <p class="text-xs font-bold text-gray-700">加入自訂清單</p>
          <button class="text-gray-400 hover:text-gray-700" @click.stop="emit('closePopover')">
            <UIcon name="i-lucide-x" class="size-3" />
          </button>
        </div>
        <div class="max-h-44 overflow-y-auto py-1">
          <button
            v-for="col in collections"
            :key="col.id"
            type="button"
            class="flex w-full items-center gap-2.5 px-3 py-2 text-xs transition hover:bg-gray-50"
            @click="onToggleCollection($event, col)"
          >
            <span
              class="flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded border transition"
              :class="isInCollection(col) ? 'border-primary-600 bg-primary-600' : 'border-gray-300'"
            >
              <UIcon v-if="isInCollection(col)" name="i-lucide-check" class="size-2 text-white" />
            </span>
            <span class="flex-1 truncate font-medium text-gray-900 text-left">{{ col.name }}</span>
            <span class="text-gray-400">{{ col.count }}</span>
          </button>
        </div>
        <div class="border-t border-gray-100 px-3 py-1.5">
          <NuxtLink to="/list" class="text-[11px] text-primary-600 hover:underline" @click.stop="emit('closePopover')">
            管理清單 →
          </NuxtLink>
        </div>
      </div>
    </Transition>

    <!-- Backdrop to close popover when clicking outside -->
    <div
      v-if="popoverOpen"
      class="fixed inset-0 z-20"
      @click="emit('closePopover')"
    />
  </div>
</template>

<style scoped>
.pop-fade-enter-active,
.pop-fade-leave-active {
  transition: opacity 0.12s ease, transform 0.12s ease;
}
.pop-fade-enter-from,
.pop-fade-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
