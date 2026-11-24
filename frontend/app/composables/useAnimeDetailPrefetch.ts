import type { ApiPayload, ItemResponse } from '../types/api'
import { createPrefetchCache } from '../utils/prefetchCache'

// Matches the API's browser max-age, so a prefetched detail is never older
// than what the browser cache itself would serve.
const PREFETCH_TTL_MS = 60_000
const PREFETCH_MAX_ENTRIES = 30

type DetailCache = ReturnType<typeof createPrefetchCache<number, ItemResponse<ApiPayload>>>

// Module-level so the card that prefetches and the detail page that consumes
// share one cache. Only ever created in the browser; the server never sees it.
let detailCache: DetailCache | null = null

/**
 * Starts loading an anime's detail when the visitor presses or hovers its
 * card, so the detail page usually has its data by the time it opens.
 */
export function useAnimeDetailPrefetch() {
  const api = useApi()
  if (import.meta.client && !detailCache) {
    detailCache = createPrefetchCache(id => api.getAnime(id), {
      ttlMs: PREFETCH_TTL_MS,
      maxEntries: PREFETCH_MAX_ENTRIES
    })
  }

  return {
    prefetchAnimeDetail(id: number) {
      if (import.meta.client) detailCache?.prefetch(id)
    },
    takeAnimeDetail(id: number): Promise<ItemResponse<ApiPayload>> | undefined {
      return import.meta.client ? detailCache?.take(id) : undefined
    }
  }
}
