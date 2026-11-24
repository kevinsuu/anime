import type { AnimeCardStatus } from '../composables/useAnimeCardStatuses'
import type { Collection } from './normalize'

// The last known favorite/watched state per user, so cards can show it the
// moment the page renders; the server bootstrap then corrects anything stale.
const KEY_PREFIX = 'animeCardStatuses:v1:'
const MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000

export interface CardStatusSnapshot {
  statuses: Map<number, AnimeCardStatus>
  collections: Collection[]
}

function keyFor(userId: number): string {
  return `${KEY_PREFIX}${userId}`
}

function isPositiveInteger(value: unknown): value is number {
  return Number.isInteger(value) && (value as number) > 0
}

function toStatus(value: unknown): AnimeCardStatus | null {
  if (value === null || typeof value !== 'object') return null
  const item = value as Record<string, unknown>
  if (!isPositiveInteger(item.animeId) || !isPositiveInteger(item.listItemId)) return null
  return {
    animeId: item.animeId,
    listItemId: item.listItemId,
    watched: item.watched === true,
    collectionIds: Array.isArray(item.collectionIds) ? item.collectionIds.filter(isPositiveInteger) : []
  }
}

function toCollection(value: unknown): Collection | null {
  if (value === null || typeof value !== 'object') return null
  const item = value as Record<string, unknown>
  if (!isPositiveInteger(item.id) || typeof item.name !== 'string') return null
  return {
    id: item.id,
    name: item.name,
    isPublic: item.isPublic === true,
    publicSlug: typeof item.publicSlug === 'string' ? item.publicSlug : '',
    count: typeof item.count === 'number' && item.count >= 0 ? item.count : 0
  }
}

export function readCardStatusSnapshot(userId: number): CardStatusSnapshot | null {
  try {
    const raw = localStorage.getItem(keyFor(userId))
    if (!raw) return null
    const parsed: unknown = JSON.parse(raw)
    if (parsed === null || typeof parsed !== 'object') return null
    const record = parsed as Record<string, unknown>
    if (typeof record.savedAt !== 'number' || Date.now() - record.savedAt > MAX_AGE_MS) return null

    const statuses = new Map<number, AnimeCardStatus>()
    for (const entry of Array.isArray(record.statuses) ? record.statuses : []) {
      const status = toStatus(entry)
      if (status) statuses.set(status.animeId, status)
    }
    const collections = (Array.isArray(record.collections) ? record.collections : [])
      .map(toCollection)
      .filter((collection): collection is Collection => collection !== null)
    return { statuses, collections }
  } catch {
    return null
  }
}

export function writeCardStatusSnapshot(
  userId: number,
  statuses: Iterable<AnimeCardStatus>,
  collections: readonly Collection[]
): void {
  try {
    localStorage.setItem(keyFor(userId), JSON.stringify({
      savedAt: Date.now(),
      statuses: [...statuses],
      collections
    }))
  } catch {
    // Storage full or blocked (private mode): the snapshot is only a speed-up.
  }
}

export function clearCardStatusSnapshots(): void {
  try {
    const keys: string[] = []
    for (let index = 0; index < localStorage.length; index++) {
      const key = localStorage.key(index)
      if (key?.startsWith(KEY_PREFIX)) keys.push(key)
    }
    keys.forEach(key => localStorage.removeItem(key))
  } catch {
    // Nothing to clear when storage is unavailable.
  }
}
