export interface PrefetchCacheOptions {
  /** How long a prefetched result may be used, so a stale detail is never shown. */
  ttlMs: number
  /** Upper bound on remembered entries (oldest are evicted first). */
  maxEntries: number
}

interface Entry<T> {
  promise: Promise<T>
  createdAt: number
}

/**
 * Remembers short-lived prefetch requests keyed by id. A page can `take()`
 * the in-flight or finished promise instead of starting the same request
 * again, so data that began loading on press/hover is ready sooner.
 */
export function createPrefetchCache<K, T>(fetcher: (key: K) => Promise<T>, options: PrefetchCacheOptions) {
  const entries = new Map<K, Entry<T>>()

  function fresh(key: K): Entry<T> | undefined {
    const entry = entries.get(key)
    if (!entry) return undefined
    if (Date.now() - entry.createdAt > options.ttlMs) {
      entries.delete(key)
      return undefined
    }
    return entry
  }

  function prefetch(key: K): void {
    if (fresh(key)) return

    const promise = fetcher(key)
    const entry: Entry<T> = { promise, createdAt: Date.now() }
    entries.set(key, entry)
    // A failed prefetch is forgotten so the page makes its own request and
    // shows its normal error handling.
    promise.catch(() => {
      if (entries.get(key) === entry) entries.delete(key)
    })

    while (entries.size > options.maxEntries) {
      const oldest = entries.keys().next().value as K
      entries.delete(oldest)
    }
  }

  function take(key: K): Promise<T> | undefined {
    return fresh(key)?.promise
  }

  return { prefetch, take }
}
