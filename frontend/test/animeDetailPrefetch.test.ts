import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPrefetchCache } from '../app/utils/prefetchCache'

describe('detail prefetch cache', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-11-24T00:00:00Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('reuses an in-flight prefetch instead of requesting again', async () => {
    const fetcher = vi.fn(async (id: number) => ({ id }))
    const cache = createPrefetchCache(fetcher, { ttlMs: 60_000, maxEntries: 10 })

    cache.prefetch(1)
    cache.prefetch(1)
    const result = await cache.take(1)

    expect(fetcher).toHaveBeenCalledOnce()
    expect(result).toEqual({ id: 1 })
  })

  it('returns nothing when the id was not prefetched', () => {
    const cache = createPrefetchCache(async (id: number) => id, { ttlMs: 60_000, maxEntries: 10 })

    expect(cache.take(2)).toBeUndefined()
  })

  it('expires old prefetches so a stale detail is never shown', () => {
    const fetcher = vi.fn(async (id: number) => id)
    const cache = createPrefetchCache(fetcher, { ttlMs: 60_000, maxEntries: 10 })

    cache.prefetch(1)
    vi.advanceTimersByTime(60_001)

    expect(cache.take(1)).toBeUndefined()
    cache.prefetch(1)
    expect(fetcher).toHaveBeenCalledTimes(2)
  })

  it('drops a failed prefetch so the page falls back to a fresh request', async () => {
    const fetcher = vi.fn(async () => { throw new Error('offline') })
    const cache = createPrefetchCache(fetcher, { ttlMs: 60_000, maxEntries: 10 })

    cache.prefetch(1)
    await vi.runAllTimersAsync()

    expect(cache.take(1)).toBeUndefined()
  })

  it('keeps at most maxEntries, evicting the oldest', () => {
    const fetcher = vi.fn(async (id: number) => id)
    const cache = createPrefetchCache(fetcher, { ttlMs: 60_000, maxEntries: 2 })

    cache.prefetch(1)
    cache.prefetch(2)
    cache.prefetch(3)

    expect(cache.take(1)).toBeUndefined()
    expect(cache.take(3)).toBeDefined()
  })
})
