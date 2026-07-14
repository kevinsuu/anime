import { normalizeCollection, normalizeListItem } from '../utils/normalize'
import type { Collection, ListItem } from '../utils/normalize'

export function useAnimeListActions() {
  const api = useApi()
  const { session, isAuthed } = useSession()
  const toast = useToast()

  const list = ref<ListItem[]>([])
  const collections = ref<Collection[]>([])
  const pendingInList = reactive(new Set<number>())
  const pendingWatched = reactive(new Set<number>())
  const pendingCollections = reactive(new Set<string>())

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
    if (listByAnimeId.value.has(animeId) || pendingInList.has(animeId)) return
    pendingInList.add(animeId)
    try {
      const result = await api.addToList(animeId)
      list.value.push(normalizeListItem(result.item))
      toast.add({ title: '已加入清單', color: 'success' })
    } catch (err: any) {
      toast.add({ title: err.message || '加入失敗', color: 'error' })
    } finally {
      pendingInList.delete(animeId)
    }
  }

  async function markWatched(animeId: number) {
    if (!isAuthed.value) return navigateTo('/login')
    if (pendingWatched.has(animeId)) return
    pendingWatched.add(animeId)
    const existing = listByAnimeId.value.get(animeId)
    const previousWatched = existing?.watched ?? false
    const nextWatched = existing ? !existing.watched : true
    let createdItem: ListItem | null = null
    if (existing) existing.watched = nextWatched
    try {
      if (existing) {
        const result = await api.updateListItem(existing.id, { watched: nextWatched })
        const index = list.value.findIndex(item => item.id === existing.id)
        if (index >= 0) list.value[index] = normalizeListItem(result.item)
      } else {
        pendingInList.add(animeId)
        const created = await api.addToList(animeId)
        createdItem = normalizeListItem(created.item)
        const result = await api.updateListItem(created.item.id, { watched: true })
        list.value.push(normalizeListItem(result.item))
      }
      toast.add({ title: nextWatched ? '已標記為看完' : '已取消已看', color: 'success' })
    } catch (err: any) {
      if (existing) existing.watched = previousWatched
      else if (createdItem) list.value.push(createdItem)
      toast.add({ title: err.message || '操作失敗，已恢復原狀態', color: 'error' })
    } finally {
      pendingInList.delete(animeId)
      pendingWatched.delete(animeId)
    }
  }

  async function toggleCollection(animeId: number, collection: Collection) {
    if (!isAuthed.value) return
    const listItem = listByAnimeId.value.get(animeId)
    if (!listItem) return
    const operationKey = `${listItem.id}:${collection.id}`
    if (pendingCollections.has(operationKey)) return
    pendingCollections.add(operationKey)
    const inCollection = listItem.collections.some(item => item.id === collection.id)
    const previousCollections = [...listItem.collections]
    const collectionIndex = collections.value.findIndex(item => item.id === collection.id)
    const previousCount = collectionIndex >= 0 ? collections.value[collectionIndex].count : null
    listItem.collections = inCollection
      ? listItem.collections.filter(item => item.id !== collection.id)
      : [...listItem.collections, { id: collection.id, name: collection.name }]
    if (collectionIndex >= 0) collections.value[collectionIndex].count += inCollection ? -1 : 1
    try {
      if (inCollection) {
        await api.removeFromCollection(collection.id, listItem.id)
      } else {
        await api.addToCollection(collection.id, listItem.id)
      }
    } catch (err: any) {
      listItem.collections = previousCollections
      if (collectionIndex >= 0 && previousCount !== null) collections.value[collectionIndex].count = previousCount
      toast.add({ title: err.message || '操作失敗', color: 'error' })
    } finally {
      pendingCollections.delete(operationKey)
    }
  }

  return {
    list,
    collections,
    listByAnimeId,
    pendingInList,
    pendingWatched,
    loadMyList,
    addAnime,
    markWatched,
    toggleCollection
  }
}
