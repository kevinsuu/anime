import { reactive, ref, toValue, watch, type MaybeRefOrGetter } from 'vue'
import { normalizeCollection } from '../utils/normalize'
import type { Collection } from '../utils/normalize'
import { apiErrorMessage } from '../utils/apiError'

export interface AnimeCardStatus {
  animeId: number
  listItemId: number
  watched: boolean
  collectionIds: number[]
}

function asRecord(value: unknown): Record<string, unknown> | null {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    ? value as Record<string, unknown>
    : null
}

function positiveInteger(value: unknown): number | null {
  const parsed = Number(value)
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null
}

export function normalizeAnimeIds(animeIds: readonly number[]): number[] {
  return [...new Set(animeIds.filter(id => Number.isInteger(id) && id > 0))].sort((a, b) => a - b)
}

export function normalizeAnimeCardStatus(value: unknown): AnimeCardStatus | null {
  const payload = asRecord(value)
  if (!payload) return null

  const animeId = positiveInteger(payload.anime_id)
  const listItemId = positiveInteger(payload.list_item_id)
  if (animeId === null || listItemId === null) return null

  const collectionIds = Array.isArray(payload.collection_ids)
    ? normalizeAnimeIds(payload.collection_ids.map(Number))
    : []

  return {
    animeId,
    listItemId,
    watched: Boolean(payload.watched),
    collectionIds
  }
}

function statusFromMutationResponse(
  animeId: number,
  response: unknown,
  fallback: Pick<AnimeCardStatus, 'watched' | 'collectionIds'>
): AnimeCardStatus {
  const result = asRecord(response)
  const item = asRecord(result?.item)
  const listItemId = positiveInteger(item?.id)
  if (!item || listItemId === null) throw new Error('清單 API 回應格式錯誤')

  const collections = Array.isArray(item.collections)
    ? item.collections
        .map(collection => positiveInteger(asRecord(collection)?.id))
        .filter((id): id is number => id !== null)
    : fallback.collectionIds

  return {
    animeId,
    listItemId,
    watched: item.watched === undefined ? fallback.watched : Boolean(item.watched),
    collectionIds: normalizeAnimeIds(collections)
  }
}

export function useAnimeCardStatuses(animeIds: MaybeRefOrGetter<readonly number[]>) {
  const api = useApi()
  const { isAuthed } = useSession()
  const toast = useToast()

  const statusesByAnimeId = reactive(new Map<number, AnimeCardStatus>())
  const collections = ref<Collection[]>([])
  const bootstrapLoading = ref(false)
  const bootstrapError = ref('')
  const pendingInList = reactive(new Set<number>())
  const pendingWatched = reactive(new Set<number>())
  const pendingCollections = reactive(new Set<string>())
  const pendingListOperations = reactive(new Set<number>())

  let loadedKey = ''
  let loadingKey = ''
  let scopeKey = ''
  let scopeGeneration = 0
  let scopedAnimeIds = new Set<number>()
  let bootstrapRequestId = 0
  let bootstrapPromise: Promise<void> | null = null
  let mutationTokenSequence = 0
  const mutationTokens = new Map<string, number>()

  function beginMutation(operationKey: string): number {
    const token = ++mutationTokenSequence
    mutationTokens.set(operationKey, token)
    return token
  }

  function finishMutation(operationKey: string, token: number, cleanup: () => void) {
    if (mutationTokens.get(operationKey) !== token) return
    mutationTokens.delete(operationKey)
    cleanup()
  }

  function clearPendingMutations() {
    mutationTokens.clear()
    pendingInList.clear()
    pendingWatched.clear()
    pendingCollections.clear()
    pendingListOperations.clear()
  }

  function cancelBootstrap() {
    bootstrapRequestId++
    bootstrapPromise = null
    loadingKey = ''
    bootstrapLoading.value = false
  }

  function resetStatusState() {
    scopeGeneration++
    scopeKey = ''
    scopedAnimeIds = new Set()
    cancelBootstrap()
    loadedKey = ''
    statusesByAnimeId.clear()
    collections.value = []
    bootstrapError.value = ''
    clearPendingMutations()
  }

  function setStatusScope(ids: readonly number[]): number {
    const key = ids.join(',')
    if (key !== scopeKey) {
      scopeGeneration++
      scopeKey = key
      scopedAnimeIds = new Set(ids)
      bootstrapError.value = ''
    }
    return scopeGeneration
  }

  function mutationIsCurrent(operationKey: string, token: number, animeId: number): boolean {
    return mutationTokens.get(operationKey) === token
      && isAuthed.value
      && scopedAnimeIds.has(animeId)
  }

  function hasPendingStatusMutation(animeId: number): boolean {
    return pendingListOperations.has(animeId)
      || pendingInList.has(animeId)
      || pendingWatched.has(animeId)
      || hasPendingCollectionOperation(animeId)
  }

  async function loadCardStatuses(requestedAnimeIds: readonly number[]) {
    if (!isAuthed.value) {
      resetStatusState()
      return
    }

    const ids = normalizeAnimeIds(requestedAnimeIds)
    if (ids.length === 0) {
      resetStatusState()
      return
    }

    const key = ids.join(',')
    const generation = setStatusScope(ids)
    if (key === loadedKey) {
      if (bootstrapLoading.value || loadingKey) cancelBootstrap()
      bootstrapError.value = ''
      return
    }
    if (key === loadingKey && bootstrapPromise) return bootstrapPromise

    const requestId = ++bootstrapRequestId
    loadingKey = key
    bootstrapLoading.value = true
    bootstrapError.value = ''

    const task = (async () => {
      try {
        const result = await api.meBootstrap(ids)
        if (
          requestId !== bootstrapRequestId
          || generation !== scopeGeneration
          || !isAuthed.value
          || key !== scopeKey
        ) return

        const requestedIds = new Set(ids)
        const nextStatuses = new Map<number, AnimeCardStatus>()
        for (const payload of Array.isArray(result.statuses) ? result.statuses : []) {
          const status = normalizeAnimeCardStatus(payload)
          if (status && requestedIds.has(status.animeId)) nextStatuses.set(status.animeId, status)
        }

        // A scope change can start a new bootstrap while a card mutation from
        // the previous scope is still in flight. Keep its optimistic state:
        // the bootstrap snapshot may predate the mutation and would otherwise
        // make the card briefly revert (or overwrite the final response).
        for (const animeId of requestedIds) {
          if (!hasPendingStatusMutation(animeId)) continue
          const currentStatus = statusesByAnimeId.get(animeId)
          if (currentStatus) nextStatuses.set(animeId, currentStatus)
          else nextStatuses.delete(animeId)
        }

        statusesByAnimeId.clear()
        nextStatuses.forEach((status, animeId) => statusesByAnimeId.set(animeId, status))
        collections.value = (Array.isArray(result.collections) ? result.collections : [])
          .map(asRecord)
          .filter((collection): collection is Record<string, unknown> => collection !== null)
          .map(collection => normalizeCollection(collection))
        loadedKey = key
      } catch (error: unknown) {
        if (requestId !== bootstrapRequestId) return
        bootstrapError.value = apiErrorMessage(error, '載入卡片狀態失敗')
        toast.add({ title: bootstrapError.value, color: 'error' })
      } finally {
        if (requestId === bootstrapRequestId) {
          loadingKey = ''
          bootstrapLoading.value = false
          bootstrapPromise = null
        }
      }
    })()

    bootstrapPromise = task
    return task
  }

  function retryCardStatuses() {
    return loadCardStatuses(normalizeAnimeIds(toValue(animeIds)))
  }

  function hasPendingCollectionOperation(animeId: number): boolean {
    const prefix = `${animeId}:`
    return [...pendingCollections].some(key => key.startsWith(prefix))
  }

  function isInList(animeId: number): boolean {
    return statusesByAnimeId.has(animeId) || pendingInList.has(animeId)
  }

  function isWatched(animeId: number): boolean {
    return statusesByAnimeId.get(animeId)?.watched ?? pendingWatched.has(animeId)
  }

  function isStatusPending(animeId: number): boolean {
    return hasPendingStatusMutation(animeId)
  }

  async function toggleAnimeInList(animeId: number) {
    if (!isAuthed.value) return navigateTo('/login')
    if (bootstrapLoading.value || bootstrapError.value || !scopedAnimeIds.has(animeId)) return
    if (pendingListOperations.has(animeId) || pendingWatched.has(animeId) || hasPendingCollectionOperation(animeId)) return

    const operationKey = `list:${animeId}`
    const operationToken = beginMutation(operationKey)
    pendingListOperations.add(animeId)
    const existing = statusesByAnimeId.get(animeId)

    if (existing) {
      const previousStatus: AnimeCardStatus = {
        ...existing,
        collectionIds: [...existing.collectionIds]
      }
      const previousCounts = new Map<number, number>()
      for (const collectionId of existing.collectionIds) {
        const collection = collections.value.find(item => item.id === collectionId)
        if (!collection) continue
        previousCounts.set(collectionId, collection.count)
        collection.count = Math.max(0, collection.count - 1)
      }
      statusesByAnimeId.delete(animeId)

      try {
        await api.deleteListItem(existing.listItemId)
        if (mutationIsCurrent(operationKey, operationToken, animeId)) {
          toast.add({ title: '已取消收藏', color: 'warning' })
        }
      } catch (error: unknown) {
        if (mutationIsCurrent(operationKey, operationToken, animeId)) {
          statusesByAnimeId.set(animeId, previousStatus)
          previousCounts.forEach((count, collectionId) => {
            const collection = collections.value.find(item => item.id === collectionId)
            if (collection) collection.count = count
          })
          toast.add({ title: apiErrorMessage(error, '取消收藏失敗'), color: 'error' })
        }
      } finally {
        finishMutation(operationKey, operationToken, () => {
          pendingListOperations.delete(animeId)
        })
      }
      return
    }

    pendingInList.add(animeId)
    try {
      const result = await api.addToList(animeId)
      if (mutationIsCurrent(operationKey, operationToken, animeId)) {
        statusesByAnimeId.set(animeId, statusFromMutationResponse(animeId, result, {
          watched: false,
          collectionIds: []
        }))
        toast.add({ title: '已加入收藏', color: 'success' })
      }
    } catch (error: unknown) {
      if (mutationIsCurrent(operationKey, operationToken, animeId)) {
        toast.add({ title: apiErrorMessage(error, '加入收藏失敗'), color: 'error' })
      }
    } finally {
      finishMutation(operationKey, operationToken, () => {
        pendingInList.delete(animeId)
        pendingListOperations.delete(animeId)
      })
    }
  }

  async function markWatched(animeId: number) {
    if (!isAuthed.value) return navigateTo('/login')
    if (bootstrapLoading.value || bootstrapError.value || !scopedAnimeIds.has(animeId)) return
    if (pendingWatched.has(animeId) || pendingListOperations.has(animeId) || hasPendingCollectionOperation(animeId)) return

    const operationKey = `watched:${animeId}`
    const operationToken = beginMutation(operationKey)
    pendingWatched.add(animeId)
    const existing = statusesByAnimeId.get(animeId)
    const previousWatched = existing?.watched ?? false
    const nextWatched = existing ? !existing.watched : true
    let createdStatus: AnimeCardStatus | null = null

    if (existing) existing.watched = nextWatched
    else pendingInList.add(animeId)

    try {
      if (existing) {
        const result = await api.updateListItem(existing.listItemId, { watched: nextWatched })
        if (mutationIsCurrent(operationKey, operationToken, animeId)) {
          statusesByAnimeId.set(animeId, statusFromMutationResponse(animeId, result, {
            watched: nextWatched,
            collectionIds: existing.collectionIds
          }))
        }
      } else {
        const created = await api.addToList(animeId)
        if (!mutationIsCurrent(operationKey, operationToken, animeId)) return
        createdStatus = statusFromMutationResponse(animeId, created, {
          watched: false,
          collectionIds: []
        })
        if (mutationIsCurrent(operationKey, operationToken, animeId)) statusesByAnimeId.set(animeId, createdStatus)
        const result = await api.updateListItem(createdStatus.listItemId, { watched: true })
        if (mutationIsCurrent(operationKey, operationToken, animeId)) {
          statusesByAnimeId.set(animeId, statusFromMutationResponse(animeId, result, {
            watched: true,
            collectionIds: createdStatus.collectionIds
          }))
        }
      }

      if (mutationIsCurrent(operationKey, operationToken, animeId)) {
        toast.add({
          title: nextWatched ? '已標記為看完' : '已取消已看',
          color: nextWatched ? 'success' : 'warning'
        })
      }
    } catch (error: unknown) {
      if (mutationIsCurrent(operationKey, operationToken, animeId)) {
        if (existing) existing.watched = previousWatched
        else if (createdStatus) statusesByAnimeId.set(animeId, createdStatus)
        else statusesByAnimeId.delete(animeId)
        toast.add({ title: apiErrorMessage(error, '操作失敗，已恢復原狀態'), color: 'error' })
      }
    } finally {
      finishMutation(operationKey, operationToken, () => {
        pendingInList.delete(animeId)
        pendingWatched.delete(animeId)
      })
    }
  }

  async function toggleCollection(animeId: number, collection: Collection) {
    if (!isAuthed.value) return
    if (bootstrapLoading.value || bootstrapError.value || !scopedAnimeIds.has(animeId)) return
    if (
      pendingListOperations.has(animeId)
      || pendingWatched.has(animeId)
      || hasPendingCollectionOperation(animeId)
    ) return

    const status = statusesByAnimeId.get(animeId)
    if (!status) return

    const pendingKey = `${animeId}:${collection.id}`
    pendingCollections.add(pendingKey)
    const operationKey = `collection:${pendingKey}`
    const operationToken = beginMutation(operationKey)

    const inCollection = status.collectionIds.includes(collection.id)
    const previousCollectionIds = [...status.collectionIds]
    const targetCollection = collections.value.find(item => item.id === collection.id)
    const previousCount = targetCollection?.count ?? null

    status.collectionIds = inCollection
      ? status.collectionIds.filter(id => id !== collection.id)
      : [...status.collectionIds, collection.id]
    if (targetCollection) {
      targetCollection.count = inCollection
        ? Math.max(0, targetCollection.count - 1)
        : targetCollection.count + 1
    }

    try {
      if (inCollection) {
        await api.removeFromCollection(collection.id, status.listItemId)
      } else {
        await api.addToCollection(collection.id, status.listItemId)
      }
      if (mutationIsCurrent(operationKey, operationToken, animeId)) {
        toast.add({
          title: inCollection ? `已從「${collection.name}」移除` : `已加入「${collection.name}」`,
          color: inCollection ? 'warning' : 'success'
        })
      }
    } catch (error: unknown) {
      if (mutationIsCurrent(operationKey, operationToken, animeId)) {
        const currentStatus = statusesByAnimeId.get(animeId)
        if (currentStatus?.listItemId === status.listItemId) currentStatus.collectionIds = previousCollectionIds
        if (targetCollection && previousCount !== null) targetCollection.count = previousCount
        toast.add({ title: apiErrorMessage(error, '操作失敗'), color: 'error' })
      }
    } finally {
      finishMutation(operationKey, operationToken, () => {
        pendingCollections.delete(pendingKey)
      })
    }
  }

  watch(
    () => ({
      authenticated: isAuthed.value,
      animeIds: normalizeAnimeIds(toValue(animeIds))
    }),
    ({ authenticated, animeIds: nextAnimeIds }) => {
      if (!authenticated) {
        resetStatusState()
        return
      }
      void loadCardStatuses(nextAnimeIds)
    },
    { immediate: true }
  )

  return {
    statusesByAnimeId,
    collections,
    bootstrapLoading,
    bootstrapError,
    pendingInList,
    pendingWatched,
    loadCardStatuses,
    retryCardStatuses,
    isInList,
    isWatched,
    isStatusPending,
    toggleAnimeInList,
    markWatched,
    toggleCollection
  }
}
