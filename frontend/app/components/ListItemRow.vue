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
const mobileEditorOpen = ref(false)
const mobileNoteDraft = ref(props.item.note)

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

function removeFromMobileEditor() {
  confirmingRemove.value = false
  mobileNoteDraft.value = props.item.note
  mobileEditorOpen.value = false
  emit('remove')
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && colPopoverOpen.value) colPopoverOpen.value = false
}

watch(colPopoverOpen, (open) => {
  if (open) window.addEventListener('keydown', onKeydown)
  else window.removeEventListener('keydown', onKeydown)
})

watch(mobileEditorOpen, (open) => {
  if (open) {
    mobileNoteDraft.value = props.item.note
    return
  }

  confirmingRemove.value = false
  if (mobileNoteDraft.value !== props.item.note) updateNote(mobileNoteDraft.value)
})

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="flex w-full min-w-0 max-w-full gap-3 rounded-xl border border-gray-200 bg-white p-3 shadow-sm transition hover:shadow-md md:gap-4 md:p-4">
    <!-- Cover -->
    <NuxtLink :to="`/anime/${item.anime.id}`" class="shrink-0">
      <img
        v-if="item.anime.imageUrl"
        :src="item.anime.imageUrl"
        :alt="item.anime.name"
        loading="lazy"
        width="80"
        height="112"
        class="h-24 w-[72px] rounded-lg object-cover shadow-sm md:h-28 md:w-20"
      />
      <div
        v-else
        class="flex h-24 w-[72px] items-center justify-center rounded-lg bg-primary-100 text-2xl font-bold text-primary-600 md:h-28 md:w-20"
      >
        {{ item.anime.name.slice(0, 1) }}
      </div>
    </NuxtLink>

    <!-- Info -->
    <div class="min-w-0 flex-1 space-y-2">
      <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
          <NuxtLink :to="`/anime/${item.anime.id}`" class="hover:underline">
            <h3 class="line-clamp-2 text-sm font-bold leading-5 text-gray-950 md:line-clamp-none md:truncate md:text-base md:leading-normal">{{ item.anime.name }}</h3>
          </NuxtLink>
          <p v-if="item.anime.aliases?.length" class="mt-0.5 hidden truncate text-xs text-gray-400 md:block">
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
      <div v-if="genreTags.length > 0" class="hidden flex-wrap items-center gap-1.5 md:flex">
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
      <div v-if="item.collections.length > 0 || collections.length > 0" class="hidden flex-wrap items-center gap-1.5 md:flex">
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
        class="hidden w-full resize-none rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700 placeholder:text-gray-400 outline-none transition focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-100 disabled:opacity-50 md:block"
        @change="event => updateNote((event.target as HTMLTextAreaElement).value)"
      />

      <!-- Mobile actions: the frequently-used watched state remains inline;
           detailed editing moves into a focus-managed bottom sheet. -->
      <div class="mt-auto flex items-center gap-2 md:hidden">
        <button
          type="button"
          role="switch"
          :aria-checked="item.watched"
          :disabled="disabled"
          class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg border px-3 text-sm font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-50"
          :class="item.watched
            ? 'border-primary-200 bg-primary-50 text-primary-700'
            : 'border-gray-200 bg-white text-gray-600 active:bg-gray-50'"
          @click="updateWatched(!item.watched)"
        >
          <UIcon :name="item.watched ? 'i-lucide-circle-check' : 'i-lucide-circle'" class="size-4" />
          {{ item.watched ? '已看' : '標記已看' }}
        </button>

        <USlideover
          v-model:open="mobileEditorOpen"
          side="bottom"
          :title="`編輯「${item.anime.name}」`"
          description="管理評分、筆記與自訂清單。"
          :ui="{
            content: 'max-h-[90dvh] rounded-t-3xl',
            body: 'overflow-y-auto px-4 sm:px-6',
            close: 'size-11',
            footer: 'border-t border-gray-100 px-4 pb-[calc(1rem+env(safe-area-inset-bottom))] pt-3 sm:px-6'
          }"
        >
          <button
            type="button"
            :disabled="disabled"
            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-3 text-sm font-bold text-gray-700 shadow-sm transition active:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-50"
            aria-haspopup="dialog"
          >
            <UIcon name="i-lucide-pencil" class="size-4" />
            編輯
          </button>

          <template #body>
            <div class="space-y-6">
              <section :aria-labelledby="`mobile-list-rating-heading-${item.id}`" class="space-y-2">
                <h4 :id="`mobile-list-rating-heading-${item.id}`" class="text-sm font-bold text-gray-900">評分</h4>
                <select
                  :value="item.rating ? String(item.rating) : UNRATED"
                  :disabled="disabled"
                  class="min-h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm font-semibold text-gray-700 outline-none transition focus:border-primary-400 focus:ring-2 focus:ring-primary-100 disabled:opacity-50"
                  :aria-labelledby="`mobile-list-rating-heading-${item.id}`"
                  @change="event => updateRating((event.target as HTMLSelectElement).value)"
                >
                  <option v-for="opt in ratingOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
              </section>

              <section :aria-labelledby="`mobile-list-note-heading-${item.id}`" class="space-y-2">
                <h4 :id="`mobile-list-note-heading-${item.id}`" class="text-sm font-bold text-gray-900">筆記</h4>
                <textarea
                  v-model="mobileNoteDraft"
                  :disabled="disabled"
                  rows="4"
                  placeholder="記錄集數進度、推薦理由或心得…"
                  class="w-full resize-none rounded-lg border border-gray-200 bg-gray-50 px-3 py-3 text-sm leading-6 text-gray-700 outline-none transition placeholder:text-gray-400 focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-100 disabled:opacity-50"
                  :aria-labelledby="`mobile-list-note-heading-${item.id}`"
                />
              </section>

              <section v-if="collections.length > 0" :aria-labelledby="`mobile-list-collections-heading-${item.id}`" class="space-y-2">
                <h4 :id="`mobile-list-collections-heading-${item.id}`" class="text-sm font-bold text-gray-900">自訂清單</h4>
                <div class="space-y-2">
                  <button
                    v-for="col in collections"
                    :key="col.id"
                    type="button"
                    :disabled="disabled"
                    class="flex min-h-11 w-full items-center gap-3 rounded-xl border px-3 text-left text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-50"
                    :class="isInCollection(col)
                      ? 'border-primary-300 bg-primary-50 text-primary-800'
                      : 'border-gray-200 bg-white text-gray-700 active:bg-gray-50'"
                    :aria-pressed="isInCollection(col)"
                    @click="emit('toggleCollection', col)"
                  >
                    <span
                      class="grid size-5 shrink-0 place-items-center rounded border"
                      :class="isInCollection(col) ? 'border-primary-600 bg-primary-600' : 'border-gray-300 bg-white'"
                    >
                      <UIcon v-if="isInCollection(col)" name="i-lucide-check" class="size-3 text-white" />
                    </span>
                    <span class="min-w-0 flex-1 truncate">{{ col.name }}</span>
                    <span class="shrink-0 text-xs text-gray-400">{{ col.count }}</span>
                  </button>
                </div>
              </section>

              <section class="border-t border-gray-100 pt-5" :aria-labelledby="`mobile-list-remove-heading-${item.id}`">
                <h4 :id="`mobile-list-remove-heading-${item.id}`" class="text-sm font-bold text-gray-900">從我的清單移除</h4>
                <p class="mt-1 text-xs leading-5 text-gray-500">移除後，這部作品的觀看狀態、評分與筆記也會一併刪除。</p>
                <button
                  v-if="!confirmingRemove"
                  type="button"
                  :disabled="disabled"
                  class="mt-3 min-h-11 w-full rounded-lg border border-red-200 bg-white px-4 text-sm font-bold text-red-600 transition active:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 disabled:opacity-50"
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
                      class="min-h-11 rounded-lg bg-red-600 px-3 text-sm font-bold text-white transition active:bg-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:opacity-50"
                      @click="removeFromMobileEditor"
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
              class="min-h-11 w-full rounded-lg bg-primary-600 px-4 text-sm font-bold text-white shadow-sm transition active:bg-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
              @click="close"
            >
              完成
            </button>
          </template>
        </USlideover>
      </div>

      <!-- Desktop actions row -->
      <div class="hidden flex-wrap items-center gap-3 md:flex">
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
