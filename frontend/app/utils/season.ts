export const seasons = ['winter', 'spring', 'summer', 'fall'] as const

export type Season = typeof seasons[number]

export interface SeasonSelection {
  year: number
  season: Season
}

export const seasonMonthLabels: Readonly<Record<string, string>> = {
  winter: '1月',
  spring: '4月',
  summer: '7月',
  fall: '10月'
}

export function seasonForMonth(month: number): Season {
  if (month <= 3) return 'winter'
  if (month <= 6) return 'spring'
  if (month <= 9) return 'summer'
  return 'fall'
}

export function isSeason(value: unknown): value is Season {
  return typeof value === 'string' && seasons.includes(value as Season)
}

export function isSeasonSelection(yearValue: unknown, seasonValue: unknown): boolean {
  return typeof yearValue === 'string' && /^\d{4}$/.test(yearValue) && isSeason(seasonValue)
}

export function seasonSelection(
  yearValue: unknown,
  seasonValue: unknown,
  now = new Date()
): SeasonSelection {
  const parsedYear = typeof yearValue === 'string' && /^\d{4}$/.test(yearValue)
    ? Number(yearValue)
    : Number.NaN

  return {
    year: Number.isInteger(parsedYear) ? parsedYear : now.getFullYear(),
    season: isSeason(seasonValue) ? seasonValue : seasonForMonth(now.getMonth() + 1)
  }
}

export function shiftSeason(selection: SeasonSelection, offset: number): SeasonSelection {
  const currentIndex = seasons.indexOf(selection.season)
  const absoluteIndex = selection.year * seasons.length + currentIndex + offset
  const year = Math.floor(absoluteIndex / seasons.length)
  const seasonIndex = ((absoluteIndex % seasons.length) + seasons.length) % seasons.length

  return { year, season: seasons[seasonIndex] ?? 'winter' }
}
