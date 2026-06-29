<script setup lang="ts">
import { normalizeListItem } from '../../utils/normalize'
import type { ListItem } from '../../utils/normalize'

const route = useRoute()
const api = useApi()

const publicUser = ref<any>(null)
const items = ref<ListItem[]>([])
const error = ref('')

async function load() {
  try {
    const result = await api.publicList(route.params.slug as string)
    publicUser.value = result.user
    items.value = (result.items || []).map(normalizeListItem)
  } catch (err: any) {
    error.value = err.message || '載入失敗'
  }
}

onMounted(load)
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

    <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6">
      <AnimeGridCard
        v-for="item in items"
        :key="item.id"
        :anime="item.anime"
        :in-list="false"
        :watched="item.watched"
        @add="() => {}"
      />
    </div>
  </div>
</template>
