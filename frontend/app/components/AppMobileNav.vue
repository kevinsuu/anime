<script setup lang="ts">
const route = useRoute()
const { isAuthed } = useSession()

const navItems = [
  { label: '新番總覽', to: '/', icon: 'i-lucide-home', protected: false },
  { label: '資料庫', to: '/catalog', icon: 'i-lucide-search', protected: false },
  { label: '我的清單', to: '/list', icon: 'i-lucide-library', protected: true },
  { label: '設定', to: '/settings', icon: 'i-lucide-settings', protected: true }
]

function isActive(path: string): boolean {
  if (path === '/list') return route.path.startsWith('/list')
  if (path === '/') return route.path === '/' || route.path === '/seasonal'
  return route.path === path
}

function targetFor(item: { to: string; protected: boolean }): string {
  return item.protected && !isAuthed.value ? '/login' : item.to
}
</script>

<template>
  <nav
    class="fixed inset-x-0 bottom-0 z-40 grid gap-1 border-t border-gray-200 bg-white p-2 shadow-[0_-12px_28px_rgba(15,23,42,0.1)] md:hidden"
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
