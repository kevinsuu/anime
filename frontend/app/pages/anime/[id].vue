<script setup lang="ts">
import { normalizeAnime, tagColor } from '../../utils/normalize'
import type { Anime } from '../../utils/normalize'

const route = useRoute()
const api = useApi()
const { isAuthed } = useSession()
const toast = useToast()

const anime = ref<Anime | null>(null)
const loading = ref(true)
const error = ref('')
const addedToList = ref(false)

// Trailer modal
const activeTrailerUrl = ref<string | null>(null)

function youtubeEmbedUrl(url: string): string {
  const match = url.match(/[?&]v=([^&]+)/) || url.match(/youtu\.be\/([^?]+)/)
  const id = match?.[1]
  return id ? `https://www.youtube.com/embed/${id}?autoplay=1` : url
}

function openTrailer(url: string) {
  activeTrailerUrl.value = url
}

function closeTrailer() {
  activeTrailerUrl.value = null
}

// Close on Escape key
onMounted(() => {
  window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeTrailer()
  })
})

const seasonMonthMap: Record<string, string> = {
  winter: '1月', spring: '4月', summer: '7月', fall: '10月'
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const result = await api.getAnime(Number(route.params.id))
    anime.value = normalizeAnime(result.item)
  } catch (err: any) {
    error.value = err.message || '載入失敗'
  } finally {
    loading.value = false
  }
}

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

onMounted(load)
</script>

<template>
  <div>
    <!-- Back -->
    <NuxtLink
      to="/seasonal"
      class="mb-6 inline-flex items-center gap-1.5 text-sm font-semibold text-gray-500 hover:text-gray-900 transition-colors"
    >
      <UIcon name="i-lucide-chevron-left" class="size-4" />
      返回新番表
    </NuxtLink>

    <!-- Loading -->
    <div v-if="loading" class="space-y-6">
      <div class="flex gap-6">
        <div class="h-72 w-48 shrink-0 animate-pulse rounded-xl bg-gray-200" />
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
      <div class="grid gap-8 lg:grid-cols-[220px_1fr]">

        <!-- ── Left column ── -->
        <div class="flex flex-col gap-4">
          <!-- Cover -->
          <img
            v-if="anime.imageUrl"
            :src="anime.imageUrl"
            :alt="anime.name"
            width="220"
            height="293"
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
              : 'bg-primary-600 text-white hover:bg-primary-500 shadow-sm'"
            @click="addToList"
          >
            <span class="flex items-center justify-center gap-1.5">
              <UIcon :name="addedToList ? 'i-lucide-check' : 'i-lucide-plus'" class="size-4" />
              {{ addedToList ? '已加入清單' : '加入清單' }}
            </span>
          </button>

          <!-- External links：只顯示「一般」分類，不顯示「資料庫」 -->
          <template v-if="linksByCategory['一般']?.length > 0">
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
        <div class="space-y-8">

          <!-- Title -->
          <div>
            <p v-if="anime.seasonYear" class="text-xs font-extrabold uppercase tracking-widest text-primary-600">
              {{ anime.seasonYear }}年 {{ seasonMonthMap[anime.seasonCode] ?? anime.seasonCode }}
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
            <div class="flex flex-wrap gap-2">
              <template v-for="s in anime.streams" :key="`${s.region}-${s.platform}`">
                <a
                  v-if="s.url"
                  :href="s.url"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 transition"
                >
                  <UIcon name="i-lucide-play" class="size-3.5 text-primary-500" />
                  {{ s.platform }}
                  <span class="text-xs text-gray-400">{{ s.region }}</span>
                </a>
                <span
                  v-else
                  class="inline-flex items-center gap-1.5 rounded-lg border border-gray-100 bg-gray-50 px-3 py-1.5 text-sm font-semibold text-gray-500"
                >
                  <UIcon name="i-lucide-play" class="size-3.5 text-gray-300" />
                  {{ s.platform }}
                  <span class="text-xs text-gray-400">{{ s.region }}</span>
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
                <span class="w-8 shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-center text-[11px] font-bold text-gray-500">
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
                class="group relative block w-full overflow-hidden rounded-lg bg-gray-900 shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                @click="openTrailer(trailer.url)"
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
              <div class="grid grid-cols-2">
                <div
                  v-for="entry in anime.cast"
                  :key="entry.character"
                  class="flex items-center gap-3 border-b border-r border-gray-100 px-4 py-3 last:border-b-0 odd:border-r even:border-r-0"
                >
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-[11px] font-semibold text-gray-400">{{ entry.character }}</p>
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
              <div class="grid grid-cols-2">
                <div
                  v-for="entry in anime.staff"
                  :key="entry.role"
                  class="flex items-center gap-3 border-b border-r border-gray-100 px-4 py-3 last:border-b-0 odd:border-r even:border-r-0"
                >
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-[11px] font-semibold text-gray-400">{{ entry.role }}</p>
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
          <div class="relative w-full max-w-3xl">
            <!-- Close button -->
            <button
              class="absolute -top-10 right-0 flex h-8 w-8 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
              aria-label="關閉影片"
              @click="closeTrailer"
            >
              <UIcon name="i-lucide-x" class="size-4" />
            </button>
            <!-- YouTube iframe -->
            <div class="aspect-video w-full overflow-hidden rounded-xl shadow-2xl">
              <iframe
                :src="youtubeEmbedUrl(activeTrailerUrl)"
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
