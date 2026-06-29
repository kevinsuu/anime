import { describe, expect, it } from 'vitest'
import { matchesAnimeCategory, weekdayIndexOf, genreCategories } from '../app/composables/useSeasonalCatalog'
import { normalizeAnime } from '../app/utils/normalize'

describe('matchesAnimeCategory', () => {
  it('matches "all" category for anything', () => {
    const anime = normalizeAnime({ name: '任意作品' })
    expect(matchesAnimeCategory(anime, genreCategories[0])).toBe(true)
  })

  it('matches fantasy category by keyword in name', () => {
    const anime = normalizeAnime({ name: '異世界悠閒農家' })
    const fantasy = genreCategories.find(c => c.key === 'fantasy')!
    expect(matchesAnimeCategory(anime, fantasy)).toBe(true)
  })

  it('does not match unrelated category', () => {
    const anime = normalizeAnime({ name: '異世界悠閒農家' })
    const romance = genreCategories.find(c => c.key === 'romance')!
    expect(matchesAnimeCategory(anime, romance)).toBe(false)
  })
})

describe('weekdayIndexOf', () => {
  it('returns null when airDate is missing', () => {
    const anime = normalizeAnime({})
    expect(weekdayIndexOf(anime)).toBeNull()
  })

  it('returns the day-of-week index for a valid date', () => {
    const anime = normalizeAnime({ air_date: '2026-04-05T23:00:00' })
    const expected = new Date('2026-04-05T23:00:00').getDay()
    expect(weekdayIndexOf(anime)).toBe(expected)
  })
})
