<script setup lang="ts">
const route = useRoute()
const { isAuthed } = useSession()
const config = useRuntimeConfig()

const navItems = [
  { label: '總覽', to: '/', protected: false },
  { label: '資料庫', to: '/catalog', protected: false },
  { label: '本季新番', to: '/seasonal', protected: false },
  { label: '我的清單', to: '/list', protected: true },
  { label: '設定', to: '/settings', protected: true }
]

function isActive(path: string): boolean {
  if (path === '/list') return route.path.startsWith('/list')
  if (path === '/') return route.path === '/' || route.path === '/seasonal'
  return route.path === path
}

function targetFor(item: typeof navItems[number]): string {
  return item.protected && !isAuthed.value ? '/login' : item.to
}
</script>

<template>
  <header class="sticky top-0 z-30 border-b border-gray-200 bg-white/96 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4">
      <NuxtLink to="/" class="flex items-center gap-3 font-bold text-gray-900">
        <span class="grid h-10 w-10 place-items-center rounded-full bg-primary-600 text-white">
          <UIcon name="i-lucide-sparkles" class="size-5" />
        </span>
        <span class="leading-tight">
          <strong class="block">動漫庫</strong>
          <small class="block text-xs font-normal text-gray-500">Anime Vault</small>
        </span>
      </NuxtLink>

      <nav class="hidden flex-1 items-center gap-1 overflow-x-auto md:flex" aria-label="主要導覽">
        <NuxtLink
          v-for="item in navItems"
          :key="item.to"
          :to="targetFor(item)"
          class="rounded-md px-3 py-2 text-sm font-semibold"
          :class="isActive(item.to) ? 'bg-primary-50 text-primary-700' : 'text-gray-600 hover:text-gray-900'"
        >
          {{ item.label }}
        </NuxtLink>
      </nav>

      <div class="hidden text-right text-xs text-gray-500 md:block">
        <span class="block">{{ isAuthed ? '已登入' : '訪客' }}</span>
        <strong class="block truncate text-gray-700">{{ config.public.apiBaseUrl }}</strong>
      </div>
    </div>
  </header>
</template>
