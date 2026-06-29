<script setup lang="ts">
import type { ListItem } from '../utils/normalize'

const props = defineProps<{
  item: ListItem
  disabled: boolean
}>()

const emit = defineEmits<{
  update: [patch: Record<string, any>]
  remove: []
}>()

const confirmingRemove = ref(false)

const UNRATED = 'unrated'
const ratingOptions = [
  { value: UNRATED, label: '未評分' },
  ...Array.from({ length: 10 }, (_, i) => ({ value: String(i + 1), label: `${i + 1} 分` }))
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
</script>

<template>
  <UCard>
    <div class="grid grid-cols-[88px_1fr] gap-3 sm:grid-cols-[110px_1fr_180px]">
      <img
        v-if="item.anime.imageUrl"
        :src="item.anime.imageUrl"
        :alt="item.anime.name"
        loading="lazy"
        class="aspect-[3/4] w-full rounded-md object-cover"
      />
      <div v-else class="grid aspect-[3/4] w-full place-items-center rounded-md bg-primary-600 text-2xl font-bold text-white">
        {{ item.anime.name.slice(0, 1) }}
      </div>

      <div class="space-y-1">
        <UBadge :color="item.watched ? 'success' : 'neutral'">
          {{ item.watched ? '已看完' : '待補完' }}
        </UBadge>
        <h3 class="text-lg font-bold">{{ item.anime.name }}</h3>
        <p class="line-clamp-2 text-sm text-gray-500">{{ item.anime.description }}</p>
        <UTextarea
          :model-value="item.note"
          :rows="2"
          :disabled="disabled"
          placeholder="記錄集數進度、推薦理由或心得"
          @change="event => updateNote((event.target as HTMLTextAreaElement).value)"
        />
      </div>

      <div class="col-span-2 flex flex-wrap items-center gap-3 sm:col-span-1 sm:flex-col sm:items-stretch">
        <USwitch :model-value="item.watched" :disabled="disabled" label="已看" @update:model-value="updateWatched" />

        <USelect
          :model-value="item.rating ? String(item.rating) : UNRATED"
          :items="ratingOptions"
          :disabled="disabled"
          @update:model-value="updateRating"
        />

        <UButton
          v-if="!confirmingRemove"
          color="error"
          variant="outline"
          :disabled="disabled"
          @click="confirmingRemove = true"
        >
          移除
        </UButton>
        <div v-else class="flex gap-2">
          <UButton color="neutral" variant="ghost" :disabled="disabled" @click="confirmingRemove = false">取消</UButton>
          <UButton color="error" :disabled="disabled" @click="confirmingRemove = false; $emit('remove')">確認移除</UButton>
        </div>
      </div>
    </div>
  </UCard>
</template>
