import { SOURCE_TAGS, isGenreTag, weekdayIndexOf } from '../composables/useSeasonalCatalog'
import type { AnimeSummary } from './normalize'
import { isSeasonAfter, seasonMonthLabels, seasons, shiftSeason } from './season'
import type { Season, SeasonSelection } from './season'

export const seasonNameLabels: Readonly<Record<Season, string>> = {
  winter: '冬季',
  spring: '春季',
  summer: '夏季',
  fall: '秋季'
}

const SEASON_START_MONTH: Readonly<Record<Season, number>> = {
  winter: 1,
  spring: 4,
  summer: 7,
  fall: 10
}

// Promotional labels from the source site that are not a genre a reader asks about.
const NON_GENRE_SUMMARY_TAGS = new Set(['高期待'])

// Display order Monday → Sunday, matching the weekday tabs.
const WEEKDAY_ORDER: ReadonlyArray<{ index: number; label: string }> = [
  { index: 1, label: '週一' },
  { index: 2, label: '週二' },
  { index: 3, label: '週三' },
  { index: 4, label: '週四' },
  { index: 5, label: '週五' },
  { index: 6, label: '週六' },
  { index: 0, label: '週日' }
]

export interface TagCount {
  tag: string
  count: number
}

export interface SeasonalSummary {
  total: number
  sources: TagCount[]
  genres: TagCount[]
  weekdays: { label: string; count: number }[]
  withStreams: number
  firstPremiere: string | null
  lastPremiere: string | null
}

export interface SeasonalFaqItem {
  question: string
  answer: string
}

/** e.g. 「2026年10月（秋季）」 */
export function seasonHeading(selection: SeasonSelection): string {
  return `${selection.year}年${seasonMonthLabels[selection.season]}（${seasonNameLabels[selection.season]}）`
}

function countTags(items: AnimeSummary[], include: (tag: string) => boolean): TagCount[] {
  const counts = new Map<string, number>()
  for (const item of items) {
    for (const tag of new Set(item.tags)) {
      if (include(tag)) counts.set(tag, (counts.get(tag) ?? 0) + 1)
    }
  }
  return [...counts.entries()]
    .map(([tag, count]) => ({ tag, count }))
    .sort((a, b) => b.count - a.count || a.tag.localeCompare(b.tag, 'zh-Hant'))
}

/** Premiere dates inside the season's three months; carry-over titles are ignored. */
function seasonPremiereDates(items: AnimeSummary[], selection: SeasonSelection): string[] {
  const startMonth = SEASON_START_MONTH[selection.season]
  return items
    .map(item => item.airDate?.slice(0, 10) ?? '')
    .filter((date) => {
      const match = date.match(/^(\d{4})-(\d{2})-\d{2}$/)
      if (!match) return false
      const month = Number(match[2])
      return Number(match[1]) === selection.year && month >= startMonth && month < startMonth + 3
    })
    .sort()
}

export function summarizeSeason(items: AnimeSummary[], selection: SeasonSelection): SeasonalSummary {
  const weekdayCounts = new Map<number, number>()
  for (const item of items) {
    const index = weekdayIndexOf(item)
    if (index !== null) weekdayCounts.set(index, (weekdayCounts.get(index) ?? 0) + 1)
  }
  const premieres = seasonPremiereDates(items, selection)

  return {
    total: items.length,
    sources: countTags(items, tag => SOURCE_TAGS.has(tag)),
    genres: countTags(items, tag => isGenreTag(tag) && !NON_GENRE_SUMMARY_TAGS.has(tag)),
    weekdays: WEEKDAY_ORDER
      .map(({ index, label }) => ({ label, count: weekdayCounts.get(index) ?? 0 }))
      .filter(day => day.count > 0),
    withStreams: items.filter(item => item.streamCount > 0).length,
    firstPremiere: premieres[0] ?? null,
    lastPremiere: premieres[premieres.length - 1] ?? null
  }
}

function formatMonthDay(date: string): string {
  const [, month, day] = date.split('-').map(Number)
  return `${month}月${day}日`
}

function joinCounts(items: TagCount[], limit: number): string {
  return items.slice(0, limit).map(item => `${item.tag}（${item.count} 部）`).join('、')
}

/**
 * Answer-first copy for search and answer engines. Every sentence is derived
 * from the listed titles so the visible text and FAQ JSON-LD stay factual.
 */
export function buildSeasonalFaq(selection: SeasonSelection, summary: SeasonalSummary): SeasonalFaqItem[] {
  if (summary.total === 0) return []
  const heading = seasonHeading(selection)
  const monthLabel = `${selection.year}年${seasonMonthLabels[selection.season]}`
  const faq: SeasonalFaqItem[] = []

  const overview = [`動漫庫收錄${heading}新番動畫共 ${summary.total} 部。`]
  if (summary.sources.length > 0) overview.push(`作品類型以${joinCounts(summary.sources, 3)}為主。`)
  if (summary.genres.length > 0) overview.push(`題材最多的是${joinCounts(summary.genres, 5)}。`)
  faq.push({ question: `${monthLabel}新番有哪些？`, answer: overview.join('') })

  if (summary.firstPremiere && summary.lastPremiere) {
    faq.push({
      question: `${monthLabel}新番什麼時候開播？`,
      answer: summary.firstPremiere === summary.lastPremiere
        ? `本季新番於${formatMonthDay(summary.firstPremiere)}首播。`
        : `本季新番最早於${formatMonthDay(summary.firstPremiere)}首播，最晚於${formatMonthDay(summary.lastPremiere)}首播；各作品的首播日與每週播出時間標示在作品卡片上。`
    })
  }

  if (summary.weekdays.length > 0) {
    faq.push({
      question: `${monthLabel}新番每週幾播出？`,
      answer: `依每週播出日統計：${summary.weekdays.map(day => `${day.label} ${day.count} 部`).join('、')}。可用新番表上方的星期分頁查看每天播出的作品。`
    })
  }

  if (summary.withStreams > 0) {
    faq.push({
      question: `${monthLabel}新番在哪裡看？`,
      answer: `${summary.total} 部新番中有 ${summary.withStreams} 部已整理串流觀看平台，點進作品頁即可查看各地區的播放平台。`
    })
  }

  return faq
}

/**
 * Every other season of this year plus the same season in adjacent years, for
 * internal links. Seasons after `latest` (normally the upcoming season) have no
 * announced titles yet, so they are not linked.
 */
export function relatedSeasons(selection: SeasonSelection, latest: SeasonSelection): SeasonSelection[] {
  const sameYear = seasons.map(season => ({ year: selection.year, season }))
  const neighbours = [shiftSeason(selection, -4), shiftSeason(selection, 4)]
  return [...sameYear, ...neighbours]
    .filter(item => item.year !== selection.year || item.season !== selection.season)
    .filter(item => !isSeasonAfter(item, latest))
}
