<script setup lang="ts">
import { deriveFilterOptions } from '../composables/useSeasonalCatalog'
import type { AnimeSummary } from '../utils/normalize'

const props = defineProps<{
  open: boolean
  year: number
  season: string
  sourceTag: string
  genreTags: string[]
  actor: string
  status: string
  animeList: AnimeSummary[]
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'update:year': [value: number]
  'update:season': [value: string]
  'update:sourceTag': [value: string]
  'update:genreTags': [value: string[]]
  'update:actor': [value: string]
  'update:status': [value: string]
  'reset': []
}>()

const seasonOptions = [
  { value: 'winter', label: '冬番（1月）' },
  { value: 'spring', label: '春番（4月）' },
  { value: 'summer', label: '夏番（7月）' },
  { value: 'fall', label: '秋番（10月）' },
]

const statusOptions = [
  { value: 'all', label: '全部作品' },
  { value: 'listed', label: '已加入清單' },
  { value: 'unlisted', label: '未加入清單' },
  { value: 'watched', label: '已看' },
  { value: 'queued', label: '待補' },
  { value: 'with-cover', label: '有封面' },
]

const filterOptions = computed(() => deriveFilterOptions(props.animeList))

const activeCount = computed(() => {
  let n = 0
  if (props.sourceTag) n++
  if (props.genreTags.length > 0) n++
  if (props.actor) n++
  if (props.status !== 'all') n++
  return n
})

function btn(active: boolean) {
  return active
    ? 'bg-primary-500 text-white shadow-sm'
    : 'bg-white/10 text-gray-200 hover:bg-white/20 hover:text-white'
}

function toggleGenre(tag: string) {
  const next = props.genreTags.includes(tag)
    ? props.genreTags.filter(item => item !== tag)
    : [...props.genreTags, tag]
  emit('update:genreTags', next)
}
</script>

<template>
  <USlideover :open="open" @update:open="v => emit('update:open', v)">
    <template #content>
      <div class="flex h-full flex-col bg-gray-950 text-white">

        <!-- Header -->
        <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
          <div class="flex items-center gap-2.5">
            <h2 class="text-base font-bold text-white">篩選條件</h2>
            <span
              v-if="activeCount > 0"
              class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary-500 px-1.5 text-[11px] font-bold text-white"
            >{{ activeCount }}</span>
          </div>
          <div class="flex items-center gap-2">
            <button
              v-if="activeCount > 0"
              class="min-h-11 rounded px-2 py-1 text-xs font-semibold text-gray-400 transition-colors hover:text-white md:min-h-0"
              @click="emit('reset')"
            >
              清除全部
            </button>
            <button
              class="grid h-11 w-11 place-items-center rounded-md text-gray-400 transition-colors hover:bg-white/10 hover:text-white md:h-8 md:w-8"
              aria-label="關閉"
              @click="emit('update:open', false)"
            >
              <UIcon name="i-lucide-x" class="size-4" />
            </button>
          </div>
        </div>

        <!-- Body -->
        <div class="flex flex-1 flex-col gap-6 overflow-y-auto px-5 py-5">

          <!-- Season selector -->
          <section class="space-y-3">
            <p id="filter-season-label" class="text-[11px] font-bold uppercase tracking-widest text-gray-400">季度</p>
            <div class="flex gap-2">
              <label for="filter-year-input" class="sr-only">年份</label>
              <input
                id="filter-year-input"
                :value="year"
                type="number"
                aria-labelledby="filter-season-label"
                class="min-h-11 w-24 rounded-lg border border-white/20 bg-white/10 px-3 py-2 text-sm text-white outline-none focus:border-primary-400 focus:ring-1 focus:ring-primary-400"
                @change="emit('update:year', Number(($event.target as HTMLInputElement).value))"
              />
              <label for="filter-season-select" class="sr-only">季度</label>
              <select
                id="filter-season-select"
                :value="season"
                class="min-h-11 flex-1 rounded-lg border border-white/20 bg-white/10 px-3 py-2 text-sm text-white outline-none focus:border-primary-400"
                @change="emit('update:season', ($event.target as HTMLSelectElement).value)"
              >
                <option v-for="opt in seasonOptions" :key="opt.value" :value="opt.value" class="bg-gray-900">
                  {{ opt.label }}
                </option>
              </select>
            </div>
          </section>

          <section v-if="filterOptions.genres.length > 0" class="space-y-3">
            <p class="text-[11px] font-bold uppercase tracking-widest text-gray-400">類型</p>
            <div class="flex flex-wrap gap-2">
              <button
                class="min-h-11 rounded-full px-3 py-1 text-xs font-semibold transition-all md:min-h-0"
                :class="btn(genreTags.length === 0)"
                @click="emit('update:genreTags', [])"
              >
                全部
              </button>
              <button
                v-for="item in filterOptions.genres"
                :key="item.tag"
                class="min-h-11 rounded-full px-3 py-1 text-xs font-semibold transition-all md:min-h-0"
                :class="btn(genreTags.includes(item.tag))"
                @click="toggleGenre(item.tag)"
              >
                {{ item.tag }}<span class="ml-1 opacity-60">({{ item.count }})</span>
              </button>
            </div>
          </section>

          <!-- Source type (種類) -->
          <section v-if="filterOptions.sources.length > 0" class="space-y-3">
            <p class="text-[11px] font-bold uppercase tracking-widest text-gray-400">種類</p>
            <div class="flex flex-wrap gap-2">
              <button
                class="min-h-11 rounded-full px-3 py-1 text-xs font-semibold transition-all md:min-h-0"
                :class="btn(!sourceTag)"
                @click="emit('update:sourceTag', '')"
              >
                全部
              </button>
              <button
                v-for="item in filterOptions.sources"
                :key="item.tag"
                class="min-h-11 rounded-full px-3 py-1 text-xs font-semibold transition-all md:min-h-0"
                :class="btn(sourceTag === item.tag)"
                @click="emit('update:sourceTag', sourceTag === item.tag ? '' : item.tag)"
              >
                {{ item.tag }}<span class="ml-1 opacity-60">({{ item.count }})</span>
              </button>
            </div>
          </section>

          <!-- Cast actors -->
          <section v-if="filterOptions.actors.length > 0" class="space-y-3">
            <p class="text-[11px] font-bold uppercase tracking-widest text-gray-400">配音員</p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="item in filterOptions.actors"
                :key="item.actor"
                class="flex min-h-11 items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition-all md:min-h-0"
                :class="btn(actor === item.actor)"
                @click="emit('update:actor', actor === item.actor ? '' : item.actor)"
              >
                {{ item.actor }}<span class="opacity-60">({{ item.count }})</span>
              </button>
            </div>
          </section>

          <!-- Watch status -->
          <section class="space-y-3">
            <p class="text-[11px] font-bold uppercase tracking-widest text-gray-400">觀看狀態</p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="opt in statusOptions"
                :key="opt.value"
                class="min-h-11 rounded-full px-3 py-1 text-xs font-semibold transition-all md:min-h-0"
                :class="btn(status === opt.value)"
                @click="emit('update:status', opt.value)"
              >
                {{ opt.label }}
              </button>
            </div>
          </section>
        </div>

        <!-- Footer -->
        <div class="border-t border-white/10 px-5 py-4">
          <button
            class="min-h-11 w-full rounded-lg bg-primary-600 py-2.5 text-sm font-bold text-white transition-colors hover:bg-primary-500"
            @click="emit('update:open', false)"
          >
            套用{{ activeCount > 0 ? `（${activeCount} 個條件）` : '' }}
          </button>
        </div>
      </div>
    </template>
  </USlideover>
</template>
