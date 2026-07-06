import { describe, expect, it } from 'vitest'
import { applyListFilters, extractTagOptions, matchesSelectedTags } from '../app/utils/listFilters'
import { normalizeListItem } from '../app/utils/normalize'
import type { ListItem } from '../app/utils/normalize'

function makeItem(tags: string[], overrides: Record<string, any> = {}): ListItem {
  return normalizeListItem({
    id: Math.random(),
    anime: { id: 1, name: '測試作品', tags, ...overrides },
  })
}

// Extends makeItem with list-item-level fields (watched, collections) needed
// for testing applyListFilters' status+tag combination.
function makeListItem(opts: { tags: string[]; watched?: boolean; collections?: { id: number; name: string }[] }): ListItem {
  return normalizeListItem({
    id: Math.random(),
    watched: opts.watched ?? false,
    collections: opts.collections ?? [],
    anime: { id: 1, name: '測試作品', tags: opts.tags },
  })
}

describe('extractTagOptions', () => {
  it('returns empty array when list is empty', () => {
    expect(extractTagOptions([])).toEqual([])
  })

  it('dedupes tags and counts occurrences', () => {
    const list = [
      makeItem(['戀愛', '戰鬥']),
      makeItem(['戀愛', '搞笑']),
      makeItem(['戰鬥']),
    ]
    const options = extractTagOptions(list)
    // Ties on count break alphabetically (ascending) for deterministic chip
    // order: 戀愛/戰鬥 both have count 2, and 戀愛 < 戰鬥 per localeCompare.
    expect(options).toEqual([
      { tag: '戀愛', count: 2 },
      { tag: '戰鬥', count: 2 },
      { tag: '搞笑', count: 1 },
    ])
  })

  it('ignores items with no tags', () => {
    const list = [makeItem([]), makeItem(['戀愛'])]
    expect(extractTagOptions(list)).toEqual([{ tag: '戀愛', count: 1 }])
  })

  it('excludes source/type tags like 新作/漫畫改編 (not a genre)', () => {
    const list = [makeItem(['新作', '漫畫改編', '戀愛'])]
    expect(extractTagOptions(list)).toEqual([{ tag: '戀愛', count: 1 }])
  })

  it('excludes season-count tags like "2季度" (not a genre)', () => {
    const list = [makeItem(['2季度', '戰鬥'])]
    expect(extractTagOptions(list)).toEqual([{ tag: '戰鬥', count: 1 }])
  })

  it('sorts options by count descending', () => {
    const list = [makeItem(['戰鬥']), makeItem(['戀愛']), makeItem(['戀愛'])]
    const options = extractTagOptions(list)
    expect(options[0]).toEqual({ tag: '戀愛', count: 2 })
    expect(options[1]).toEqual({ tag: '戰鬥', count: 1 })
  })
})

describe('matchesSelectedTags', () => {
  it('returns true when no tags are selected (no-op filter)', () => {
    const item = makeItem(['戀愛'])
    expect(matchesSelectedTags(item, [])).toBe(true)
  })

  it('returns true when item has at least one selected tag (OR logic)', () => {
    const item = makeItem(['戀愛', '日常'])
    expect(matchesSelectedTags(item, ['戰鬥', '戀愛'])).toBe(true)
  })

  it('returns false when item has none of the selected tags', () => {
    const item = makeItem(['日常'])
    expect(matchesSelectedTags(item, ['戰鬥', '戀愛'])).toBe(false)
  })
})

describe('applyListFilters', () => {
  it('combines the watched status filter with the tag filter (AND)', () => {
    const list = [
      makeListItem({ tags: ['戀愛'], watched: true }),   // matches both
      makeListItem({ tags: ['戀愛'], watched: false }),  // right genre, not watched
      makeListItem({ tags: ['戰鬥'], watched: true }),   // watched, wrong genre
    ]
    const result = applyListFilters(list, 'watched', ['戀愛'])
    expect(result).toHaveLength(1)
    expect(result[0].watched).toBe(true)
    expect(result[0].anime.tags).toEqual(['戀愛'])
  })

  it('combines the unwatched status filter with the tag filter (AND)', () => {
    const list = [
      makeListItem({ tags: ['戀愛'], watched: false }),  // matches both
      makeListItem({ tags: ['戀愛'], watched: true }),   // right genre, watched
      makeListItem({ tags: ['戰鬥'], watched: false }),  // unwatched, wrong genre
    ]
    const result = applyListFilters(list, 'unwatched', ['戀愛'])
    expect(result).toHaveLength(1)
    expect(result[0].watched).toBe(false)
    expect(result[0].anime.tags).toEqual(['戀愛'])
  })

  it('combines a collection filter with the tag filter (AND)', () => {
    const col = { id: 1, name: '我的最愛' }
    const list = [
      makeListItem({ tags: ['戀愛'], collections: [col] }),  // matches both
      makeListItem({ tags: ['戀愛'], collections: [] }),      // right genre, not in collection
      makeListItem({ tags: ['戰鬥'], collections: [col] }),   // in collection, wrong genre
    ]
    const result = applyListFilters(list, 'col:1', ['戀愛'])
    expect(result).toHaveLength(1)
    expect(result[0].anime.tags).toEqual(['戀愛'])
  })

  it('applies no tag filtering when selectedTags is empty', () => {
    const list = [
      makeListItem({ tags: ['戀愛'], watched: true }),
      makeListItem({ tags: ['戰鬥'], watched: true }),
    ]
    expect(applyListFilters(list, 'watched', [])).toHaveLength(2)
  })

  it('returns the full list for the "all" filter with no tags selected', () => {
    const list = [
      makeListItem({ tags: ['戀愛'], watched: true }),
      makeListItem({ tags: [], watched: false }),
    ]
    expect(applyListFilters(list, 'all', [])).toHaveLength(2)
  })
})
