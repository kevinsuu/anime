<script setup lang="ts">
const { navItems, isActive, targetFor } = useNav()
</script>

<template>
  <nav
    class="fixed inset-x-0 bottom-0 z-40 grid gap-1 border-t border-gray-200 bg-white px-2 pt-2 pb-[calc(0.5rem+env(safe-area-inset-bottom))] shadow-[0_-12px_28px_rgba(15,23,42,0.1)] md:hidden"
    :style="{ gridTemplateColumns: `repeat(${navItems.length}, minmax(0, 1fr))` }"
    aria-label="手機導覽"
  >
    <NuxtLink
      v-for="item in navItems"
      :key="item.to"
      :to="targetFor(item)"
      class="flex min-h-12 flex-col items-center justify-center gap-1 rounded-md py-2 text-xs"
      :class="isActive(item.to) ? 'text-primary-700' : 'text-gray-600'"
    >
      <UIcon :name="item.icon" class="size-5" />
      <span>{{ item.label }}</span>
    </NuxtLink>
  </nav>
</template>
