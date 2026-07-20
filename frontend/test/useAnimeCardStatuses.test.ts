import { nextTick, ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  normalizeAnimeCardStatus,
  normalizeAnimeIds,
  useAnimeCardStatuses
} from '../app/composables/useAnimeCardStatuses'

function deferred<T = void>() {
  let resolve!: (value: T | PromiseLike<T>) => void
  let reject!: (reason?: unknown) => void
  const promise = new Promise<T>((done, fail) => {
    resolve = done
    reject = fail
  })
  return { promise, resolve, reject }
}

describe('useAnimeCardStatuses', () => {
  let isAuthed: ReturnType<typeof ref<boolean>>
  let api: Record<string, ReturnType<typeof vi.fn>>
  let toastAdd: ReturnType<typeof vi.fn>

  beforeEach(() => {
    isAuthed = ref(true)
    toastAdd = vi.fn()
    api = {
      meBootstrap: vi.fn().mockResolvedValue({ user: { id: 1 }, statuses: [], collections: [] }),
      addToList: vi.fn(),
      deleteListItem: vi.fn(),
      updateListItem: vi.fn(),
      addToCollection: vi.fn(),
      removeFromCollection: vi.fn()
    }

    vi.stubGlobal('useApi', () => api)
    vi.stubGlobal('useSession', () => ({ isAuthed }))
    vi.stubGlobal('useToast', () => ({ add: toastAdd }))
    vi.stubGlobal('navigateTo', vi.fn())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('normalizes and deduplicates the bootstrap status contract', () => {
    expect(normalizeAnimeIds([9, 2, 9, 0, Number.NaN])).toEqual([2, 9])
    expect(normalizeAnimeCardStatus({
      anime_id: 9,
      list_item_id: 91,
      watched: 1,
      collection_ids: [4, 2, 4]
    })).toEqual({
      animeId: 9,
      listItemId: 91,
      watched: true,
      collectionIds: [2, 4]
    })
  })

  it('loads one bootstrap for the current card ids and reuses the same canonical id set', async () => {
    api.meBootstrap.mockResolvedValue({
      user: { id: 1 },
      statuses: [{ anime_id: 9, list_item_id: 91, watched: true, collection_ids: [4] }],
      collections: [{ id: 4, name: '喜歡', is_public: false, public_slug: 'likes', count: 3 }]
    })
    const animeIds = ref([9, 2, 9])
    const state = useAnimeCardStatuses(animeIds)

    await vi.waitFor(() => expect(api.meBootstrap).toHaveBeenCalledOnce())
    await vi.waitFor(() => expect(state.statusesByAnimeId.get(9)?.listItemId).toBe(91))
    expect(api.meBootstrap).toHaveBeenCalledWith([2, 9])
    expect(state.collections.value).toEqual([{
      id: 4,
      name: '喜歡',
      isPublic: false,
      publicSlug: 'likes',
      count: 3
    }])

    animeIds.value = [2, 9]
    await nextTick()
    await Promise.resolve()
    expect(api.meBootstrap).toHaveBeenCalledOnce()
  })

  it('cancels an intermediate bootstrap when returning to the already loaded scope', async () => {
    const intermediate = deferred<any>()
    api.meBootstrap
      .mockResolvedValueOnce({
        user: { id: 1 },
        statuses: [{ anime_id: 1, list_item_id: 11, watched: false, collection_ids: [] }],
        collections: []
      })
      .mockReturnValueOnce(intermediate.promise)
    const animeIds = ref([1])
    const state = useAnimeCardStatuses(animeIds)
    await vi.waitFor(() => expect(state.statusesByAnimeId.get(1)?.listItemId).toBe(11))

    animeIds.value = [2]
    await vi.waitFor(() => expect(state.bootstrapLoading.value).toBe(true))
    expect(api.meBootstrap).toHaveBeenCalledTimes(2)

    animeIds.value = [1]
    await vi.waitFor(() => expect(state.bootstrapLoading.value).toBe(false))
    expect(api.meBootstrap).toHaveBeenCalledTimes(2)
    expect(state.statusesByAnimeId.get(1)?.listItemId).toBe(11)

    intermediate.reject(new Error('過期 bootstrap 失敗'))
    await expect(intermediate.promise).rejects.toThrow('過期 bootstrap 失敗')
    await nextTick()

    expect(state.bootstrapLoading.value).toBe(false)
    expect(toastAdd).not.toHaveBeenCalled()
  })

  it('does not call bootstrap until the viewer is authenticated', async () => {
    isAuthed.value = false
    const state = useAnimeCardStatuses(ref([1, 2]))
    await nextTick()

    expect(api.meBootstrap).not.toHaveBeenCalled()
    expect(state.statusesByAnimeId.size).toBe(0)

    isAuthed.value = true
    await vi.waitFor(() => expect(api.meBootstrap).toHaveBeenCalledWith([1, 2]))
  })

  it('rejects card mutations while the bootstrap request is still loading', async () => {
    const bootstrap = deferred<any>()
    api.meBootstrap.mockReturnValue(bootstrap.promise)
    const state = useAnimeCardStatuses(ref([1]))
    await vi.waitFor(() => expect(state.bootstrapLoading.value).toBe(true))

    await state.toggleAnimeInList(1)
    await state.markWatched(1)
    await state.toggleCollection(1, {
      id: 5,
      name: '稍後看',
      isPublic: false,
      publicSlug: 'later',
      count: 0
    })

    expect(api.addToList).not.toHaveBeenCalled()
    expect(api.updateListItem).not.toHaveBeenCalled()
    expect(api.addToCollection).not.toHaveBeenCalled()
    expect(api.removeFromCollection).not.toHaveBeenCalled()

    bootstrap.resolve({ user: { id: 1 }, statuses: [], collections: [] })
    await vi.waitFor(() => expect(state.bootstrapLoading.value).toBe(false))
  })

  it('does not issue the second watched request or restore state after logout', async () => {
    const created = deferred<any>()
    api.addToList.mockReturnValue(created.promise)
    const state = useAnimeCardStatuses(ref([1]))
    await vi.waitFor(() => expect(state.bootstrapLoading.value).toBe(false))

    const mutation = state.markWatched(1)
    await vi.waitFor(() => expect(api.addToList).toHaveBeenCalledWith(1))
    expect(state.pendingInList.has(1)).toBe(true)

    isAuthed.value = false
    await nextTick()
    expect(state.statusesByAnimeId.size).toBe(0)
    expect(state.pendingInList.size).toBe(0)
    expect(state.pendingWatched.size).toBe(0)

    created.resolve({ item: { id: 11, watched: false, collections: [] } })
    await mutation

    expect(api.updateListItem).not.toHaveBeenCalled()
    expect(state.statusesByAnimeId.size).toBe(0)
    expect(toastAdd).not.toHaveBeenCalled()
  })

  it('ignores an old mutation after the card id scope changes without clearing the new lock', async () => {
    const oldMutationResponse = deferred<any>()
    const newMutationResponse = deferred<any>()
    api.addToList
      .mockReturnValueOnce(oldMutationResponse.promise)
      .mockReturnValueOnce(newMutationResponse.promise)
    const animeIds = ref([1])
    const state = useAnimeCardStatuses(animeIds)
    await vi.waitFor(() => expect(state.bootstrapLoading.value).toBe(false))

    const oldMutation = state.toggleAnimeInList(1)
    await vi.waitFor(() => expect(api.addToList).toHaveBeenCalledTimes(1))

    animeIds.value = [1, 2]
    await vi.waitFor(() => expect(api.meBootstrap).toHaveBeenCalledTimes(2))
    await vi.waitFor(() => expect(state.bootstrapLoading.value).toBe(false))

    const newMutation = state.toggleAnimeInList(1)
    await vi.waitFor(() => expect(api.addToList).toHaveBeenCalledTimes(2))
    expect(state.pendingInList.has(1)).toBe(true)

    oldMutationResponse.resolve({ item: { id: 11, watched: false, collections: [] } })
    await oldMutation
    expect(state.pendingInList.has(1)).toBe(true)
    expect(state.statusesByAnimeId.has(1)).toBe(false)

    newMutationResponse.resolve({ item: { id: 22, watched: false, collections: [] } })
    await newMutation
    expect(state.pendingInList.has(1)).toBe(false)
    expect(state.statusesByAnimeId.get(1)?.listItemId).toBe(22)
  })

  it('rolls a failed optimistic removal and collection counts back', async () => {
    api.meBootstrap.mockResolvedValue({
      user: { id: 1 },
      statuses: [{ anime_id: 1, list_item_id: 11, watched: false, collection_ids: [5] }],
      collections: [{ id: 5, name: '稍後看', is_public: false, public_slug: 'later', count: 2 }]
    })
    api.deleteListItem.mockRejectedValue(new Error('刪除失敗'))
    const state = useAnimeCardStatuses(ref([1]))
    await vi.waitFor(() => expect(state.statusesByAnimeId.has(1)).toBe(true))

    await state.toggleAnimeInList(1)

    expect(state.statusesByAnimeId.get(1)).toMatchObject({ listItemId: 11, collectionIds: [5] })
    expect(state.collections.value[0]?.count).toBe(2)
    expect(toastAdd).toHaveBeenCalledWith(expect.objectContaining({ color: 'error' }))
  })

  it('creates a lightweight status when marking an unlisted anime as watched', async () => {
    api.addToList.mockResolvedValue({ item: { id: 22, watched: false, collections: [] } })
    api.updateListItem.mockResolvedValue({ item: { id: 22, watched: true, collections: [] } })
    const state = useAnimeCardStatuses(ref([2]))
    await vi.waitFor(() => expect(api.meBootstrap).toHaveBeenCalledOnce())

    await state.markWatched(2)

    expect(api.addToList).toHaveBeenCalledWith(2)
    expect(api.updateListItem).toHaveBeenCalledWith(22, { watched: true })
    expect(state.statusesByAnimeId.get(2)).toEqual({
      animeId: 2,
      listItemId: 22,
      watched: true,
      collectionIds: []
    })
    expect(state.isInList(2)).toBe(true)
    expect(state.isWatched(2)).toBe(true)
  })

  it('locks duplicate collection mutations while keeping the optimistic state', async () => {
    api.meBootstrap.mockResolvedValue({
      user: { id: 1 },
      statuses: [{ anime_id: 1, list_item_id: 11, watched: false, collection_ids: [] }],
      collections: [{ id: 5, name: '稍後看', is_public: false, public_slug: 'later', count: 1 }]
    })
    const pending = deferred()
    api.addToCollection.mockReturnValue(pending.promise)
    const state = useAnimeCardStatuses(ref([1]))
    await vi.waitFor(() => expect(state.statusesByAnimeId.has(1)).toBe(true))
    const collection = state.collections.value[0]!

    const first = state.toggleCollection(1, collection)
    const duplicate = state.toggleCollection(1, collection)

    expect(state.statusesByAnimeId.get(1)?.collectionIds).toEqual([5])
    expect(collection.count).toBe(2)
    expect(api.addToCollection).toHaveBeenCalledOnce()

    pending.resolve()
    await Promise.all([first, duplicate])
  })

  it('serializes different collection mutations for the same anime so rollback stays isolated', async () => {
    api.meBootstrap.mockResolvedValue({
      user: { id: 1 },
      statuses: [{ anime_id: 1, list_item_id: 11, watched: false, collection_ids: [] }],
      collections: [
        { id: 5, name: '稍後看', is_public: false, public_slug: 'later', count: 1 },
        { id: 6, name: '最愛', is_public: false, public_slug: 'favorite', count: 3 }
      ]
    })
    const firstRequest = deferred()
    api.addToCollection
      .mockReturnValueOnce(firstRequest.promise)
      .mockResolvedValueOnce(undefined)
    const state = useAnimeCardStatuses(ref([1]))
    await vi.waitFor(() => expect(state.collections.value).toHaveLength(2))
    const firstCollection = state.collections.value[0]!
    const secondCollection = state.collections.value[1]!

    const first = state.toggleCollection(1, firstCollection)
    const blockedSecond = state.toggleCollection(1, secondCollection)

    expect(api.addToCollection).toHaveBeenCalledOnce()
    expect(api.addToCollection).toHaveBeenCalledWith(5, 11)
    expect(state.statusesByAnimeId.get(1)?.collectionIds).toEqual([5])
    expect(firstCollection.count).toBe(2)
    expect(secondCollection.count).toBe(3)

    firstRequest.reject(new Error('第一個清單更新失敗'))
    await Promise.all([first, blockedSecond])

    expect(state.statusesByAnimeId.get(1)?.collectionIds).toEqual([])
    expect(firstCollection.count).toBe(1)
    expect(secondCollection.count).toBe(3)

    await state.toggleCollection(1, secondCollection)
    expect(api.addToCollection).toHaveBeenCalledTimes(2)
    expect(api.addToCollection).toHaveBeenLastCalledWith(6, 11)
    expect(state.statusesByAnimeId.get(1)?.collectionIds).toEqual([6])
    expect(secondCollection.count).toBe(4)
  })
})
