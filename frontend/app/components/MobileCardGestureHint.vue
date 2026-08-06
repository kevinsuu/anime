<template>
  <p
    data-mobile-card-gesture-hint
    class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] font-semibold leading-none text-gray-500 md:hidden"
    aria-label="手機卡片操作：點兩下收藏，長按設為已觀看"
  >
    <span class="inline-flex items-center gap-1">
      <UIcon name="i-lucide-heart" class="size-3.5 text-rose-500" aria-hidden="true" />
      點兩下收藏
    </span>
    <span class="h-3 w-px bg-gray-200" aria-hidden="true" />
    <span class="inline-flex items-center gap-1">
      <UIcon name="i-lucide-circle-check" class="size-3.5 text-emerald-600" aria-hidden="true" />
      長按設為已觀看
    </span>
  </p>
</template>
