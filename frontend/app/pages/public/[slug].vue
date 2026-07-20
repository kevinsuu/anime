<script setup lang="ts">
import { normalizeListItem } from '../../utils/normalize'

const route = useRoute()
const api = useApi()

// SSR-fetched (public endpoint, no auth needed) so the shared link renders
// with content on first paint instead of blank-then-hydrate-then-fetch.
const { data, error: fetchError } = await useAsyncData(
  `public-list-${route.params.slug}`,
  async () => {
    const result = await api.publicList(route.params.slug as string)
    return {
      user: result.user,
      items: (result.items || []).map(normalizeListItem),
    }
  }
)

const publicUser = computed(() => data.value?.user ?? null)
const items = computed(() => data.value?.items ?? [])
const error = computed(() => fetchError.value ? (fetchError.value.message || '載入失敗') : '')
</script>

<template>
  <div class="space-y-4">
    <header class="flex items-center justify-between">
      <h1 class="text-2xl font-bold">{{ publicUser?.display_name || '使用者' }} 的公開清單</h1>
      <UBadge color="neutral">{{ items.length }} 筆</UBadge>
    </header>

    <UAlert v-if="error" color="error" :title="error" />

    <div v-if="items.length === 0 && !error" class="rounded-md border border-dashed border-gray-300 p-6 text-center text-gray-500">
      這份公開清單目前沒有作品。
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-6">
      <AnimeGridCard
        v-for="item in items"
        :key="item.id"
        :anime="item.anime"
        :in-list="false"
        :watched="item.watched"
        :show-actions="false"
      />
    </div>
  </div>
</template>
