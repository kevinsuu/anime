<script setup lang="ts">
import { normalizeAnime, tagColor } from '../../utils/normalize'
import type { Anime } from '../../utils/normalize'
import { seasonMonthLabels } from '../../utils/season'

const route = useRoute()
const router = useRouter()
const api = useApi()
const { isAuthed } = useSession()
const toast = useToast()

const { data: anime, pending: loading, error: fetchError } = await useAsyncData(
  `anime-${route.params.id}`,
  async () => normalizeAnime((await api.getAnime(Number(route.params.id))).item)
)
const error = computed(() => fetchError.value ? (fetchError.value.message || '載入失敗') : '')
const addedToList = ref(false)

function goBack() {
  if (import.meta.client && window.history.length > 1) {
    router.back()
    return
  }
  navigateTo('/seasonal')
}

// Trailer modal
const activeTrailerUrl = ref<string | null>(null)
const trailerDialogRef = ref<HTMLElement | null>(null)
const trailerTriggerRef = ref<HTMLElement | null>(null)

function youtubeEmbedUrl(url: string): string {
  const match = url.match(/[?&]v=([^&]+)/) || url.match(/youtu\.be\/([^?]+)/)
  const id = match?.[1]
  return id ? `https://www.youtube.com/embed/${id}?autoplay=1` : url
}

function openTrailer(url: string, event?: Event) {
  trailerTriggerRef.value = event?.currentTarget instanceof HTMLElement ? event.currentTarget : null
  activeTrailerUrl.value = url
  nextTick(() => trailerDialogRef.value?.focus())
}

function closeTrailer() {
  const trigger = trailerTriggerRef.value
  activeTrailerUrl.value = null
  nextTick(() => trigger?.focus())
}

function onTrailerKeydown(event: KeyboardEvent) {
  if (!activeTrailerUrl.value) return
  if (event.key === 'Escape') {
    event.preventDefault()
    closeTrailer()
    return
  }
  if (event.key !== 'Tab' || !trailerDialogRef.value) return

  const focusable = Array.from(trailerDialogRef.value.querySelectorAll<HTMLElement>(
    'button, a[href], iframe, [tabindex]:not([tabindex="-1"])'
  )).filter(element => !element.hasAttribute('disabled'))
  if (focusable.length === 0) return
  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  if (event.shiftKey && (document.activeElement === first || document.activeElement === trailerDialogRef.value) && last) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last && first) {
    event.preventDefault()
    first.focus()
  }
}

onMounted(() => {
  window.addEventListener('keydown', onTrailerKeydown)
})
onBeforeUnmount(() => window.removeEventListener('keydown', onTrailerKeydown))

async function addToList() {
  if (!isAuthed.value) return navigateTo('/login')
  try {
    await api.addToList(Number(route.params.id))
    addedToList.value = true
    toast.add({ title: '已加入清單', color: 'success' })
  } catch (err: any) {
    toast.add({ title: err.message || '加入失敗', color: 'error' })
  }
}

// Group links by category
const linksByCategory = computed(() => {
  if (!anime.value) return {}
  return anime.value.links.reduce((acc, l) => {
    const cat = l.category || '其他'
    if (!acc[cat]) acc[cat] = []
    acc[cat].push(l)
    return acc
  }, {} as Record<string, typeof anime.value.links>)
})

useSeoMeta({
  title: () => anime.value ? `${anime.value.name} － 動畫新番介紹｜動漫庫` : '動漫庫',
  description: () => anime.value ? (anime.value.description || '').slice(0, 120) : undefined,
  ogTitle: () => anime.value?.name,
  ogDescription: () => (anime.value?.description || '').slice(0, 200),
  ogImage: () => anime.value?.imageUrl || undefined,
  ogType: 'video.tv_show',
  twitterCard: 'summary_large_image'
})

useHead({
  link: [{ rel: 'canonical', href: () => `https://anime.kaistarstudio.me/anime/${route.params.id}` }],
  script: [{
    type: 'application/ld+json',
    innerHTML: computed(() => anime.value ? JSON.stringify({
      '@context': 'https://schema.org',
      '@type': 'TVSeries',
      name: anime.value.name,
      alternateName: anime.value.titleJa || undefined,
      description: anime.value.description,
      image: anime.value.imageUrl || undefined,
      genre: anime.value.tags,
      datePublished: anime.value.airDate || undefined,
      numberOfEpisodes: anime.value.episodeCount || undefined
    }) : '{}')
  }]
})
</script>

<template>
  <div>
    <!-- Back -->
    <button
      type="button"
      class="mb-3 inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-gray-500 transition-colors hover:text-gray-900 md:mb-6 md:min-h-0"
      @click="goBack"
    >
      <UIcon name="i-lucide-chevron-left" class="size-4" />
      返回上一頁
    </button>

    <!-- Loading -->
    <div v-if="loading" class="space-y-6">
      <div class="flex gap-3 md:gap-6">
        <div class="h-[150px] w-28 shrink-0 animate-pulse rounded-xl bg-gray-200 md:h-72 md:w-48" />
        <div class="flex-1 space-y-3 pt-2">
          <div class="h-4 w-24 animate-pulse rounded bg-gray-200" />
          <div class="h-8 w-64 animate-pulse rounded bg-gray-200" />
          <div class="h-4 w-48 animate-pulse rounded bg-gray-200" />
          <div class="mt-4 h-32 w-full animate-pulse rounded bg-gray-200" />
        </div>
      </div>
    </div>

    <UAlert v-else-if="error" color="error" :title="error" />

    <template v-else-if="anime">
      <section class="grid grid-cols-[112px_minmax(0,1fr)] gap-3 md:hidden">
        <img
          v-if="anime.imageUrl"
          :src="anime.imageUrl"
          :alt="anime.name"
          loading="eager"
          fetchpriority="high"
          decoding="async"
          width="112"
          height="150"
          class="h-[150px] w-28 rounded-xl object-cover shadow-md"
        />
        <div
          v-else
          class="flex h-[150px] w-28 items-center justify-center rounded-xl bg-primary-100 text-3xl font-bold text-primary-400"
        >
          {{ anime.name.slice(0, 1) }}
        </div>

        <div class="flex min-w-0 flex-col">
          <p v-if="anime.seasonYear" class="text-[11px] font-extrabold uppercase tracking-wider text-primary-700">
            {{ anime.seasonYear }}年 {{ seasonMonthLabels[anime.seasonCode] ?? anime.seasonCode }}
          </p>
          <h1 class="mt-0.5 line-clamp-3 text-lg font-extrabold leading-snug tracking-tight text-gray-950">{{ anime.name }}</h1>
          <p v-if="anime.titleJa" class="mt-1 line-clamp-1 text-xs text-gray-500">{{ anime.titleJa }}</p>
          <p v-if="anime.episodeCount" class="mt-1 text-xs font-semibold text-gray-500">全 {{ anime.episodeCount }} 集</p>
          <button
            :disabled="addedToList"
            class="mt-auto min-h-11 w-full rounded-lg px-3 text-sm font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
            :class="addedToList
              ? 'cursor-default bg-green-100 text-green-700'
              : 'bg-primary-700 text-white shadow-sm active:bg-primary-800'"
            @click="addToList"
          >
            <span class="flex items-center justify-center gap-1.5">
              <UIcon :name="addedToList ? 'i-lucide-check' : 'i-lucide-plus'" class="size-4" />
              {{ addedToList ? '已加入清單' : '加入清單' }}
            </span>
          </button>
        </div>
      </section>

      <div v-if="anime.tags.length > 0" class="mt-3 flex gap-1.5 overflow-x-auto pb-1 md:hidden">
        <span
          v-for="tag in anime.tags"
          :key="tag"
          class="shrink-0 rounded-md px-2.5 py-1 text-xs font-semibold"
          :style="{ backgroundColor: tagColor(tag).bg, color: tagColor(tag).text }"
        >{{ tag }}</span>
      </div>

      <div class="mt-5 grid gap-5 md:mt-0 md:gap-8 lg:grid-cols-[220px_1fr]">

        <!-- ── Left column ── -->
        <div class="hidden flex-col gap-4 md:flex">
          <!-- Cover -->
          <img
            v-if="anime.imageUrl"
            :src="anime.imageUrl"
            :alt="anime.name"
            width="220"
            height="293"
            loading="eager"
            fetchpriority="high"
            decoding="async"
            class="aspect-3/4 w-full rounded-xl object-cover shadow-md"
          />
          <div
            v-else
            class="flex aspect-3/4 w-full items-center justify-center rounded-xl bg-primary-100 text-4xl font-bold text-primary-400"
          >
            {{ anime.name.slice(0, 1) }}
          </div>

          <!-- Add to list -->
          <button
            :disabled="addedToList"
            class="w-full rounded-lg py-2.5 text-sm font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
            :class="addedToList
              ? 'bg-green-100 text-green-700 cursor-default'
              : 'bg-primary-700 text-white shadow-sm hover:bg-primary-800'"
            @click="addToList"
          >
            <span class="flex items-center justify-center gap-1.5">
              <UIcon :name="addedToList ? 'i-lucide-check' : 'i-lucide-plus'" class="size-4" />
              {{ addedToList ? '已加入清單' : '加入清單' }}
            </span>
          </button>

          <!-- External links：只顯示「一般」分類，不顯示「資料庫」 -->
          <template v-if="(linksByCategory['一般']?.length ?? 0) > 0">
            <div class="space-y-1.5">
              <a
                v-for="link in linksByCategory['一般']"
                :key="link.url"
                :href="link.url"
                target="_blank"
                rel="noopener noreferrer"
                class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50"
              >
                <UIcon name="i-lucide-external-link" class="size-3.5 shrink-0 text-gray-400" />
                {{ link.label }}
              </a>
            </div>
          </template>
        </div>

        <!-- ── Right column ── -->
        <div class="min-w-0 space-y-8">

          <!-- Title -->
          <div class="hidden md:block">
            <p v-if="anime.seasonYear" class="text-xs font-extrabold uppercase tracking-widest text-primary-700">
              {{ anime.seasonYear }}年 {{ seasonMonthLabels[anime.seasonCode] ?? anime.seasonCode }}
            </p>
            <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-gray-950">{{ anime.name }}</h1>
            <p v-if="anime.titleJa" class="mt-0.5 text-sm text-gray-500">{{ anime.titleJa }}</p>

            <!-- Tags -->
            <div v-if="anime.tags.length > 0" class="mt-3 flex flex-wrap gap-1.5">
              <span
                v-for="tag in anime.tags"
                :key="tag"
                class="rounded-md px-2.5 py-0.5 text-xs font-semibold"
                :style="{ backgroundColor: tagColor(tag).bg, color: tagColor(tag).text }"
              >{{ tag }}</span>
            </div>
          </div>

          <!-- Air info -->
          <div v-if="anime.airDateText || anime.airDate" class="flex items-start gap-2 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <UIcon name="i-lucide-calendar" class="mt-0.5 size-4 shrink-0 text-primary-500" />
            <p class="text-sm font-medium text-gray-900">{{ anime.airDateText || anime.airDate }}</p>
          </div>

          <div v-if="(linksByCategory['一般']?.length ?? 0) > 0" class="grid gap-2 md:hidden">
            <a
              v-for="link in linksByCategory['一般']"
              :key="link.url"
              :href="link.url"
              target="_blank"
              rel="noopener noreferrer"
              class="flex min-h-11 items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 text-sm font-semibold text-gray-700 shadow-sm"
            >
              <UIcon name="i-lucide-external-link" class="size-4 shrink-0 text-gray-400" />
              {{ link.label }}
            </a>
          </div>

          <!-- Summary -->
          <section class="space-y-2">
            <h2 class="text-[11px] font-bold uppercase tracking-widest text-gray-500">故事介紹</h2>
            <div class="rounded-xl border border-gray-200 bg-white px-5 py-4 shadow-sm">
              <p class="whitespace-pre-line text-sm leading-relaxed text-gray-700">{{ anime.description }}</p>
            </div>
          </section>

          <!-- Aliases -->
          <section v-if="anime.aliases.length > 0" class="space-y-2">
            <h2 class="text-[11px] font-bold uppercase tracking-widest text-gray-500">其他名稱</h2>
            <div class="rounded-xl border border-gray-200 bg-white px-5 py-4 shadow-sm">
              <p class="text-sm text-gray-600 leading-relaxed">{{ anime.aliases.join('、') }}</p>
            </div>
          </section>

          <!-- Streams -->
          <section v-if="anime.streams.length > 0" class="space-y-2">
            <h2 class="text-[11px] font-bold uppercase tracking-widest text-gray-500">線上觀看</h2>
            <div class="grid gap-2 sm:flex sm:flex-wrap">
              <template v-for="s in anime.streams" :key="`${s.region}-${s.platform}`">
                <a
                  v-if="s.url"
                  :href="s.url"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 md:min-h-0"
                >
                  <UIcon name="i-lucide-play" class="size-3.5 text-primary-500" />
                  {{ s.platform }}
                  <span class="text-xs text-gray-600">{{ s.region }}</span>
                </a>
                <span
                  v-else
                  class="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-gray-100 bg-gray-50 px-3 py-1.5 text-sm font-semibold text-gray-500 md:min-h-0"
                >
                  <UIcon name="i-lucide-play" class="size-3.5 text-gray-300" />
                  {{ s.platform }}
                  <span class="text-xs text-gray-600">{{ s.region }}</span>
                </span>
              </template>
            </div>
          </section>

          <!-- Theme songs -->
          <section v-if="anime.themes.length > 0" class="space-y-2">
            <h2 class="text-[11px] font-bold uppercase tracking-widest text-gray-500">主題曲</h2>
            <div class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
              <div
                v-for="theme in anime.themes"
                :key="`${theme.type}-${theme.title}`"
                class="flex items-center gap-3 px-4 py-3"
              >
                <span class="w-8 shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-center text-[11px] font-bold text-gray-600">
                  {{ theme.type }}
                </span>
                <div class="min-w-0">
                  <p class="truncate text-sm font-semibold text-gray-900">{{ theme.title }}</p>
                  <p v-if="theme.artist" class="truncate text-xs text-gray-500">{{ theme.artist }}</p>
                </div>
              </div>
            </div>
          </section>

          <!-- Trailers -->
          <section v-if="anime.trailers.length > 0" class="space-y-2">
            <h2 class="text-[11px] font-bold uppercase tracking-widest text-gray-500">宣傳片</h2>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
              <button
                v-for="(trailer, i) in anime.trailers"
                :key="trailer.url"
                type="button"
                :aria-label="`播放宣傳片 ${i + 1}`"
                class="group relative block w-full overflow-hidden rounded-lg bg-gray-900 shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                @click="openTrailer(trailer.url, $event)"
              >
                <img
                  v-if="trailer.thumbnail"
                  :src="trailer.thumbnail"
                  :alt="`宣傳片 ${i + 1}`"
                  loading="lazy"
                  width="320"
                  height="180"
                  class="aspect-video w-full object-cover opacity-80 transition group-hover:opacity-60"
                />
                <div v-else class="flex aspect-video w-full items-center justify-center bg-gray-800" />
                <div class="absolute inset-0 flex items-center justify-center">
                  <div class="flex h-10 w-10 items-center justify-center rounded-full bg-white/90 shadow-md transition group-hover:scale-110">
                    <UIcon name="i-lucide-play" class="size-4 translate-x-0.5 text-gray-900" />
                  </div>
                </div>
              </button>
            </div>
          </section>

          <!-- Cast -->
          <section v-if="anime.cast.length > 0" class="space-y-2">
            <h2 class="text-[11px] font-bold uppercase tracking-widest text-gray-500">配音員</h2>
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
              <div class="grid grid-cols-1 sm:grid-cols-2">
                <div
                  v-for="entry in anime.cast"
                  :key="entry.character"
                  class="flex items-center gap-3 border-b border-r border-gray-100 px-4 py-3 last:border-b-0 odd:border-r even:border-r-0"
                >
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-[11px] font-semibold text-gray-600">{{ entry.character }}</p>
                    <p class="truncate text-sm font-bold text-gray-900">{{ entry.actor }}</p>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <!-- Staff -->
          <section v-if="anime.staff.length > 0" class="space-y-2">
            <h2 class="text-[11px] font-bold uppercase tracking-widest text-gray-500">製作人員</h2>
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
              <div class="grid grid-cols-1 sm:grid-cols-2">
                <div
                  v-for="entry in anime.staff"
                  :key="entry.role"
                  class="flex items-center gap-3 border-b border-r border-gray-100 px-4 py-3 last:border-b-0 odd:border-r even:border-r-0"
                >
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-[11px] font-semibold text-gray-600">{{ entry.role }}</p>
                    <p class="text-sm font-medium text-gray-900">{{ entry.name }}</p>
                  </div>
                </div>
              </div>
            </div>
          </section>

        </div>
      </div>
    </template>

    <!-- Trailer modal -->
    <Teleport to="body">
      <Transition name="trailer-fade">
        <div
          v-if="activeTrailerUrl"
          class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
          @click.self="closeTrailer"
        >
          <div
            ref="trailerDialogRef"
            role="dialog"
            aria-modal="true"
            aria-labelledby="trailer-dialog-title"
            tabindex="-1"
            class="relative w-full max-w-3xl outline-none"
          >
            <h2 id="trailer-dialog-title" class="sr-only">{{ anime?.name }} 宣傳片</h2>
            <!-- Close button -->
            <button
              class="absolute -top-12 right-0 flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white md:-top-10 md:h-8 md:w-8"
              aria-label="關閉影片"
              @click="closeTrailer"
            >
              <UIcon name="i-lucide-x" class="size-4" />
            </button>
            <!-- YouTube iframe -->
            <div class="aspect-video w-full overflow-hidden rounded-xl shadow-2xl">
              <iframe
                :src="youtubeEmbedUrl(activeTrailerUrl)"
                :title="`${anime?.name ?? '動畫'} 宣傳片`"
                class="h-full w-full"
                frameborder="0"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                allowfullscreen
              />
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.trailer-fade-enter-active,
.trailer-fade-leave-active {
  transition: opacity 0.2s ease;
}
.trailer-fade-enter-from,
.trailer-fade-leave-to {
  opacity: 0;
}
</style>
