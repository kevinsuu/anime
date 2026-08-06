<script setup lang="ts">
defineProps<{
  message: string
}>()

const emit = defineEmits<{
  retry: []
}>()
</script>

<template>
  <section
    data-card-status-error
    role="alert"
    class="flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center"
  >
    <div class="flex min-w-0 flex-1 items-start gap-3">
      <span class="grid size-9 shrink-0 place-items-center rounded-full bg-white text-amber-700 shadow-sm ring-1 ring-amber-200">
        <UIcon name="i-lucide-cloud-off" class="size-4.5" aria-hidden="true" />
      </span>
      <div class="min-w-0">
        <p class="text-sm font-extrabold text-amber-950">收藏狀態尚未同步</p>
        <p class="mt-0.5 text-xs font-medium leading-5 text-amber-800">{{ message }}</p>
        <p class="text-xs font-medium leading-5 text-amber-800">你仍可瀏覽作品，重新同步前暫停收藏操作。</p>
      </div>
    </div>
    <button
      type="button"
      class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-lg bg-amber-900 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-amber-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-700 focus-visible:ring-offset-2 focus-visible:ring-offset-amber-50 sm:min-h-9"
      @click="emit('retry')"
    >
      <UIcon name="i-lucide-refresh-cw" class="size-4" aria-hidden="true" />
      重新同步
    </button>
  </section>
</template>
