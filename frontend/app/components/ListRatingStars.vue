<script setup lang="ts">
const props = withDefaults(defineProps<{
  rating: number | null
  disabled?: boolean
  compact?: boolean
  labelledBy?: string
}>(), {
  disabled: false,
  compact: false,
  labelledBy: undefined
})

const emit = defineEmits<{
  'update:rating': [rating: number | null]
}>()

const scores = Array.from({ length: 10 }, (_, index) => index + 1)
const previewRating = ref<number | null>(null)

const ratingText = computed(() => props.rating ? `${props.rating} / 10` : '未評分')
const highlightedRating = computed(() => previewRating.value ?? props.rating ?? 0)

function previewScore(event: PointerEvent, score: number) {
  if (props.disabled || (event.pointerType !== 'mouse' && event.pointerType !== 'pen')) return
  previewRating.value = score
}

function previewFocusedScore(score: number) {
  if (!props.disabled) previewRating.value = score
}

function clearPreview() {
  previewRating.value = null
}

function selectRating(score: number) {
  clearPreview()
  emit('update:rating', props.rating === score ? null : score)
}
</script>

<template>
  <div
    class="flex min-w-0 flex-wrap items-center gap-2"
    data-rating-stars
  >
    <div
      class="flex items-center"
      role="group"
      :aria-label="labelledBy ? undefined : '評分'"
      :aria-labelledby="labelledBy"
    >
      <button
        v-for="score in scores"
        :key="score"
        type="button"
        :disabled="disabled"
        :data-rating-highlighted="score <= highlightedRating"
        :aria-label="`${score} 分${rating === score ? '，目前評分；再按一次清除' : ''}`"
        :aria-pressed="rating === score"
        class="grid shrink-0 place-items-center rounded-md transition focus-visible:z-10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50"
        :class="compact ? 'size-6' : 'h-10 w-7 min-[360px]:w-8'"
        @blur="clearPreview"
        @click="selectRating(score)"
        @focus="previewFocusedScore(score)"
        @pointerenter="previewScore($event, score)"
        @pointerleave="clearPreview"
      >
        <UIcon
          name="i-lucide-star"
          class="transition-colors"
          :class="[
            compact ? 'size-[18px]' : 'size-6',
            score <= highlightedRating
              ? 'fill-amber-400 text-amber-400'
              : 'text-gray-300'
          ]"
        />
      </button>
    </div>

    <output
      class="shrink-0 font-semibold tabular-nums text-gray-500"
      :class="compact ? 'text-[11px]' : 'text-xs'"
      aria-live="polite"
    >
      {{ ratingText }}
    </output>
  </div>
</template>
