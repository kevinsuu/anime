import { describe, expect, it } from 'vitest'
import { seasonSelection, shiftSeason } from '../app/utils/season'

describe('seasonSelection', () => {
  it('parses a valid route selection', () => {
    expect(seasonSelection('2026', 'spring')).toEqual({ year: 2026, season: 'spring' })
  })

  it('falls back to the supplied current date for malformed route values', () => {
    expect(seasonSelection('invalid', 'unknown', new Date('2025-08-01T00:00:00Z')))
      .toEqual({ year: 2025, season: 'summer' })
  })
})

describe('shiftSeason', () => {
  it('moves forward and backward within the same year', () => {
    expect(shiftSeason({ year: 2026, season: 'spring' }, 1))
      .toEqual({ year: 2026, season: 'summer' })
    expect(shiftSeason({ year: 2026, season: 'summer' }, -1))
      .toEqual({ year: 2026, season: 'spring' })
  })

  it('crosses year boundaries without an intermediate wrong season', () => {
    expect(shiftSeason({ year: 2026, season: 'fall' }, 1))
      .toEqual({ year: 2027, season: 'winter' })
    expect(shiftSeason({ year: 2026, season: 'winter' }, -1))
      .toEqual({ year: 2025, season: 'fall' })
  })
})
