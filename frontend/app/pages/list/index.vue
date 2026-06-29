<script setup lang="ts">
import { normalizeListItem } from '../../utils/normalize'
import type { ListItem } from '../../utils/normalize'

definePageMeta({ middleware: 'auth' })

const api = useApi()
const route = useRoute()
const router = useRouter()

const list = ref<ListItem[]>([])
const loading = ref(false)
const error = ref('')
const notice = ref('')

const activeFilter = computed(() => (route.query.filter as string) || 'all')

const filterTabs = [
  { value: 'all', label: '全部' },
  { value: 'watched', label: '已看' },
  { value: 'unwatched', label: '未看' }
]

function setFilter(value: string | number) {
  router.push({ path: '/list', query: value === 'all' ? {} : { filter: String(value) } })
}

const filteredList = computed(() => {
  if (activeFilter.value === 'watched') return list.value.filter(item => item.watched)
  if (activeFilter.value === 'unwatched') return list.value.filter(item => !item.watched)
  return list.value
})

async function loadList() {
  loading.value = true
  error.value = ''
  try {
    const result = await api.myList()
    list.value = (result.items || []).map(normalizeListItem)
  } catch (err: any) {
    error.value = err.message || '載入失敗'
  } finally {
    loading.value = false
  }
}

async function updateItem(item: ListItem, patch: Record<string, any>) {
  try {
    const result = await api.updateListItem(item.id, patch)
    const index = list.value.findIndex(existing => existing.id === item.id)
    if (index >= 0) list.value[index] = normalizeListItem(result.item)
    notice.value = '清單已更新'
  } catch (err: any) {
    error.value = err.message || '更新失敗'
  }
}

async function removeItem(item: ListItem) {
  try {
    await api.deleteListItem(item.id)
    list.value = list.value.filter(existing => existing.id !== item.id)
    notice.value = '已從清單移除'
  } catch (err: any) {
    error.value = err.message || '移除失敗'
  }
}

onMounted(loadList)
</script>

<template>
  <div class="space-y-4">
    <header class="flex items-center justify-between">
      <h1 class="text-2xl font-bold">我的清單</h1>
      <UTabs :model-value="activeFilter" :items="filterTabs" @update:model-value="setFilter" />
    </header>

    <UAlert v-if="error" color="error" :title="error" />
    <UAlert v-if="notice && !error" color="success" :title="notice" />

    <div v-if="filteredList.length === 0" class="rounded-md border border-dashed border-gray-300 p-6 text-center text-gray-500">
      清單目前是空的，先到資料庫搜尋作品，再加入你的追番清單。
      <UButton class="mt-3" to="/catalog">去搜尋作品</UButton>
    </div>

    <div class="space-y-3">
      <ListItemRow
        v-for="item in filteredList"
        :key="item.id"
        :item="item"
        :disabled="loading"
        @update="patch => updateItem(item, patch)"
        @remove="removeItem(item)"
      />
    </div>
  </div>
</template>
