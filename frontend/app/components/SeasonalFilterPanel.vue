<script setup lang="ts">
import { genreCategories } from '../composables/useSeasonalCatalog'

const props = defineProps<{
  open: boolean
  year: number
  season: string
  category: string
  status: string
  syncResult: { fetched: number; imported: number; skipped: number } | null
  loading: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'update:year': [value: number]
  'update:season': [value: string]
  'update:category': [value: string]
  'update:status': [value: string]
  sync: []
}>()

const seasonOptions = [
  { value: 'winter', label: '冬番（1-3 月）' },
  { value: 'spring', label: '春番（4-6 月）' },
  { value: 'summer', label: '夏番（7-9 月）' },
  { value: 'fall', label: '秋番（10-12 月）' }
]

const statusOptions = [
  { value: 'all', label: '全部作品' },
  { value: 'listed', label: '已加入清單' },
  { value: 'unlisted', label: '未加入清單' },
  { value: 'watched', label: '已看' },
  { value: 'queued', label: '待補' },
  { value: 'with-cover', label: '有封面' }
]
</script>

<template>
  <USlideover :open="open" @update:open="value => emit('update:open', value)">
    <template #content>
      <div class="flex flex-col gap-6 p-4">
        <h2 class="text-lg font-bold">篩選與同步</h2>

        <section class="space-y-2">
          <p class="text-xs font-bold uppercase text-gray-500">季度選擇</p>
          <div class="flex gap-2">
            <UInput
              :model-value="year"
              type="number"
              class="w-24"
              @update:model-value="value => emit('update:year', Number(value))"
            />
            <USelect
              :model-value="season"
              :items="seasonOptions"
              class="flex-1"
              @update:model-value="value => emit('update:season', value)"
            />
          </div>
          <UButton block :loading="loading" @click="emit('sync')">同步新番資料</UButton>
          <div v-if="syncResult" class="flex flex-wrap gap-2 text-xs text-gray-600">
            <span>抓取 {{ syncResult.fetched }}</span>
            <span>匯入 {{ syncResult.imported }}</span>
            <span>略過 {{ syncResult.skipped }}</span>
          </div>
        </section>

        <section class="space-y-2">
          <p class="text-xs font-bold uppercase text-gray-500">分類</p>
          <div class="flex flex-wrap gap-2">
            <UButton
              v-for="genre in genreCategories"
              :key="genre.key"
              size="sm"
              :color="category === genre.key ? 'primary' : 'neutral'"
              :variant="category === genre.key ? 'solid' : 'outline'"
              @click="emit('update:category', genre.key)"
            >
              {{ genre.label }}
            </UButton>
          </div>
        </section>

        <section class="space-y-2">
          <p class="text-xs font-bold uppercase text-gray-500">觀看狀態</p>
          <div class="flex flex-wrap gap-2">
            <UButton
              v-for="option in statusOptions"
              :key="option.value"
              size="sm"
              :color="status === option.value ? 'primary' : 'neutral'"
              :variant="status === option.value ? 'solid' : 'outline'"
              @click="emit('update:status', option.value)"
            >
              {{ option.label }}
            </UButton>
          </div>
        </section>
      </div>
    </template>
  </USlideover>
</template>
