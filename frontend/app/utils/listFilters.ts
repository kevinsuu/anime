import { isGenreTag } from '../composables/useSeasonalCatalog'
import type { ListItem } from './normalize'

export interface TagOption {
  tag: string
  count: number
}

// Only counts genre/theme tags (e.g. 戀愛/戰鬥/搞笑) — source/type tags
// (新作/漫畫改編/…) and season-count tags are excluded, matching the same
// exclusion `useSeasonalCatalog.ts` applies for the /catalog genre filter,
// since those aren't genres a user would filter their list by.
export function extractTagOptions(list: ListItem[]): TagOption[] {
  const counts: Record<string, number> = {}
  for (const item of list) {
    for (const tag of item.anime.tags) {
      if (!isGenreTag(tag)) continue
      counts[tag] = (counts[tag] ?? 0) + 1
    }
  }
  return Object.entries(counts)
    .map(([tag, count]) => ({ tag, count }))
    .sort((a, b) => b.count - a.count || a.tag.localeCompare(b.tag))
}

export function matchesSelectedTags(item: ListItem, selectedTags: string[]): boolean {
  if (selectedTags.length === 0) return true
  return selectedTags.some(tag => item.anime.tags.includes(tag))
}

// Composes the status filter (all/watched/unwatched/col:{id}) with the tag
// filter (AND) — this is the exact combination the "my list" page applies,
// extracted here so the combination itself (not just each half) is testable.
export function applyListFilters(list: ListItem[], statusFilter: string, selectedTags: string[]): ListItem[] {
  let base = list
  if (statusFilter === 'watched') base = list.filter(i => i.watched)
  else if (statusFilter === 'unwatched') base = list.filter(i => !i.watched)
  else if (statusFilter.startsWith('col:')) {
    const colId = Number(statusFilter.slice(4))
    base = list.filter(i => i.collections.some(c => c.id === colId))
  }
  return base.filter(i => matchesSelectedTags(i, selectedTags))
}
