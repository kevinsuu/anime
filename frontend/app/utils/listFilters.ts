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
