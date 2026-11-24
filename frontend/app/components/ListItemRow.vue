<script setup lang="ts">
import type { ListItem, Collection } from '../utils/normalize'
import type { ListItemPatch } from '../types/api'
import { tagColor } from '../utils/normalize'
import { isGenreTag } from '../composables/useSeasonalCatalog'
import { useLazyLoad } from '../composables/useLazyLoad'

const props = defineProps<{
  item: ListItem
  collections: Collection[]
  disabled: boolean
}>()

// Only genre/theme tags are shown here — source/type tags (新作/漫畫改編/…)
// and season-count tags aren't genres, and would be misleading alongside them.
const genreTags = computed(() => props.item.anime.tags.filter(isGenreTag))

const emit = defineEmits<{
  update: [patch: ListItemPatch]
  remove: []
  toggleCollection: [col: Collection]
}>()

const confirmingRemove = ref(false)
const editorOpen = ref(false)
const noteDraft = ref(props.item.note)
const rowRef = ref<HTMLElement | null>(null)
const shouldLoadCover = useLazyLoad(rowRef)

// The editor is a bottom sheet on phones and a side panel on wider screens,
// where a sheet rising from the bottom of a large window feels out of place.
const isDesktop = ref(false)
let desktopQuery: MediaQueryList | null = null
function syncDesktop() {
  isDesktop.value = desktopQuery?.matches ?? false
}
onMounted(() => {
  desktopQuery = window.matchMedia('(min-width: 768px)')
  syncDesktop()
  desktopQuery.addEventListener('change', syncDesktop)
})

function updateWatched(value: boolean) {
  emit('update', { watched: value })
}

function updateRating(value: number | null) {
  emit('update', { rating: value })
}

function updateNote(value: string) {
  emit('update', { note: value })
}

function isInCollection(col: Collection): boolean {
  return props.item.collections.some(c => c.id === col.id)
}

function removeFromEditor() {
  confirmingRemove.value = false
  noteDraft.value = props.item.note
  editorOpen.value = false
  emit('remove')
}

// The note is saved once when the editor closes instead of on every keystroke.
watch(editorOpen, (open) => {
  if (open) {
    noteDraft.value = props.item.note
    return
  }

  confirmingRemove.value = false
  if (noteDraft.value !== props.item.note) updateNote(noteDraft.value)
})

onBeforeUnmount(() => desktopQuery?.removeEventListener('change', syncDesktop))
</script>

<template>
  <!-- One layout for every screen size: the watched toggle is the primary
       action, rating stays one click away on desktop, and the less frequent
       edits (note, categories, remove) live in the editor so rows stay calm. -->
  <div ref="rowRef" class="flex w-full min-w-0 max-w-full gap-3 rounded-xl border border-gray-200 bg-white p-3 shadow-sm transition hover:shadow-md md:gap-4 md:p-4">
    <!-- Cover -->
    <NuxtLink :to="`/anime/${item.anime.id}`" no-prefetch class="shrink-0" tabindex="-1" aria-hidden="true">
      <img
        v-if="item.anime.imageUrl"
        :src="shouldLoadCover ? item.anime.imageUrl : undefined"
        alt=""
        :loading="shouldLoadCover ? 'eager' : 'lazy'"
        :fetchpriority="shouldLoadCover ? 'auto' : 'low'"
        decoding="async"
        width="80"
        height="112"
        class="h-24 w-[72px] rounded-lg object-cover shadow-sm md:h-28 md:w-20"
      />
      <div
        v-else
        class="flex h-24 w-[72px] items-center justify-center rounded-lg bg-primary-100 text-2xl font-bold text-primary-800 md:h-28 md:w-20"
      >
        {{ item.anime.name.slice(0, 1) }}
      </div>
    </NuxtLink>

    <!-- Info -->
    <div class="flex min-w-0 flex-1 flex-col gap-2">
      <div class="min-w-0">
        <NuxtLink :to="`/anime/${item.anime.id}`" no-prefetch class="hover:underline">
          <h3 class="line-clamp-2 text-sm font-bold leading-5 text-gray-950 md:text-base md:leading-6">{{ item.anime.name }}</h3>
        </NuxtLink>
        <p v-if="item.anime.aliases?.length" class="mt-0.5 hidden truncate text-xs text-gray-500 md:block">
          其他名稱：{{ item.anime.aliases.slice(0,3).join('、') }}
        </p>
      </div>

      <!-- Read-only summary: genres (desktop), categories, rating (phone) -->
      <div
        v-if="genreTags.length > 0 || item.collections.length > 0 || item.rating"
        class="flex flex-wrap items-center gap-1.5"
      >
        <span
          v-for="col in item.collections"
          :key="`col-${col.id}`"
          class="inline-flex items-center gap-1 rounded-full bg-primary-50 px-2.5 py-0.5 text-xs font-semibold text-primary-800"
        >
          <UIcon name="i-lucide-folder" class="size-3" aria-hidden="true" />
          {{ col.name }}
        </span>
        <span
          v-if="item.rating"
          class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-800 md:hidden"
        >
          <UIcon name="i-lucide-star" class="size-3" aria-hidden="true" />
          {{ item.rating }} / 10
        </span>
        <span
          v-for="tag in genreTags.slice(0, 4)"
          :key="`tag-${tag}`"
          class="hidden items-center rounded-full px-2.5 py-0.5 text-xs font-semibold md:inline-flex"
          :style="{ backgroundColor: tagColor(tag).bg, color: tagColor(tag).text }"
        >
          {{ tag }}
        </span>
      </div>

      <p v-if="item.note" class="line-clamp-2 text-xs leading-5 text-gray-600">
        <UIcon name="i-lucide-notebook-pen" class="mr-1 inline size-3.5 align-[-2px] text-gray-500" aria-hidden="true" />
        {{ item.note }}
      </p>

      <!-- Actions -->
      <div class="mt-auto flex flex-wrap items-center gap-2 md:gap-3">
        <button
          type="button"
          role="switch"
          :aria-checked="item.watched"
          :disabled="disabled"
          class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg border px-3 text-sm font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-50 md:min-h-9 md:flex-none"
          :class="item.watched
            ? 'border-primary-200 bg-primary-50 text-primary-800 hover:bg-primary-100'
            : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50 active:bg-gray-50'"
          @click="updateWatched(!item.watched)"
        >
          <UIcon :name="item.watched ? 'i-lucide-circle-check' : 'i-lucide-circle'" class="size-4" aria-hidden="true" />
          {{ item.watched ? '已看完' : '標記已看完' }}
        </button>

        <div class="hidden items-center gap-2 md:flex">
          <span :id="`list-rating-label-${item.id}`" class="sr-only">為「{{ item.anime.name }}」評分</span>
          <ListRatingStars
            :rating="item.rating"
            :disabled="disabled"
            compact
            :labelled-by="`list-rating-label-${item.id}`"
            @update:rating="updateRating"
          />
        </div>

        <USlideover
          v-model:open="editorOpen"
          :side="isDesktop ? 'right' : 'bottom'"
          :title="`編輯「${item.anime.name}」`"
          description="評分、筆記、收藏分類與移除"
          :ui="{
            content: isDesktop ? 'w-full max-w-md' : 'max-h-[90dvh] rounded-t-3xl',
            body: 'overflow-y-auto px-4 sm:px-6',
            close: 'size-11',
            footer: 'border-t border-gray-100 px-4 pb-[calc(1rem+env(safe-area-inset-bottom))] pt-3 sm:px-6'
          }"
        >
          <button
            type="button"
            :disabled="disabled"
            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-3 text-sm font-bold text-gray-700 shadow-sm transition hover:bg-gray-50 active:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-50 md:ml-auto md:min-h-9"
            aria-haspopup="dialog"
            :aria-label="`編輯「${item.anime.name}」`"
          >
            <UIcon name="i-lucide-pencil" class="size-4" aria-hidden="true" />
            編輯
          </button>

          <template #body>
            <div class="space-y-6">
              <section :aria-labelledby="`list-editor-rating-${item.id}`" class="space-y-2">
                <h4 :id="`list-editor-rating-${item.id}`" class="text-sm font-bold text-gray-900">評分</h4>
                <ListRatingStars
                  :rating="item.rating"
                  :disabled="disabled"
                  :labelled-by="`list-editor-rating-${item.id}`"
                  @update:rating="updateRating"
                />
              </section>

              <section :aria-labelledby="`list-editor-note-${item.id}`" class="space-y-2">
                <h4 :id="`list-editor-note-${item.id}`" class="text-sm font-bold text-gray-900">筆記</h4>
                <textarea
                  v-model="noteDraft"
                  :disabled="disabled"
                  rows="4"
                  placeholder="記錄看到第幾集、推薦理由或心得…"
                  class="w-full resize-none rounded-lg border border-gray-200 bg-gray-50 px-3 py-3 text-sm leading-6 text-gray-700 outline-none transition placeholder:text-gray-500 focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-100 disabled:opacity-50"
                  :aria-labelledby="`list-editor-note-${item.id}`"
                />
                <p class="text-xs text-gray-500">關閉面板時自動儲存。</p>
              </section>

              <section :aria-labelledby="`list-editor-collections-${item.id}`" class="space-y-2">
                <h4 :id="`list-editor-collections-${item.id}`" class="text-sm font-bold text-gray-900">收藏分類</h4>
                <div v-if="collections.length > 0" class="space-y-2">
                  <button
                    v-for="col in collections"
                    :key="col.id"
                    type="button"
                    :disabled="disabled"
                    class="flex min-h-11 w-full items-center gap-3 rounded-xl border px-3 text-left text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-50"
                    :class="isInCollection(col)
                      ? 'border-primary-300 bg-primary-50 text-primary-800'
                      : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50 active:bg-gray-50'"
                    :aria-pressed="isInCollection(col)"
                    @click="emit('toggleCollection', col)"
                  >
                    <span
                      class="grid size-5 shrink-0 place-items-center rounded border"
                      :class="isInCollection(col) ? 'border-primary-700 bg-primary-700' : 'border-gray-300 bg-white'"
                    >
                      <UIcon v-if="isInCollection(col)" name="i-lucide-check" class="size-3 text-white" />
                    </span>
                    <span class="min-w-0 flex-1 truncate">{{ col.name }}</span>
                    <span class="shrink-0 text-xs text-gray-500">{{ col.count }} 部</span>
                  </button>
                </div>
                <p v-else class="rounded-xl border border-dashed border-gray-200 px-3 py-3 text-sm text-gray-600">
                  還沒有收藏分類。可以在清單頁的「收藏分類」新增，例如「本季必追」「補番」。
                </p>
              </section>

              <section class="border-t border-gray-100 pt-5" :aria-labelledby="`list-editor-remove-${item.id}`">
                <h4 :id="`list-editor-remove-${item.id}`" class="text-sm font-bold text-gray-900">從我的清單移除</h4>
                <p class="mt-1 text-xs leading-5 text-gray-500">移除後，這部作品的觀看狀態、評分與筆記也會一併刪除。</p>
                <button
                  v-if="!confirmingRemove"
                  type="button"
                  :disabled="disabled"
                  class="mt-3 min-h-11 w-full rounded-lg border border-red-200 bg-white px-4 text-sm font-bold text-red-700 transition hover:bg-red-50 active:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 disabled:opacity-50"
                  @click="confirmingRemove = true"
                >
                  移除作品
                </button>
                <div v-else class="mt-3 rounded-xl border border-red-200 bg-red-50 p-3" role="alert">
                  <p class="text-sm font-bold text-red-800">確定要移除這部作品？</p>
                  <div class="mt-3 grid grid-cols-2 gap-2">
                    <button
                      type="button"
                      :disabled="disabled"
                      class="min-h-11 rounded-lg border border-red-200 bg-white px-3 text-sm font-bold text-red-700 transition active:bg-red-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 disabled:opacity-50"
                      @click="confirmingRemove = false"
                    >
                      取消
                    </button>
                    <button
                      type="button"
                      :disabled="disabled"
                      class="min-h-11 rounded-lg bg-red-700 px-3 text-sm font-bold text-white transition active:bg-red-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:opacity-50"
                      @click="removeFromEditor"
                    >
                      確認移除
                    </button>
                  </div>
                </div>
              </section>
            </div>
          </template>

          <template #footer="{ close }">
            <button
              type="button"
              class="min-h-11 w-full rounded-lg bg-primary-700 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-primary-800 active:bg-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
              @click="close"
            >
              完成
            </button>
          </template>
        </USlideover>
      </div>
    </div>
  </div>
</template>
