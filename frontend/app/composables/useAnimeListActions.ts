import { normalizeCollection, normalizeListItem } from '../utils/normalize'
import type { Collection, ListItem } from '../utils/normalize'

export function useAnimeListActions() {
  const api = useApi()
  const { session, isAuthed } = useSession()
  const toast = useToast()

  const list = ref<ListItem[]>([])
  const collections = ref<Collection[]>([])

  const listByAnimeId = computed(() => {
    const items = new Map<number, ListItem>()
    list.value.forEach(item => items.set(item.anime.id, item))
    return items
  })

  async function loadMyList() {
    if (!session.token) return
    try {
      const [listRes, collectionRes] = await Promise.all([api.myList(), api.myCollections()])
      list.value = (listRes.items || []).map(normalizeListItem)
      collections.value = (collectionRes.items || []).map(normalizeCollection)
    } catch (err: any) {
      toast.add({ title: err.message || '載入清單失敗', color: 'error' })
    }
  }

  async function addAnime(animeId: number) {
    if (!isAuthed.value) return navigateTo('/login')
    if (listByAnimeId.value.has(animeId)) return
    try {
      await api.addToList(animeId)
      await loadMyList()
      toast.add({ title: '已加入清單', color: 'success' })
    } catch (err: any) {
      toast.add({ title: err.message || '加入失敗', color: 'error' })
    }
  }

  async function markWatched(animeId: number) {
    if (!isAuthed.value) return navigateTo('/login')
    try {
      const existing = listByAnimeId.value.get(animeId)
      if (existing) {
        await api.updateListItem(existing.id, { watched: !existing.watched })
      } else {
        await api.addToList(animeId)
        const freshList = await api.myList()
        const freshItem = (freshList.items || []).find((item: any) => item.anime?.id === animeId)
        if (freshItem) await api.updateListItem(freshItem.id, { watched: true })
      }
      const item = listByAnimeId.value.get(animeId)
      toast.add({ title: item?.watched ? '已標記為看完' : '已取消已看', color: 'success' })
    } catch (err: any) {
      toast.add({ title: err.message || '操作失敗，清單狀態已重新整理', color: 'error' })
    } finally {
      await loadMyList()
    }
  }

  async function toggleCollection(animeId: number, collection: Collection) {
    if (!isAuthed.value) return
    const listItem = listByAnimeId.value.get(animeId)
    if (!listItem) return
    const inCollection = listItem.collections.some(item => item.id === collection.id)
    try {
      if (inCollection) {
        await api.removeFromCollection(collection.id, listItem.id)
        listItem.collections = listItem.collections.filter(item => item.id !== collection.id)
      } else {
        await api.addToCollection(collection.id, listItem.id)
        listItem.collections = [...listItem.collections, { id: collection.id, name: collection.name }]
      }
      const index = collections.value.findIndex(item => item.id === collection.id)
      if (index >= 0) collections.value[index].count += inCollection ? -1 : 1
    } catch (err: any) {
      toast.add({ title: err.message || '操作失敗', color: 'error' })
    }
  }

  return {
    list,
    collections,
    listByAnimeId,
    loadMyList,
    addAnime,
    markWatched,
    toggleCollection
  }
}
