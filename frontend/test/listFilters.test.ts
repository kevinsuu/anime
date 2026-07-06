import { describe, expect, it } from 'vitest'
import { applyListFilters } from '../app/utils/listFilters'
import { normalizeListItem } from '../app/utils/normalize'
import type { ListItem } from '../app/utils/normalize'

function makeListItem(opts: { watched?: boolean; collections?: { id: number; name: string }[] }): ListItem {
  return normalizeListItem({
    id: Math.random(),
    watched: opts.watched ?? false,
    collections: opts.collections ?? [],
    anime: { id: 1, name: '測試作品', tags: [] },
  })
}

describe('applyListFilters', () => {
  it('returns the full list for the "all" filter', () => {
    const list = [
      makeListItem({ watched: true }),
      makeListItem({ watched: false }),
    ]
    expect(applyListFilters(list, 'all')).toHaveLength(2)
  })

  it('filters to only watched items', () => {
    const list = [
      makeListItem({ watched: true }),
      makeListItem({ watched: false }),
    ]
    const result = applyListFilters(list, 'watched')
    expect(result).toHaveLength(1)
    expect(result[0].watched).toBe(true)
  })

  it('filters to only unwatched items', () => {
    const list = [
      makeListItem({ watched: true }),
      makeListItem({ watched: false }),
    ]
    const result = applyListFilters(list, 'unwatched')
    expect(result).toHaveLength(1)
    expect(result[0].watched).toBe(false)
  })

  it('filters to items within a given collection', () => {
    const col = { id: 1, name: '我的最愛' }
    const list = [
      makeListItem({ collections: [col] }),
      makeListItem({ collections: [] }),
    ]
    const result = applyListFilters(list, 'col:1')
    expect(result).toHaveLength(1)
    expect(result[0].collections).toEqual([col])
  })
})
