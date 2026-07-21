import { describe, expect, it } from 'vitest'
import { normalizeAnime, normalizeAnimeSummary, normalizeListItem, repairText } from '../app/utils/normalize'

describe('repairText', () => {
  it('returns the original string when it is not mojibake', () => {
    expect(repairText('芙莉蓮')).toBe('芙莉蓮')
  })

  it('returns fallback for empty input', () => {
    expect(repairText('', '預設值')).toBe('預設值')
  })
})

describe('normalizeAnime', () => {
  it('maps snake_case API fields to camelCase', () => {
    const result = normalizeAnime({
      id: 1,
      name: '葬送的芙莉蓮',
      image_url: 'https://example.com/cover.jpg',
      season_year: 2026,
      season_code: 'spring',
      air_date: '2026-04-05T23:00:00',
      episode_count: 12
    })

    expect(result).toEqual({
      id: 1,
      name: '葬送的芙莉蓮',
      description: '尚未整理作品介紹。',
      imageUrl: 'https://example.com/cover.jpg',
      source: '',
      seasonYear: 2026,
      seasonCode: 'spring',
      airDate: '2026-04-05T23:00:00',
      airDateText: '',
      episodeCount: 12,
      status: '',
      tags: [],
      streamCount: 0,
      actors: [],
      aliases: [],
      streams: [],
      titleJa: '',
      themes: [],
      trailers: [],
      cast: [],
      staff: [],
      links: []
    })
  })

  it('maps streams, aliases, and Japanese title from the API', () => {
    const result = normalizeAnime({
      id: 1,
      name: '測試',
      aliases: ['別名A'],
      streams: [{ region: '台灣', platform: '巴哈', url: 'https://a' }],
      titles: [
        { locale: 'ja', title: 'テスト', is_primary: false },
        { locale: 'zh-Hant', title: '測試', is_primary: true }
      ]
    })

    expect(result.streams).toHaveLength(1)
    expect(result.streams[0].platform).toBe('巴哈')
    expect(result.aliases).toContain('別名A')
    expect(result.titleJa).toBe('テスト')
  })

  it('defaults new fields when absent', () => {
    const result = normalizeAnime({ id: 2, name: 'x' })
    expect(result.streams).toEqual([])
    expect(result.aliases).toEqual([])
    expect(result.titleJa).toBe('')
  })

  it('falls back to placeholder name when missing', () => {
    const result = normalizeAnime({})
    expect(result.name).toBe('未命名作品')
  })

  it('safely narrows malformed unknown payload fields', () => {
    const result = normalizeAnime({
      id: 'invalid',
      tags: 'not-an-array',
      streams: [null, 'invalid'],
      cast: [{ actor: 123 }],
      titles: [{ locale: 'ja', title: 456 }]
    })

    expect(result).toMatchObject({ id: 0, tags: [], actors: [], titleJa: '' })
    expect(result.streams).toEqual([
      { region: '', platform: '', url: null },
      { region: '', platform: '', url: null }
    ])
  })
})

describe('normalizeAnimeSummary', () => {
  it('maps the lightweight card contract without requiring detail relations', () => {
    const result = normalizeAnimeSummary({
      id: 7,
      name: '測試作品',
      image_url: '/storage/covers/7.webp',
      season_year: 2026,
      season_code: 'summer',
      stream_count: 3,
      actors: ['聲優A', '聲優B']
    })

    expect(result).toMatchObject({
      id: 7,
      name: '測試作品',
      imageUrl: '/storage/covers/7.webp',
      seasonYear: 2026,
      seasonCode: 'summer',
      streamCount: 3,
      actors: ['聲優A', '聲優B']
    })
  })
})

describe('normalizeListItem', () => {
  it('normalizes watched boolean and nested anime', () => {
    const result = normalizeListItem({
      id: 5,
      watched: 1,
      rating: '8',
      anime: { id: 1, name: '測試作品' }
    })

    expect(result.watched).toBe(true)
    expect(result.rating).toBe(8)
    expect(result.anime.name).toBe('測試作品')
  })

  it('keeps rating null when absent', () => {
    const result = normalizeListItem({ id: 1, anime: {} })
    expect(result.rating).toBeNull()
  })

  it('parses Japanese titles and aliases from the real list response shape', () => {
    const result = normalizeListItem({
      id: 12,
      watched: false,
      rating: null,
      note: null,
      createdAt: '2026-07-01 12:00:00',
      updatedAt: '2026-07-02 12:00:00',
      collections: [{ id: 3, name: '進行中' }],
      anime: {
        id: 42,
        name: '葬送的芙莉蓮',
        aliases: ['Frieren'],
        titles: [{ locale: 'ja', title: '葬送のフリーレン', is_primary: true }]
      }
    })

    expect(result.anime.aliases).toEqual(['Frieren'])
    expect(result.anime.titleJa).toBe('葬送のフリーレン')
  })
})
