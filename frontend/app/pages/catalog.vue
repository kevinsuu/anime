<script setup lang="ts">
import { normalizeAnime } from '../utils/normalize'
import type { Anime } from '../utils/normalize'

const api = useApi()
const { isAuthed } = useSession()

const query = ref('')
const catalog = ref<Anime[]>([])
const loading = ref(false)
const error = ref('')
const notice = ref('')

async function search() {
  loading.value = true
  error.value = ''
  try {
    const result = await api.searchAnime(query.value)
    catalog.value = (result.items || []).map(normalizeAnime)
  } catch (err: any) {
    error.value = err.message || '搜尋失敗'
  } finally {
    loading.value = false
  }
}

async function addAnime(animeId: number) {
  if (!isAuthed.value) return navigateTo('/login')
  try {
    await api.addToList(animeId)
    notice.value = '已加入你的清單'
  } catch (err: any) {
    error.value = err.message || '加入失敗'
  }
}

async function createAnime(payload: { name: string; description: string; imageUrl: string }) {
  loading.value = true
  error.value = ''
  try {
    const result = await api.createAnime(payload)
    catalog.value = [normalizeAnime(result.item), ...catalog.value]
    notice.value = '已建立作品資料'
  } catch (err: any) {
    error.value = err.message || '建立失敗'
  } finally {
    loading.value = false
  }
}

onMounted(search)
</script>

<template>
  <div class="grid gap-4 md:grid-cols-[1fr_320px]">
    <div class="space-y-4">
      <header class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">搜尋動漫資料庫</h1>
        <UBadge color="neutral">{{ catalog.length }} 筆</UBadge>
      </header>

      <UAlert v-if="error" color="error" :title="error" />
      <UAlert v-if="notice && !error" color="success" :title="notice" />

      <form class="flex gap-2" @submit.prevent="search">
        <UInput v-model="query" class="flex-1" placeholder="例如：芙莉蓮、Bocchi、排球" />
        <UButton type="submit" :loading="loading">搜尋</UButton>
      </form>

      <div v-if="catalog.length === 0 && !error" class="rounded-md border border-dashed border-gray-300 p-6 text-center text-gray-500">
        沒有找到作品，可以換個關鍵字，或在右側手動建立資料。
      </div>

      <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6">
        <AnimeGridCard
          v-for="anime in catalog"
          :key="anime.id"
          :anime="anime"
          :in-list="false"
          :watched="false"
          @add="addAnime"
        />
      </div>
    </div>

    <ManualAnimeForm :disabled="!isAuthed" :loading="loading" @submit="createAnime" />
  </div>
</template>
