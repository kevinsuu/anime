interface RequestOptions {
  method?: string
  body?: string
  headers?: Record<string, string>
}

export function useApi() {
  const config = useRuntimeConfig()
  const apiBaseUrl = config.public.apiBaseUrl as string

  function getToken(): string {
    if (typeof window === 'undefined') return ''
    try {
      const stored = JSON.parse(localStorage.getItem('animeTrackerSession') || '{}')
      return stored.token || ''
    } catch {
      return ''
    }
  }

  async function request(path: string, options: RequestOptions = {}): Promise<any> {
    const headers: Record<string, string> = { 'Content-Type': 'application/json', ...(options.headers || {}) }
    const token = getToken()
    if (token) headers.Authorization = `Bearer ${token}`

    let response: Response
    try {
      response = await fetch(`${apiBaseUrl}${path}`, { ...options, headers })
    } catch {
      throw new Error('無法連線到後端 API，請確認 backend container 是否啟動。')
    }

    const body = await response.json().catch(() => ({}))
    if (!response.ok) {
      const error: any = new Error(body.message || '請求失敗')
      error.status = response.status
      error.body = body
      throw error
    }

    return body
  }

  return {
    apiBaseUrl,
    login: (idToken: string) => request('/auth/google', { method: 'POST', body: JSON.stringify({ idToken }) }),
    me: () => request('/me'),
    searchAnime: (query: string, filters: { year?: number | string; season?: string } = {}) => {
      const params = new URLSearchParams()
      if (query) params.set('q', query)
      if (filters.year) params.set('year', String(filters.year))
      if (filters.season) params.set('season', filters.season)
      const queryString = params.toString()
      return request(`/anime${queryString ? `?${queryString}` : ''}`)
    },
    createAnime: (payload: Record<string, any>) => request('/anime', { method: 'POST', body: JSON.stringify(payload) }),
    syncSeasonalAnime: (payload: Record<string, any>) => request('/anime/sync-seasonal', { method: 'POST', body: JSON.stringify(payload) }),
    myList: () => request('/my/anime-list'),
    addToList: (animeId: number) => request('/my/anime-list', { method: 'POST', body: JSON.stringify({ animeId }) }),
    updateListItem: (id: number, payload: Record<string, any>) => request(`/my/anime-list/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
    deleteListItem: (id: number) => request(`/my/anime-list/${id}`, { method: 'DELETE' }),
    publicList: (slug: string) => request(`/public/lists/${encodeURIComponent(slug)}`),
    regenerateSlug: () => request('/me/share-slug/regenerate', { method: 'POST' })
  }
}
