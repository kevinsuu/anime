import { describe, expect, it } from 'vitest'
import { extractTagOptions, matchesSelectedTags } from '../app/utils/listFilters'
import { normalizeListItem } from '../app/utils/normalize'
import type { ListItem } from '../app/utils/normalize'

function makeItem(tags: string[], overrides: Record<string, any> = {}): ListItem {
  return normalizeListItem({
    id: Math.random(),
    anime: { id: 1, name: '測試作品', tags, ...overrides },
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
