<script setup lang="ts">
import type { AnimeCardData, Collection } from '../utils/normalize'
import type { AnimeCardStatus } from '../composables/useAnimeCardStatuses'
import { createMobileCardGestureHandlers } from '../utils/mobileCardGestures'

const props = withDefaults(defineProps<{
  anime: AnimeCardData
  inList: boolean
  watched: boolean
  statusPending?: boolean
  status?: AnimeCardStatus
  collections?: Collection[]
  popoverOpen?: boolean
  eagerLoad?: boolean
  showActions?: boolean
}>(), {
  collections: () => [],
  popoverOpen: false,
  eagerLoad: false,
  showActions: true,
  statusPending: false
})

const emit = defineEmits<{
  addToList: [animeId: number]
  markWatched: [animeId: number]
  toggleCollection: [col: Collection]
  openPopover: []
  closePopover: []
}>()

const cardRef = ref<HTMLElement | null>(null)
const shouldLoad = useLazyLoad(cardRef, props.eagerLoad)

const imageLoaded = ref(props.eagerLoad)
const imageError = ref(false)
const imgEl = ref<HTMLImageElement | null>(null)
const hasUsableImage = computed(() => Boolean(props.anime.imageUrl) && !imageError.value)

// `load` already guarantees the bytes are available. Reveal immediately rather
// than waiting on decode(), which can remain pending while the main thread is
// busy during a fast scroll and unnecessarily keep the placeholder visible.
function revealImage() {
  if (!imgEl.value) return
  imageLoaded.value = true
}

onMounted(() => {
  // Image may already be cached/complete before @load can fire.
  if (imgEl.value?.complete && imgEl.value.naturalWidth > 0) revealImage()
})

const weekdayColors: Record<string, string> = {
  '一': 'bg-red-700',
  '二': 'bg-orange-700',
  '三': 'bg-yellow-700',
  '四': 'bg-green-700',
  '五': 'bg-teal-700',
  '六': 'bg-blue-700',
  '日': 'bg-purple-700',
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
  const dateMonth = dateMatch?.[1]
  const dateDay = dateMatch?.[2]
  const dateLabel = dateMonth && dateDay
    ? `${dateMonth}月${dateDay}日首播`
    : (airDate ? formatDate(airDate) + '首播' : '未定首播')

  const wdMatch = text.match(/每週([一二三四五六日])|週([一二三四五六日])/)
  const weekday = wdMatch?.[1] ?? wdMatch?.[2] ?? ''

  const timeMatch = text.match(/(\d{1,2})時(\d{0,2})分?/)
  let time = ''
  const hour = timeMatch?.[1]
  if (hour) {
    time = `${hour.padStart(2, '0')}:${(timeMatch?.[2] || '0').padStart(2, '0')}`
  }

  return { dateLabel, weekday, time, weekdayColor: weekdayColors[weekday] ?? 'bg-gray-500' }
}

function formatDate(airDate: string): string {
  const m = airDate.match(/^\d{4}-(\d{2})-(\d{2})/)
  const month = m?.[1]
  const day = m?.[2]
  return month && day ? `${parseInt(month)}月${parseInt(day)}日` : ''
}

function isInCollection(col: Collection): boolean {
  return props.status?.collectionIds.includes(col.id) ?? false
}

function toggleFavorite() {
  emit('addToList', props.anime.id)
  // Removing from the main list also removes collection memberships, so close
  // the popover. Only a newly-added title should offer collection choices.
  if (!props.inList && props.collections.length > 0) {
    props.popoverOpen ? emit('closePopover') : emit('openPopover')
  } else {
    emit('closePopover')
  }
}

function toggleWatched() {
  emit('markWatched', props.anime.id)
  // Don't open popover on mark-watched — just toggle watched state silently
  emit('closePopover')
}

function onAddToList(e: Event) {
  e.preventDefault()
  e.stopPropagation()
  toggleFavorite()
}

function onMarkWatched(e: Event) {
  e.preventDefault()
  e.stopPropagation()
  toggleWatched()
}

function onToggleCollection(e: Event, col: Collection) {
  e.preventDefault()
  e.stopPropagation()
  emit('toggleCollection', col)
  // 不關閉 popover，讓用戶繼續勾選其他清單
}

const airInfo = computed(() => parseAirInfo(props.anime.airDateText, props.anime.airDate))
const cardStatus = computed(() => {
  if (props.statusPending) return null
  return props.watched ? 'watched' : props.inList ? 'favorite' : null
})
const mobileGestures = createMobileCardGestureHandlers({
  isEnabled: () => props.showActions,
  onDoubleTap: toggleFavorite,
  onLongPress: toggleWatched,
  onNavigate: () => { void navigateTo(`/anime/${props.anime.id}`) }
})

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && props.popoverOpen) emit('closePopover')
}

watch(() => props.popoverOpen, (open) => {
  if (open) window.addEventListener('keydown', onKeydown)
  else window.removeEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  mobileGestures.dispose()
})
</script>

<template>
  <div
    ref="cardRef"
    class="anime-card-shell group/card relative"
    :class="cardStatus ? `anime-card-shell--${cardStatus}` : undefined"
    :data-card-status="cardStatus"
    :data-card-status-pending="statusPending || undefined"
  >
    <NuxtLink
      :to="`/anime/${anime.id}`"
      class="anime-card-link group relative block aspect-3/4 w-full touch-manipulation overflow-hidden rounded-lg bg-gray-800 transition-all duration-300"
      data-mobile-gesture-card
      draggable="false"
      @click.capture="mobileGestures.onClick"
      @contextmenu="mobileGestures.onContextMenu"
      @pointercancel="mobileGestures.onPointerCancel"
      @pointerdown="mobileGestures.onPointerDown"
      @pointerleave="mobileGestures.onPointerLeave"
      @pointermove="mobileGestures.onPointerMove"
      @pointerup="mobileGestures.onPointerUp"
    >
      <template v-if="hasUsableImage">
        <!-- Persistent placeholder layer sitting *under* the image. It is never
             removed via v-if: during fast scrolling the browser can defer
             painting a freshly-shown <img> for a frame or two, and if the
             placeholder were unmounted at that moment the card would flash
             fully blank. Keeping a grey layer beneath means the worst case is a
             grey card, never a white one. Pulses only while still loading. -->
        <div
          data-imgph
          class="absolute inset-0 bg-gray-200"
          :class="imageLoaded ? '' : 'animate-pulse'"
          aria-hidden="true"
        />
        <img
          ref="imgEl"
          :src="shouldLoad ? anime.imageUrl : undefined"
          :alt="anime.name"
          :loading="eagerLoad ? 'eager' : 'lazy'"
          :fetchpriority="eagerLoad ? 'high' : 'low'"
          decoding="async"
          width="300"
          height="400"
          class="relative h-full w-full object-cover transition-[opacity,transform] duration-300 group-hover:scale-105"
          :class="imageLoaded ? 'opacity-100' : 'opacity-0'"
          @load="revealImage"
          @error="imageError = true"
        />
      </template>
      <div
        v-else
        data-image-fallback
        class="grid h-full w-full place-items-center bg-gray-100 text-3xl font-bold text-gray-400 ring-1 ring-inset ring-gray-200"
      >
        {{ anime.name.slice(0, 1) }}
      </div>

      <!-- Gradient overlay -->
      <div
        data-card-gradient
        class="pointer-events-none absolute inset-0 bg-linear-to-b from-black/60 via-transparent to-black/80"
      />

      <!-- Top-left: date label -->
      <div class="absolute left-0 top-0 p-1.5">
        <span class="block rounded-sm bg-black/50 px-1.5 py-0.5 text-[10px] font-bold leading-tight text-white backdrop-blur-sm">
          {{ airInfo.dateLabel }}
        </span>
      </div>

      <!-- Top-right metadata: schedule, episode count, then streaming resources. -->
      <div
        v-if="airInfo.weekday || anime.episodeCount || anime.streamCount > 0"
        data-card-meta-stack
        class="absolute right-0 top-0 z-10 flex min-w-11 flex-col items-stretch overflow-hidden rounded-bl-lg text-white shadow-sm"
      >
        <div v-if="airInfo.weekday" class="flex flex-col items-center p-1.5" :class="airInfo.weekdayColor">
          <span class="text-[11px] font-extrabold leading-tight">{{ airInfo.weekday }}</span>
          <span class="text-[10px] font-bold leading-tight">{{ airInfo.time }}</span>
        </div>
        <span
          v-if="anime.episodeCount"
          data-episode-badge
          class="w-full bg-green-600 px-1.5 py-0.5 text-center text-[10px] font-bold leading-tight"
        >
          全{{ anime.episodeCount }}集
        </span>
        <span
          v-if="anime.streamCount > 0"
          data-stream-badge
          class="anime-card-stream flex w-full items-center justify-center gap-0.5 bg-gray-600 px-1.5 py-0.5 text-center text-[10px] font-bold leading-tight"
        >
          <UIcon name="i-lucide-play" class="size-3" aria-hidden="true" />
          {{ anime.streamCount }}
        </span>
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
      <!-- Watched state: a compact glass pill stays legible over light and dark covers. -->
      <div
        v-if="watched"
        class="absolute left-1.5 top-9 z-10 inline-flex items-center gap-1 rounded-full border border-emerald-200/70 bg-emerald-950/80 py-1 pl-1 pr-2 text-[10px] font-extrabold tracking-wide text-emerald-50 shadow-lg shadow-emerald-950/30 backdrop-blur-md"
      >
        <span class="grid size-4 place-items-center rounded-full bg-emerald-300 text-emerald-950 shadow-sm" aria-hidden="true">
          <UIcon name="i-lucide-check" class="size-2.5 stroke-3" />
        </span>
        已觀看
      </div>

      <!-- Title -->
      <div data-mobile-card-title class="anime-card-title absolute inset-x-1.5 bottom-1">
        <h3
          class="anime-card-heading line-clamp-3 text-xs font-bold leading-snug text-white drop-shadow max-md:line-clamp-2"
        >
          {{ anime.name }}
        </h3>
      </div>

    </NuxtLink>

      <!-- Action buttons are siblings of the navigation link, avoiding nested
           interactive controls. They stay over the artwork so virtual row
           height remains unchanged. -->
      <div
        v-if="showActions"
        class="anime-card-actions pointer-events-none absolute inset-x-0 bottom-0 z-20 flex translate-y-2 justify-end gap-1 p-1.5 opacity-0 transition-[opacity,transform] duration-150 group-hover/card:pointer-events-auto group-hover/card:translate-y-0 group-hover/card:opacity-100 group-focus-within/card:pointer-events-auto group-focus-within/card:translate-y-0 group-focus-within/card:opacity-100 max-md:pointer-events-auto max-md:translate-y-0 max-md:opacity-100"
      >
        <!-- Heart -->
        <button
          type="button"
          class="group/btn relative flex h-7 w-7 touch-manipulation items-center justify-center rounded-full transition-transform active:scale-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white max-md:h-11 max-md:w-11"
          :aria-label="inList ? '取消收藏' : '加入收藏'"
          @click="onAddToList"
        >
          <span
            data-action-surface
            class="grid size-7 place-items-center rounded-full shadow-md transition-colors max-md:size-9"
            :class="inList ? 'bg-rose-500 text-white' : 'bg-white/90 text-gray-700 group-hover/btn:bg-rose-500 group-hover/btn:text-white'"
          >
            <UIcon name="i-lucide-heart" class="size-3.5 max-md:size-[1.125rem]" :class="inList ? 'fill-current' : ''" />
          </span>
          <span class="pointer-events-none absolute bottom-full right-0 mb-1.5 whitespace-nowrap rounded bg-gray-900 px-2 py-0.5 text-[11px] font-semibold text-white opacity-0 transition-opacity group-hover/btn:opacity-100">
            {{ inList ? '取消收藏' : '加入收藏' }}
          </span>
        </button>

        <!-- Check -->
        <button
          type="button"
          class="group/btn relative flex h-7 w-7 touch-manipulation items-center justify-center rounded-full transition-transform active:scale-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white max-md:h-11 max-md:w-11"
          :aria-label="watched ? '已看完' : '標記已看'"
          @click="onMarkWatched"
        >
          <span
            data-action-surface
            class="grid size-7 place-items-center rounded-full shadow-md transition-colors max-md:size-9"
            :class="watched ? 'bg-green-500 text-white' : 'bg-white/90 text-gray-700 group-hover/btn:bg-green-500 group-hover/btn:text-white'"
          >
            <UIcon name="i-lucide-check" class="size-3.5 max-md:size-[1.125rem]" />
          </span>
          <span class="pointer-events-none absolute bottom-full right-0 mb-1.5 whitespace-nowrap rounded bg-gray-900 px-2 py-0.5 text-[11px] font-semibold text-white opacity-0 transition-opacity group-hover/btn:opacity-100">
            {{ watched ? '取消已看' : '標記已看' }}
          </span>
        </button>
      </div>

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
@property --status-border-angle {
  syntax: '<angle>';
  inherits: false;
  initial-value: 0deg;
}

.anime-card-shell::before {
  --status-border-a: transparent;
  --status-border-b: transparent;
  --status-border-c: transparent;
  content: '';
  position: absolute;
  z-index: 15;
  inset: 0;
  padding: 3px;
  border-radius: 0.5rem;
  background: repeating-conic-gradient(
    from var(--status-border-angle),
    var(--status-border-a) 0turn,
    var(--status-border-b) 0.125turn,
    var(--status-border-c) 0.25turn,
    var(--status-border-b) 0.375turn,
    var(--status-border-a) 0.5turn
  );
  opacity: 0;
  pointer-events: none;
  -webkit-mask:
    linear-gradient(#000 0 0) content-box,
    linear-gradient(#000 0 0);
  -webkit-mask-composite: xor;
  mask-composite: exclude;
}

.anime-card-shell--watched::before,
.anime-card-shell--favorite::before {
  opacity: 1;
  animation: status-border-flow 4s linear infinite;
}

.anime-card-shell--watched::before {
  --status-border-a: #16a34a;
  --status-border-b: #4ade80;
  --status-border-c: #86efac;
}

.anime-card-shell--favorite::before {
  --status-border-a: #db2777;
  --status-border-b: #f472b6;
  --status-border-c: #f9a8d4;
}

@keyframes status-border-flow {
  to {
    --status-border-angle: 360deg;
  }
}

@media (max-width: 47.999rem) {
  .anime-card-link {
    -webkit-touch-callout: none;
    user-select: none;
  }

  .anime-card-title {
    inset-inline: 0.5rem;
    bottom: 0.5rem;
    padding: 0;
  }

  .anime-card-heading {
    color: white;
    font-size: 0.8125rem;
    line-height: 1.35;
  }

  .anime-card-actions {
    top: auto;
    right: 0.25rem;
    bottom: 3.75rem;
    left: auto;
    width: 1px;
    height: 1px;
    gap: 0;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    clip-path: inset(50%);
    white-space: nowrap;
    pointer-events: none;
  }

  .anime-card-actions:focus-within {
    width: auto;
    height: auto;
    gap: 0.25rem;
    padding: 0.25rem;
    margin: 0;
    overflow: visible;
    border-radius: 9999px;
    background: rgb(0 0 0 / 68%);
    clip: auto;
    clip-path: none;
    white-space: normal;
    pointer-events: auto;
  }
}

.pop-fade-enter-active,
.pop-fade-leave-active {
  transition: opacity 0.12s ease, transform 0.12s ease;
}
.pop-fade-enter-from,
.pop-fade-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}

@media (prefers-reduced-motion: reduce) {
  .anime-card-shell--watched::before,
  .anime-card-shell--favorite::before {
    animation: none;
  }
}

</style>
