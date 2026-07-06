import { describe, expect, it } from 'vitest'
import { deriveFilterOptions, isGenreTag, weekdayIndexOf } from '../app/composables/useSeasonalCatalog'
import { normalizeAnime } from '../app/utils/normalize'

describe('isGenreTag', () => {
  it('returns true for genre/theme tags', () => {
    expect(isGenreTag('戀愛')).toBe(true)
    expect(isGenreTag('戰鬥')).toBe(true)
  })

  it('returns false for source/type tags', () => {
    expect(isGenreTag('新作')).toBe(false)
    expect(isGenreTag('漫畫改編')).toBe(false)
    expect(isGenreTag('續作')).toBe(false)
  })

  it('returns false for season-count tags like "2季度"', () => {
    expect(isGenreTag('2季度')).toBe(false)
  })
})

describe('deriveFilterOptions', () => {
  it('returns empty lists when the anime list is empty', () => {
    expect(deriveFilterOptions([])).toEqual({ sources: [], genres: [], actors: [] })
  })

  it('splits source tags and genre tags, counting occurrences', () => {
    const list = [
      normalizeAnime({ id: 1, name: 'A', tags: ['新作', '戀愛'] }),
      normalizeAnime({ id: 2, name: 'B', tags: ['新作', '戰鬥'] }),
    ]
    const { sources, genres } = deriveFilterOptions(list)
    expect(sources).toEqual([{ tag: '新作', count: 2 }])
    expect(genres).toEqual(
      expect.arrayContaining([
        { tag: '戀愛', count: 1 },
        { tag: '戰鬥', count: 1 },
      ])
    )
  })

  it('ignores season-count tags like "2季度"', () => {
    const list = [normalizeAnime({ id: 1, name: 'A', tags: ['2季度', '戀愛'] })]
    const { genres } = deriveFilterOptions(list)
    expect(genres).toEqual([{ tag: '戀愛', count: 1 }])
  })

  it('only includes actors appearing at least twice, capped at 20', () => {
    const list = [
      normalizeAnime({ id: 1, name: 'A', cast: [{ character: '角色1', actor: '聲優A' }] }),
      normalizeAnime({ id: 2, name: 'B', cast: [{ character: '角色2', actor: '聲優A' }] }),
      normalizeAnime({ id: 3, name: 'C', cast: [{ character: '角色3', actor: '聲優B' }] }),
    ]
    const { actors } = deriveFilterOptions(list)
    expect(actors).toEqual([{ actor: '聲優A', count: 2 }])
  })

  it('excludes the placeholder actor "？？？"', () => {
    const list = [
      normalizeAnime({ id: 1, name: 'A', cast: [{ character: '角色1', actor: '？？？' }] }),
      normalizeAnime({ id: 2, name: 'B', cast: [{ character: '角色2', actor: '？？？' }] }),
    ]
    expect(deriveFilterOptions(list).actors).toEqual([])
  })
})

describe('weekdayIndexOf', () => {
  it('returns null when neither airDateText nor airDate is present', () => {
    const anime = normalizeAnime({ id: 1, name: 'A' })
    expect(weekdayIndexOf(anime)).toBeNull()
  })

  it('parses the weekday from airDateText (每週X)', () => {
    const anime = normalizeAnime({ id: 1, name: 'A', air_date_text: '7月7日起／每週二／20時30分' })
    expect(weekdayIndexOf(anime)).toBe(2)
  })

  it('falls back to the day-of-week derived from airDate (plain date string)', () => {
    const anime = normalizeAnime({ id: 1, name: 'A', air_date: '2026-04-05' })
    expect(weekdayIndexOf(anime)).toBe(new Date(2026, 3, 5).getDay())
  })
})
