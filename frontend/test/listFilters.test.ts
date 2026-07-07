import { describe, expect, it } from 'vitest'
import { applyListFilters, applyTitleSearch } from '../app/utils/listFilters'
import { normalizeListItem } from '../app/utils/normalize'
import type { ListItem } from '../app/utils/normalize'

function makeListItem(opts: {
  watched?: boolean
  collections?: { id: number; name: string }[]
  name?: string
  titleJa?: string
}): ListItem {
  return normalizeListItem({
    id: Math.random(),
    watched: opts.watched ?? false,
    collections: opts.collections ?? [],
    anime: {
      id: 1,
      name: opts.name ?? '測試作品',
      tags: [],
      titles: opts.titleJa ? [{ locale: 'ja', title: opts.titleJa }] : [],
    },
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

describe('applyTitleSearch', () => {
  it('returns the full list when query is empty', () => {
    const list = [makeListItem({ name: '芙莉蓮' }), makeListItem({ name: 'Bocchi' })]
    expect(applyTitleSearch(list, '')).toHaveLength(2)
  })

  it('returns the full list when query is only whitespace', () => {
    const list = [makeListItem({ name: '芙莉蓮' }), makeListItem({ name: 'Bocchi' })]
    expect(applyTitleSearch(list, '   ')).toHaveLength(2)
  })

  it('matches on the Chinese name (substring)', () => {
    const list = [makeListItem({ name: '葬送的芙莉蓮' }), makeListItem({ name: '排球少年' })]
    const result = applyTitleSearch(list, '芙莉蓮')
    expect(result).toHaveLength(1)
    expect(result[0].anime.name).toBe('葬送的芙莉蓮')
  })

  it('matches on the Japanese title (titleJa, substring)', () => {
    const list = [
      makeListItem({ name: '孤獨搖滾', titleJa: 'ぼっち・ざ・ろっく！' }),
      makeListItem({ name: '排球少年', titleJa: 'ハイキュー' }),
    ]
    const result = applyTitleSearch(list, 'ぼっち')
    expect(result).toHaveLength(1)
    expect(result[0].anime.name).toBe('孤獨搖滾')
  })

  it('is case-insensitive', () => {
    const list = [makeListItem({ name: 'Bocchi the Rock' }), makeListItem({ name: '排球少年' })]
    const result = applyTitleSearch(list, 'BOCCHI')
    expect(result).toHaveLength(1)
    expect(result[0].anime.name).toBe('Bocchi the Rock')
  })

  it('trims surrounding whitespace from the query', () => {
    const list = [makeListItem({ name: '芙莉蓮' }), makeListItem({ name: '排球少年' })]
    const result = applyTitleSearch(list, '  芙莉蓮  ')
    expect(result).toHaveLength(1)
    expect(result[0].anime.name).toBe('芙莉蓮')
  })

  it('composes after applyListFilters (status then title)', () => {
    const list = [
      makeListItem({ name: '芙莉蓮', watched: true }),
      makeListItem({ name: '芙莉蓮外傳', watched: false }),
      makeListItem({ name: '排球少年', watched: true }),
    ]
    const result = applyTitleSearch(applyListFilters(list, 'watched'), '芙莉蓮')
    expect(result).toHaveLength(1)
    expect(result[0].anime.name).toBe('芙莉蓮')
  })
})
