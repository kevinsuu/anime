import type { ListItem } from './normalize'

export interface TagOption {
  tag: string
  count: number
}

export function extractTagOptions(list: ListItem[]): TagOption[] {
  const counts: Record<string, number> = {}
  for (const item of list) {
    for (const tag of item.anime.tags) {
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
