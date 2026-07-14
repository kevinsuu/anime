<script setup lang="ts">
import type { ListItem, Collection } from '../utils/normalize'
import { tagColor } from '../utils/normalize'
import { isGenreTag } from '../composables/useSeasonalCatalog'

const props = defineProps<{
  item: ListItem
  collections: Collection[]
  disabled: boolean
}>()

// Only genre/theme tags are shown here — source/type tags (新作/漫畫改編/…)
// and season-count tags aren't genres, and would be misleading alongside them.
const genreTags = computed(() => props.item.anime.tags.filter(isGenreTag))

const emit = defineEmits<{
  update: [patch: Record<string, any>]
  remove: []
  toggleCollection: [col: Collection]
}>()

const confirmingRemove = ref(false)
const colPopoverOpen = ref(false)

const UNRATED = 'unrated'
const ratingOptions = [
  { value: UNRATED, label: '未評分' },
  ...Array.from({ length: 10 }, (_, i) => ({ value: String(i + 1), label: `★ ${i + 1} 分` }))
]

function updateWatched(value: boolean) {
  emit('update', { watched: value })
}

function updateRating(value: string | undefined) {
  emit('update', { rating: value && value !== UNRATED ? Number(value) : null })
}

function updateNote(value: string) {
  emit('update', { note: value })
}

function isInCollection(col: Collection): boolean {
  return props.item.collections.some(c => c.id === col.id)
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && colPopoverOpen.value) colPopoverOpen.value = false
}

watch(colPopoverOpen, (open) => {
  if (open) window.addEventListener('keydown', onKeydown)
  else window.removeEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="flex w-full min-w-0 max-w-full gap-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:shadow-md">
    <!-- Cover -->
    <NuxtLink :to="`/anime/${item.anime.id}`" class="shrink-0">
      <img
        v-if="item.anime.imageUrl"
        :src="item.anime.imageUrl"
        :alt="item.anime.name"
        loading="lazy"
        width="80"
        height="112"
        class="h-28 w-20 rounded-lg object-cover shadow-sm"
      />
      <div
        v-else
        class="flex h-28 w-20 items-center justify-center rounded-lg bg-primary-100 text-2xl font-bold text-primary-600"
      >
        {{ item.anime.name.slice(0, 1) }}
      </div>
    </NuxtLink>

    <!-- Info -->
    <div class="min-w-0 flex-1 space-y-2">
      <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
          <NuxtLink :to="`/anime/${item.anime.id}`" class="hover:underline">
            <h3 class="truncate text-base font-bold text-gray-950">{{ item.anime.name }}</h3>
          </NuxtLink>
          <p v-if="item.anime.aliases?.length" class="mt-0.5 truncate text-xs text-gray-400">
            其他名稱：{{ item.anime.aliases.slice(0,3).join('、') }}
          </p>
        </div>
        <span
          class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold"
          :class="item.watched ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'"
        >
          {{ item.watched ? '已看完' : '待補完' }}
        </span>
      </div>

      <!-- Genre tags (display only, filtering happens in the page's filter bar) -->
      <div v-if="genreTags.length > 0" class="flex flex-wrap items-center gap-1.5">
        <span
          v-for="tag in genreTags"
          :key="tag"
          class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold cursor-default"
          :style="{ backgroundColor: tagColor(tag).bg, color: tagColor(tag).text }"
        >
          {{ tag }}
        </span>
      </div>

      <!-- Collection tags -->
      <div v-if="item.collections.length > 0 || collections.length > 0" class="flex flex-wrap items-center gap-1.5">
        <span
          v-for="col in item.collections"
          :key="col.id"
          class="inline-flex items-center gap-1 rounded-full bg-primary-50 px-2.5 py-0.5 text-xs font-semibold text-primary-700"
        >
          {{ col.name }}
        </span>

        <!-- Add to collection popover trigger -->
        <div v-if="collections.length > 0" class="relative">
          <button
            type="button"
            class="inline-flex items-center gap-1 rounded-full border border-dashed border-gray-300 px-2.5 py-0.5 text-xs font-semibold text-gray-400 transition hover:border-primary-400 hover:text-primary-600"
            @click="colPopoverOpen = !colPopoverOpen"
          >
            <UIcon name="i-lucide-plus" class="size-3" />
            加入清單
          </button>

          <!-- Backdrop to close popover when clicking outside -->
          <div
            v-if="colPopoverOpen"
            class="fixed inset-0 z-10"
            @click="colPopoverOpen = false"
          />

          <!-- Popover -->
          <div
            v-if="colPopoverOpen"
            class="absolute right-0 top-full z-20 mt-1.5 w-52 max-w-[calc(100vw-3rem)] rounded-xl border border-gray-200 bg-white py-1 shadow-lg sm:right-auto sm:left-0"
          >
            <p class="px-3 py-1.5 text-[11px] font-bold uppercase tracking-widest text-gray-400">選擇清單</p>
            <button
              v-for="col in collections"
              :key="col.id"
              type="button"
              class="flex w-full items-center gap-2.5 px-3 py-2 text-sm transition hover:bg-gray-50"
              @click="emit('toggleCollection', col); colPopoverOpen = false"
            >
              <span
                class="flex h-4 w-4 shrink-0 items-center justify-center rounded border transition"
                :class="isInCollection(col)
                  ? 'border-primary-600 bg-primary-600'
                  : 'border-gray-300 bg-white'"
              >
                <UIcon v-if="isInCollection(col)" name="i-lucide-check" class="size-2.5 text-white" />
              </span>
              <span class="truncate font-medium text-gray-900">{{ col.name }}</span>
              <span class="ml-auto text-xs text-gray-400">{{ col.count }}</span>
            </button>
            <div class="border-t border-gray-100 mt-1 pt-1 px-3 pb-1">
              <button
                type="button"
                class="text-xs text-gray-400 hover:text-gray-700"
                @click="colPopoverOpen = false"
              >
                關閉
              </button>
            </div>
          </div>
        </div>
      </div>

      <textarea
        :value="item.note"
        :disabled="disabled"
        rows="2"
        placeholder="記錄集數進度、推薦理由或心得…"
        class="w-full resize-none rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700 placeholder:text-gray-400 outline-none transition focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-100 disabled:opacity-50"
        @change="event => updateNote((event.target as HTMLTextAreaElement).value)"
      />

      <!-- Actions row -->
      <div class="flex flex-wrap items-center gap-3">
        <label class="flex cursor-pointer items-center gap-2 select-none">
          <button
            role="switch"
            :aria-checked="item.watched"
            :disabled="disabled"
            class="relative h-5 w-9 rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-50"
            :class="item.watched ? 'bg-primary-600' : 'bg-gray-200'"
            @click="updateWatched(!item.watched)"
          >
            <span
              class="absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform"
              :class="item.watched ? 'left-4.5' : 'left-0.5'"
            />
          </button>
          <span class="text-xs font-medium text-gray-700">已看</span>
        </label>

        <select
          :value="item.rating ? String(item.rating) : UNRATED"
          :disabled="disabled"
          class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-700 outline-none transition focus:border-primary-400 focus:ring-2 focus:ring-primary-100 disabled:opacity-50"
          @change="event => updateRating((event.target as HTMLSelectElement).value)"
        >
          <option v-for="opt in ratingOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
        </select>

        <div class="ml-auto">
          <template v-if="!confirmingRemove">
            <button
              :disabled="disabled"
              class="rounded-lg border border-red-200 px-3 py-1 text-xs font-semibold text-red-500 transition hover:bg-red-50 disabled:opacity-50"
              @click="confirmingRemove = true"
            >
              移除
            </button>
          </template>
          <template v-else>
            <div class="flex items-center gap-2">
              <button
                :disabled="disabled"
                class="rounded-lg px-3 py-1 text-xs font-semibold text-gray-500 transition hover:bg-gray-100 disabled:opacity-50"
                @click="confirmingRemove = false"
              >
                取消
              </button>
              <button
                :disabled="disabled"
                class="rounded-lg bg-red-500 px-3 py-1 text-xs font-semibold text-white transition hover:bg-red-600 disabled:opacity-50"
                @click="confirmingRemove = false; emit('remove')"
              >
                確認移除
              </button>
            </div>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>
