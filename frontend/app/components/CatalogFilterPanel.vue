<script setup lang="ts">
import { tagColor } from '../utils/normalize'

const props = defineProps<{
  activeYear: number | null
  currentYear: number
  selectedTags: string[]
  tagOptions: { tag: string; count: number }[]
  tagsLoading: boolean
  loading: boolean
  resultCount: number
}>()

const emit = defineEmits<{
  changeYear: [year: number | null]
  toggleTag: [tag: string]
  clearTags: []
}>()

const activeFilterCount = computed(() =>
  (props.activeYear === null ? 0 : 1) + props.selectedTags.length
)

const triggerRef = ref<HTMLButtonElement | null>(null)

function restoreTriggerFocus() {
  triggerRef.value?.focus()
}
</script>

<template>
  <USlideover
    side="bottom"
    title="篩選動漫資料庫"
    description="選擇年份與作品分類，結果會立即更新。"
    :ui="{
      content: 'max-h-[85dvh] rounded-t-3xl',
      body: 'overflow-y-auto px-4 sm:px-6',
      close: 'size-11',
      footer: 'border-t border-gray-100 px-4 pb-[calc(1rem+env(safe-area-inset-bottom))] pt-3 sm:px-6'
    }"
    @after:leave="restoreTriggerFocus"
  >
    <button
      ref="triggerRef"
      type="button"
      class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-3 text-sm font-semibold text-gray-700 shadow-sm transition active:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
      aria-haspopup="dialog"
    >
      <UIcon name="i-lucide-sliders-horizontal" class="size-4 text-gray-500" />
      篩選
      <span
        v-if="activeFilterCount > 0"
        class="inline-flex min-h-5 min-w-5 items-center justify-center rounded-full bg-primary-600 px-1 text-[11px] font-bold text-white"
      >
        {{ activeFilterCount }}
      </span>
    </button>

    <template #body>
      <div class="space-y-6">
        <section aria-labelledby="catalog-mobile-year-heading" class="space-y-3">
          <div class="flex items-center justify-between gap-3">
            <h3 id="catalog-mobile-year-heading" class="text-sm font-bold text-gray-900">年份</h3>
            <span class="text-xs font-medium text-gray-500">
              {{ activeYear === null ? '近期作品' : `${activeYear} 年` }}
            </span>
          </div>

          <div class="grid grid-cols-[auto_1fr] gap-2">
            <button
              type="button"
              :disabled="loading"
              class="min-h-11 rounded-lg border px-4 text-sm font-semibold shadow-sm transition disabled:opacity-40"
              :class="activeYear === null
                ? 'border-primary-500 bg-primary-50 text-primary-700'
                : 'border-gray-200 bg-white text-gray-600 active:bg-gray-50'"
              :aria-pressed="activeYear === null"
              @click="emit('changeYear', null)"
            >
              近期
            </button>

            <div class="grid grid-cols-[44px_1fr_44px] items-center overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
              <button
                type="button"
                :disabled="loading"
                class="grid min-h-11 place-items-center text-gray-600 transition active:bg-gray-50 disabled:opacity-40"
                aria-label="上一年"
                @click="emit('changeYear', (activeYear ?? currentYear) - 1)"
              >
                <UIcon name="i-lucide-chevron-left" class="size-4" />
              </button>
              <span class="text-center text-sm font-bold text-gray-800">
                {{ activeYear !== null ? `${activeYear} 年` : '選擇年份' }}
              </span>
              <button
                type="button"
                :disabled="loading || (activeYear !== null && activeYear >= currentYear)"
                class="grid min-h-11 place-items-center text-gray-600 transition active:bg-gray-50 disabled:opacity-40"
                aria-label="下一年"
                @click="emit('changeYear', (activeYear ?? currentYear - 1) + 1)"
              >
                <UIcon name="i-lucide-chevron-right" class="size-4" />
              </button>
            </div>
          </div>
        </section>

        <section aria-labelledby="catalog-mobile-tags-heading" class="space-y-3 border-t border-gray-100 pt-5">
          <div class="flex items-center justify-between gap-3">
            <h3 id="catalog-mobile-tags-heading" class="text-sm font-bold text-gray-900">作品分類</h3>
            <button
              v-if="selectedTags.length > 0"
              type="button"
              class="min-h-11 rounded-lg px-2 text-sm font-semibold text-primary-700 transition active:bg-primary-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
              @click="emit('clearTags')"
            >
              清除分類
            </button>
          </div>

          <div v-if="tagsLoading" class="grid grid-cols-3 gap-2" role="status" aria-label="正在載入作品分類">
            <span
              v-for="i in 9"
              :key="i"
              class="h-11 animate-pulse rounded-full bg-gray-100"
              aria-hidden="true"
            />
          </div>

          <div v-else-if="tagOptions.length > 0" class="flex flex-wrap gap-2">
            <button
              v-for="item in tagOptions"
              :key="item.tag"
              type="button"
              class="inline-flex min-h-11 items-center gap-1.5 rounded-full px-4 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
              :class="selectedTags.includes(item.tag) ? 'ring-2 ring-primary-500' : 'active:opacity-70'"
              :style="{ backgroundColor: tagColor(item.tag).bg, color: tagColor(item.tag).text }"
              :aria-pressed="selectedTags.includes(item.tag)"
              @click="emit('toggleTag', item.tag)"
            >
              {{ item.tag }}
              <span class="text-xs opacity-60">{{ item.count }}</span>
            </button>
          </div>

          <p v-else class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-500">
            目前沒有可用的作品分類。
          </p>
        </section>
      </div>
    </template>

    <template #footer="{ close }">
      <button
        type="button"
        class="min-h-11 w-full rounded-lg bg-primary-600 px-4 text-sm font-bold text-white shadow-sm transition active:bg-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2"
        @click="close"
      >
        查看 {{ resultCount }} 筆作品
      </button>
    </template>
  </USlideover>
</template>
