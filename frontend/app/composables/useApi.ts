interface RequestOptions {
  method?: string
  body?: string
  headers?: Record<string, string>
}

interface AnimeSummaryFilters {
  year?: number | string
  season?: string
  tags?: string[]
  page?: number
  perPage?: number
}

export interface AnimeCardBootstrapStatusPayload {
  anime_id: number
  list_item_id: number
  watched: boolean
  collection_ids: number[]
}

export interface AnimeCardBootstrapCollectionPayload {
  id: number
  name: string
  is_public: boolean
  public_slug: string
  count: number
}

export interface AnimeCardBootstrapResponse {
  user: Record<string, unknown>
  statuses: AnimeCardBootstrapStatusPayload[]
  collections: AnimeCardBootstrapCollectionPayload[]
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
        const body = await response.json().catch(() => ({}))
        if (!response.ok) {
          clearSession()
          throw new Error(body.message || '登入已失效，請重新登入')
        }

        updateTokens(body.token, body.refreshToken)
        return body.token as string
      })().finally(() => {
        refreshPromise = null
      })
    }
    return refreshPromise
  }

  async function request<T = any>(path: string, options: RequestOptions = {}, isRetry = false): Promise<T> {
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

    const body = await response.json().catch(() => ({}))
    if (!response.ok) {
      const error: any = new Error(body.message || '請求失敗')
      error.status = response.status
      error.body = body
      throw error
    }

    return body as T
  }

  return {
    login: (idToken: string) => request('/auth/google', { method: 'POST', body: JSON.stringify({ idToken }) }),
    logout: () => request('/auth/logout', { method: 'POST' }),
    me: () => request('/me'),
    meBootstrap: (animeIds: number[]) => request<AnimeCardBootstrapResponse>(
      `/me/bootstrap?anime_ids=${animeIds.join(',')}`
    ),
    searchAnime: (query: string, filters: { year?: number | string; season?: string; tags?: string[] } = {}) => {
      const params = new URLSearchParams()
      if (query) params.set('q', query)
      if (filters.year) params.set('year', String(filters.year))
      if (filters.season) params.set('season', filters.season)
      if (filters.tags?.length) params.set('tags', filters.tags.join(','))
      const queryString = params.toString()
      return request(`/anime${queryString ? `?${queryString}` : ''}`)
    },
    searchAnimeSummaries: (query: string, filters: AnimeSummaryFilters = {}) => {
      const params = new URLSearchParams()
      if (query) params.set('q', query)
      if (filters.year) params.set('year', String(filters.year))
      if (filters.season) params.set('season', filters.season)
      if (filters.tags?.length) params.set('tags', filters.tags.join(','))
      if (filters.page) params.set('page', String(filters.page))
      if (filters.perPage) params.set('per_page', String(filters.perPage))
      const queryString = params.toString()
      return request(`/anime/summaries${queryString ? `?${queryString}` : ''}`)
    },
    catalogTags: () => request('/anime/tags'),
    getAnime: (id: number) => request(`/anime/${id}`),
    myList: (params?: { tags?: string[] }) => {
      const qs = params?.tags?.length ? `?tags=${encodeURIComponent(params.tags.join(','))}` : ''
      return request(`/my/anime-list${qs}`)
    },
    myListTags: () => request('/my/anime-list/tags'),
    addToList: (animeId: number) => request('/my/anime-list', { method: 'POST', body: JSON.stringify({ animeId }) }),
    updateListItem: (id: number, payload: Record<string, any>) => request(`/my/anime-list/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
    deleteListItem: (id: number) => request(`/my/anime-list/${id}`, { method: 'DELETE' }),
    publicList: (slug: string) => request(`/public/lists/${encodeURIComponent(slug)}`),
    regenerateSlug: () => request('/me/share-slug/regenerate', { method: 'POST' }),
    // Collections
    myCollections: () => request('/my/collections'),
    createCollection: (name: string, isPublic = false) => request('/my/collections', { method: 'POST', body: JSON.stringify({ name, is_public: isPublic }) }),
    updateCollection: (id: number, patch: { name?: string; is_public?: boolean }) => request(`/my/collections/${id}`, { method: 'PATCH', body: JSON.stringify(patch) }),
    deleteCollection: (id: number) => request(`/my/collections/${id}`, { method: 'DELETE' }),
    addToCollection: (collectionId: number, listItemId: number) => request(`/my/collections/${collectionId}/items`, { method: 'POST', body: JSON.stringify({ list_item_id: listItemId }) }),
    removeFromCollection: (collectionId: number, listItemId: number) => request(`/my/collections/${collectionId}/items/${listItemId}`, { method: 'DELETE' }),
    publicCollection: (slug: string) => request(`/public/collections/${encodeURIComponent(slug)}`)
  }
}
