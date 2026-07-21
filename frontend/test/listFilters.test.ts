import { describe, expect, it } from 'vitest'
import { applyListFilters, applyTagFilters, applyTitleSearch, applyListSort } from '../app/utils/listFilters'
import { normalizeListItem } from '../app/utils/normalize'
import type { ListItem } from '../app/utils/normalize'

function makeListItem(opts: {
  watched?: boolean
  collections?: { id: number; name: string }[]
  name?: string
  titleJa?: string
  createdAt?: string
  airDate?: string | null
  seasonYear?: number | null
  tags?: string[]
}): ListItem {
  return normalizeListItem({
    id: Math.random(),
    watched: opts.watched ?? false,
    collections: opts.collections ?? [],
    createdAt: opts.createdAt ?? '2026-01-01 00:00:00',
    anime: {
      id: 1,
      name: opts.name ?? '測試作品',
      tags: opts.tags ?? [],
      titles: opts.titleJa ? [{ locale: 'ja', title: opts.titleJa }] : [],
      air_date: opts.airDate ?? null,
      season_year: opts.seasonYear ?? null,
    },
  })
}

describe('applyTagFilters', () => {
  it('returns the full list without selected tags', () => {
    const list = [makeListItem({ tags: ['戀愛'] }), makeListItem({ tags: ['戰鬥'] })]
    expect(applyTagFilters(list, [])).toBe(list)
  })

  it('matches any selected tag using OR semantics', () => {
    const list = [
      makeListItem({ name: 'A', tags: ['戀愛'] }),
      makeListItem({ name: 'B', tags: ['戰鬥'] }),
      makeListItem({ name: 'C', tags: ['日常'] })
    ]

    expect(applyTagFilters(list, ['戀愛', '戰鬥']).map(item => item.anime.name)).toEqual(['A', 'B'])
  })
})

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

describe('applyListSort', () => {
  it('sorts by added date (createdAt) newest first', () => {
    const list = [
      makeListItem({ name: 'A', createdAt: '2026-01-01 00:00:00' }),
      makeListItem({ name: 'B', createdAt: '2026-03-01 00:00:00' }),
      makeListItem({ name: 'C', createdAt: '2026-02-01 00:00:00' }),
    ]
    const result = applyListSort(list, 'added')
    expect(result.map(i => i.anime.name)).toEqual(['B', 'C', 'A'])
  })

  it('sorts by airDate newest first, nulls last', () => {
    const list = [
      makeListItem({ name: 'A', airDate: '2024-04-01' }),
      makeListItem({ name: 'B', airDate: null }),
      makeListItem({ name: 'C', airDate: '2026-07-01' }),
    ]
    const result = applyListSort(list, 'airDate')
    expect(result.map(i => i.anime.name)).toEqual(['C', 'A', 'B'])
  })

  it('sorts by seasonYear newest first, nulls last', () => {
    const list = [
      makeListItem({ name: 'A', seasonYear: 2020 }),
      makeListItem({ name: 'B', seasonYear: null }),
      makeListItem({ name: 'C', seasonYear: 2026 }),
    ]
    const result = applyListSort(list, 'year')
    expect(result.map(i => i.anime.name)).toEqual(['C', 'A', 'B'])
  })

  it('does not mutate the input array', () => {
    const list = [
      makeListItem({ name: 'A', createdAt: '2026-01-01 00:00:00' }),
      makeListItem({ name: 'B', createdAt: '2026-03-01 00:00:00' }),
    ]
    const before = list.map(i => i.anime.name)
    applyListSort(list, 'added')
    expect(list.map(i => i.anime.name)).toEqual(before)
  })

  it('composes after filters (status → title → sort)', () => {
    const list = [
      makeListItem({ name: '芙莉蓮 A', watched: true, createdAt: '2026-01-01 00:00:00' }),
      makeListItem({ name: '芙莉蓮 B', watched: true, createdAt: '2026-05-01 00:00:00' }),
      makeListItem({ name: '排球少年', watched: true, createdAt: '2026-09-01 00:00:00' }),
      makeListItem({ name: '芙莉蓮 C', watched: false, createdAt: '2026-12-01 00:00:00' }),
    ]
    const result = applyListSort(
      applyTitleSearch(applyListFilters(list, 'watched'), '芙莉蓮'),
      'added'
    )
    expect(result.map(i => i.anime.name)).toEqual(['芙莉蓮 B', '芙莉蓮 A'])
  })
})
