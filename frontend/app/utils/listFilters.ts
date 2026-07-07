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
