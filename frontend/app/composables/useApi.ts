import type {
  AnimeCardBootstrapResponse,
  AnimeListCountsResponse,
  AnimeListFilters,
  AnimeListResponse,
  AnimeSummaryFilters,
  AnimeSummaryResponse,
  ApiPayload,
  AuthResponse,
  CollectionPatch,
  CollectionPayload,
  ItemResponse,
  ItemsResponse,
  ListItemPatch,
  OkResponse,
  PublicCollectionPayload,
  PublicListResponse,
  TagsResponse,
  UserResponse
} from '../types/api'
import { ApiError } from '../utils/apiError'

interface RequestOptions {
  method?: string
  body?: string
  headers?: Record<string, string>
}

export type {
  AnimeCardBootstrapResponse,
  AnimeCardBootstrapStatusPayload,
  AnimeSummaryFilters,
  ListItemPatch
} from '../types/api'

function asRecord(value: unknown): Record<string, unknown> | null {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    ? value as Record<string, unknown>
    : null
}

function responseMessage(value: unknown, fallback: string): string {
  const message = asRecord(value)?.message
  return typeof message === 'string' && message ? message : fallback
}

// Serializes concurrent 401s onto a single in-flight refresh call so a page
// that fires several requests at once doesn't burn through refresh-token
// rotations (each rotation invalidates the previous token).
let refreshPromise: Promise<string> | null = null

export function useApi() {
  const config = useRuntimeConfig()
  const apiBaseUrl = (import.meta.server ? config.apiBaseUrlInternal : config.public.apiBaseUrl) as string
  const { session, updateTokens, clearSession } = useSession()

  async function refreshAccessToken(): Promise<string> {
    if (!refreshPromise) {
      refreshPromise = (async () => {
        const refreshToken = session.refreshToken
        if (!refreshToken) throw new Error('尚未登入')

        const response = await fetch(`${apiBaseUrl}/auth/refresh`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ refreshToken })
        })
        const body: unknown = await response.json().catch(() => ({}))
        if (!response.ok) {
          clearSession()
          throw new ApiError(responseMessage(body, '登入已失效，請重新登入'), response.status, body)
        }

        const payload = asRecord(body)
        const token = payload?.token
        const nextRefreshToken = payload?.refreshToken
        if (typeof token !== 'string' || typeof nextRefreshToken !== 'string') {
          clearSession()
          throw new Error('更新登入憑證時收到無效回應')
        }

        updateTokens(token, nextRefreshToken)
        return token
      })().finally(() => {
        refreshPromise = null
      })
    }
    return refreshPromise
  }

  async function request<T>(path: string, options: RequestOptions = {}, isRetry = false): Promise<T> {
    const headers: Record<string, string> = { 'Content-Type': 'application/json', ...(options.headers || {}) }
    const token = session.token
    if (token) headers.Authorization = `Bearer ${token}`

    let response: Response
    try {
      response = await fetch(`${apiBaseUrl}${path}`, { ...options, headers })
    } catch {
      throw new Error('無法連線到後端 API，請確認伺服器是否啟動。')
    }

    // Access token expired mid-session: silently refresh and retry once,
    // so the user isn't bounced back to the login screen every hour.
    if (response.status === 401 && !isRetry && path !== '/auth/refresh' && session.refreshToken) {
      try {
        await refreshAccessToken()
        return await request<T>(path, options, true)
      } catch {
        // fall through to normal error handling below using the original response
      }
    }

    const body: unknown = await response.json().catch(() => ({}))
    if (!response.ok) {
      throw new ApiError(responseMessage(body, '請求失敗'), response.status, body)
    }

    return body as T
  }

  return {
    login: (idToken: string) => request<AuthResponse>('/auth/google', { method: 'POST', body: JSON.stringify({ idToken }) }),
    logout: () => request<OkResponse>('/auth/logout', { method: 'POST' }),
    me: () => request<UserResponse>('/me'),
    meBootstrap: (animeIds: number[]) => request<AnimeCardBootstrapResponse>(
      `/me/bootstrap?anime_ids=${animeIds.join(',')}`
    ),
    searchAnimeSummaries: (query: string, filters: AnimeSummaryFilters = {}) => {
      const params = new URLSearchParams()
      if (query) params.set('q', query)
      if (filters.year) params.set('year', String(filters.year))
      if (filters.season) params.set('season', filters.season)
      if (filters.tags?.length) params.set('tags', filters.tags.join(','))
      if (filters.page) params.set('page', String(filters.page))
      if (filters.perPage) params.set('per_page', String(filters.perPage))
      const queryString = params.toString()
      return request<AnimeSummaryResponse>(`/anime/summaries${queryString ? `?${queryString}` : ''}`)
    },
    catalogTags: () => request<TagsResponse>('/anime/tags'),
    getAnime: (id: number) => request<ItemResponse<ApiPayload>>(`/anime/${id}`),
    myList: (filters: AnimeListFilters = {}) => {
      const params = new URLSearchParams()
      if (filters.page) params.set('page', String(filters.page))
      if (filters.q) params.set('q', filters.q)
      if (filters.tags?.length) params.set('tags', filters.tags.join(','))
      if (filters.status && filters.status !== 'all') params.set('status', filters.status)
      if (filters.collectionId) params.set('collection_id', String(filters.collectionId))
      if (filters.sort) params.set('sort', filters.sort)
      const queryString = params.toString()
      return request<AnimeListResponse>(`/my/anime-list${queryString ? `?${queryString}` : ''}`)
    },
    myListCounts: () => request<AnimeListCountsResponse>('/my/anime-list/counts'),
    myListTags: () => request<TagsResponse>('/my/anime-list/tags'),
    addToList: (animeId: number) => request<ItemResponse<ApiPayload>>('/my/anime-list', { method: 'POST', body: JSON.stringify({ animeId }) }),
    updateListItem: (id: number, payload: ListItemPatch) => request<ItemResponse<ApiPayload>>(`/my/anime-list/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
    deleteListItem: (id: number) => request<OkResponse>(`/my/anime-list/${id}`, { method: 'DELETE' }),
    publicList: (slug: string) => request<PublicListResponse>(`/public/lists/${encodeURIComponent(slug)}`),
    regenerateSlug: () => request<UserResponse>('/me/share-slug/regenerate', { method: 'POST' }),
    // Collections
    myCollections: () => request<ItemsResponse<CollectionPayload>>('/my/collections'),
    createCollection: (name: string, isPublic = false) => request<ItemResponse<CollectionPayload>>('/my/collections', { method: 'POST', body: JSON.stringify({ name, is_public: isPublic }) }),
    updateCollection: (id: number, patch: CollectionPatch) => request<ItemResponse<CollectionPayload>>(`/my/collections/${id}`, { method: 'PATCH', body: JSON.stringify(patch) }),
    deleteCollection: (id: number) => request<OkResponse>(`/my/collections/${id}`, { method: 'DELETE' }),
    addToCollection: (collectionId: number, listItemId: number) => request<ItemResponse<CollectionPayload>>(`/my/collections/${collectionId}/items`, { method: 'POST', body: JSON.stringify({ list_item_id: listItemId }) }),
    removeFromCollection: (collectionId: number, listItemId: number) => request<ItemResponse<CollectionPayload>>(`/my/collections/${collectionId}/items/${listItemId}`, { method: 'DELETE' }),
    publicCollection: (slug: string) => request<ItemResponse<PublicCollectionPayload>>(`/public/collections/${encodeURIComponent(slug)}`)
  }
}
