import type { ListItem } from './normalize'

export interface TagOption {
  tag: string
  count: number
}

// Composes with the tag filter (now server-side, see useApi.ts myList()) —
// this only applies the status filter (all/watched/unwatched/col:{id}).
export function applyListFilters(list: ListItem[], statusFilter: string): ListItem[] {
  if (statusFilter === 'watched') return list.filter(i => i.watched)
  if (statusFilter === 'unwatched') return list.filter(i => !i.watched)
  if (statusFilter.startsWith('col:')) {
    const colId = Number(statusFilter.slice(4))
    return list.filter(i => i.collections.some(c => c.id === colId))
  }
  return list
}

// 標題搜尋：在已載入的清單上做即時前端過濾，與 applyListFilters 疊加使用。
// 比對主顯示標題（name）與日文原名（titleJa），不分大小寫、query 先 trim；
// 空字串不過濾。
export function applyTitleSearch(list: ListItem[], query: string): ListItem[] {
  const q = query.trim().toLowerCase()
  if (q === '') return list
  return list.filter(item =>
    item.anime.name.toLowerCase().includes(q) ||
    item.anime.titleJa.toLowerCase().includes(q)
  )
}

export type ListSortKey = 'added' | 'airDate' | 'year'

// 清單排序（皆新→舊）。非破壞性（複製後排序）。airDate/year 缺值排到最後，
// 避免 null 污染前段。與 applyListFilters/applyTitleSearch 疊加使用。
export function applyListSort(list: ListItem[], sort: ListSortKey): ListItem[] {
  const copy = [...list]
  if (sort === 'added') {
    return copy.sort((a, b) => b.createdAt.localeCompare(a.createdAt))
  }
  if (sort === 'airDate') {
    return copy.sort((a, b) => nullsLast(a.anime.airDate, b.anime.airDate, (x, y) => y.localeCompare(x)))
  }
  return copy.sort((a, b) => nullsLast(a.anime.seasonYear, b.anime.seasonYear, (x, y) => y - x))
}

// 兩值皆有 → cmp；只有一方為 null → 有值者在前；皆 null → 相等。
function nullsLast<T>(a: T | null, b: T | null, cmp: (x: T, y: T) => number): number {
  if (a === null && b === null) return 0
  if (a === null) return 1
  if (b === null) return -1
  return cmp(a, b)
}
