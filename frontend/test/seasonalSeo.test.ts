import { describe, expect, it } from 'vitest'
import type { AnimeSummary } from '../app/utils/normalize'
import { buildSeasonalFaq, relatedSeasons, seasonHeading, summarizeSeason } from '../app/utils/seasonalSeo'

const fall2026 = { year: 2026, season: 'fall' as const }

function anime(overrides: Partial<AnimeSummary>): AnimeSummary {
  return {
    id: 1,
    name: '測試動畫',
    imageUrl: '',
    airDate: null,
    airDateText: '',
    episodeCount: null,
    tags: [],
    streamCount: 0,
    source: '',
    seasonYear: 2026,
    seasonCode: 'fall',
    status: '',
    actors: [],
    ...overrides
  }
}

describe('seasonal SEO copy', () => {
  it('labels the season with both month and season name', () => {
    expect(seasonHeading(fall2026)).toBe('2026年10月（秋季）')
  })

  it('summarises counts, weekdays and in-season premiere range from the listed titles', () => {
    const items = [
      anime({ id: 1, tags: ['新作', '漫畫改編', '奇幻'], airDate: '2026-10-03', airDateText: '10月3日起／每週六／23時00分', streamCount: 2 }),
      anime({ id: 2, tags: ['新作', '原創作品', '奇幻', '戀愛'], airDate: '2026-10-07', airDateText: '每週二', streamCount: 0 }),
      // Carry-over title premiered last season: counted, but not as a premiere.
      anime({ id: 3, tags: ['跨季續播', '戀愛'], airDate: '2026-07-05', airDateText: '每週六', streamCount: 1 })
    ]

    const summary = summarizeSeason(items, fall2026)

    expect(summary.total).toBe(3)
    expect(summary.sources[0]).toEqual({ tag: '新作', count: 2 })
    expect(summary.genres.map(g => g.tag)).toEqual(['奇幻', '戀愛'])
    expect(summary.weekdays).toEqual([{ label: '週二', count: 1 }, { label: '週六', count: 2 }])
    expect(summary.withStreams).toBe(2)
    expect(summary.firstPremiere).toBe('2026-10-03')
    expect(summary.lastPremiere).toBe('2026-10-07')
  })

  it('builds answer-first FAQ entries that only state derived facts', () => {
    const items = [
      anime({ id: 1, tags: ['新作', '奇幻'], airDate: '2026-10-03', airDateText: '每週六', streamCount: 1 }),
      anime({ id: 2, tags: ['續作', '奇幻'], airDate: '2026-10-10', airDateText: '每週六' })
    ]
    const faq = buildSeasonalFaq(fall2026, summarizeSeason(items, fall2026))

    expect(faq.map(item => item.question)).toEqual([
      '2026年10月新番有哪些？',
      '2026年10月新番什麼時候開播？',
      '2026年10月新番每週幾播出？',
      '2026年10月新番在哪裡看？'
    ])
    expect(faq[0]?.answer).toContain('共 2 部')
    expect(faq[0]?.answer).toContain('奇幻（2 部）')
    expect(faq[1]?.answer).toContain('最早於10月3日首播，最晚於10月10日首播')
    expect(faq[2]?.answer).toContain('週六 2 部')
    expect(faq[3]?.answer).toContain('有 1 部已整理串流觀看平台')
  })

  it('omits FAQ entries without supporting data', () => {
    expect(buildSeasonalFaq(fall2026, summarizeSeason([], fall2026))).toEqual([])

    const faq = buildSeasonalFaq(fall2026, summarizeSeason([anime({})], fall2026))
    expect(faq.map(item => item.question)).toEqual(['2026年10月新番有哪些？'])
  })

  it('links the other seasons of the year and the same season in adjacent years', () => {
    expect(relatedSeasons(fall2026, { year: 2027, season: 'fall' })).toEqual([
      { year: 2026, season: 'winter' },
      { year: 2026, season: 'spring' },
      { year: 2026, season: 'summer' },
      { year: 2025, season: 'fall' },
      { year: 2027, season: 'fall' }
    ])
  })

  it('does not link seasons after the upcoming one', () => {
    expect(relatedSeasons(fall2026, { year: 2027, season: 'winter' })).toEqual([
      { year: 2026, season: 'winter' },
      { year: 2026, season: 'spring' },
      { year: 2026, season: 'summer' },
      { year: 2025, season: 'fall' }
    ])
  })

  it('leaves promotional tags out of the genre summary', () => {
    const summary = summarizeSeason([anime({ tags: ['高期待', '奇幻'] })], fall2026)
    expect(summary.genres.map(g => g.tag)).toEqual(['奇幻'])
  })
})
