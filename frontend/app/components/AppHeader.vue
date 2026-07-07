<script setup lang="ts">
const { session, isAuthed } = useSession()
const { navItems, isActive, targetFor } = useNav()
</script>

<template>
  <header class="sticky top-0 z-30 border-b border-gray-200 bg-white/96 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4">
      <NuxtLink to="/" class="flex items-center gap-3 font-bold text-gray-900">
        <img src="/favicon-180.png" alt="Anime Library" class="h-10 w-10 shrink-0 rounded-2xl object-cover">
        <span class="leading-tight">
          <strong class="block">動漫庫</strong>
          <small class="block text-xs font-normal text-gray-500">Anime Library</small>
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

      <NuxtLink
        v-if="isAuthed"
        to="/settings"
        class="hidden items-center gap-2 rounded-full p-1 pr-3 transition-colors hover:bg-gray-100 md:flex"
      >
        <img
          v-if="session.user?.avatar_url"
          :src="session.user.avatar_url"
          alt=""
          referrerpolicy="no-referrer"
          class="size-8 shrink-0 rounded-full object-cover ring-2 ring-gray-100"
        >
        <span v-else class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-50 ring-2 ring-gray-100">
          <UIcon name="i-lucide-user" class="size-4 text-primary-400" />
        </span>
        <strong class="max-w-28 truncate text-sm text-gray-700">{{ session.user?.display_name || session.user?.email }}</strong>
      </NuxtLink>
      <NuxtLink v-else to="/login" class="hidden text-xs font-semibold text-gray-500 hover:text-gray-900 md:block">
        訪客・登入
      </NuxtLink>
    </div>
  </header>
</template>
