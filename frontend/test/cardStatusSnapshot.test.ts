import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  clearCardStatusSnapshots,
  readCardStatusSnapshot,
  writeCardStatusSnapshot
} from '../app/utils/cardStatusSnapshot'

function memoryStorage(): Storage {
  const data = new Map<string, string>()
  return {
    get length() { return data.size },
    clear: () => data.clear(),
    getItem: key => data.get(key) ?? null,
    key: index => [...data.keys()][index] ?? null,
    removeItem: key => { data.delete(key) },
    setItem: (key, value) => { data.set(key, String(value)) }
  }
}

const status = (animeId: number, watched = false) => ({ animeId, listItemId: animeId * 10, watched, collectionIds: [] })

describe('card status snapshot', () => {
  beforeEach(() => {
    vi.stubGlobal('localStorage', memoryStorage())
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-11-24T00:00:00Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('round-trips statuses and collections per user', () => {
    writeCardStatusSnapshot(7, [status(1, true), status(2)], [{ id: 3, name: '本季必追', isPublic: false, publicSlug: '', count: 1 }])

    const snapshot = readCardStatusSnapshot(7)
    expect(snapshot?.statuses.get(1)).toEqual(status(1, true))
    expect(snapshot?.statuses.get(2)).toEqual(status(2))
    expect(snapshot?.collections).toHaveLength(1)
    expect(readCardStatusSnapshot(8)).toBeNull()
  })

  it('ignores snapshots older than 30 days', () => {
    writeCardStatusSnapshot(7, [status(1)], [])
    vi.setSystemTime(new Date('2026-12-25T00:00:01Z'))

    expect(readCardStatusSnapshot(7)).toBeNull()
  })

  it('ignores malformed data instead of throwing', () => {
    localStorage.setItem('animeCardStatuses:v1:7', '{not json')
    expect(readCardStatusSnapshot(7)).toBeNull()

    localStorage.setItem('animeCardStatuses:v1:7', JSON.stringify({ savedAt: Date.now(), statuses: [{ animeId: 'x' }], collections: 'no' }))
    expect(readCardStatusSnapshot(7)?.statuses.size).toBe(0)
  })

  it('clears every user snapshot on logout but keeps other keys', () => {
    writeCardStatusSnapshot(7, [status(1)], [])
    writeCardStatusSnapshot(8, [status(2)], [])
    localStorage.setItem('other', 'keep')

    clearCardStatusSnapshots()

    expect(readCardStatusSnapshot(7)).toBeNull()
    expect(readCardStatusSnapshot(8)).toBeNull()
    expect(localStorage.getItem('other')).toBe('keep')
  })

  it('never throws when storage is unavailable', () => {
    vi.stubGlobal('localStorage', {
      getItem: () => { throw new Error('denied') },
      setItem: () => { throw new Error('denied') },
      removeItem: () => { throw new Error('denied') },
      key: () => null,
      length: 0
    })

    expect(() => writeCardStatusSnapshot(7, [status(1)], [])).not.toThrow()
    expect(readCardStatusSnapshot(7)).toBeNull()
    expect(() => clearCardStatusSnapshots()).not.toThrow()
  })
})
