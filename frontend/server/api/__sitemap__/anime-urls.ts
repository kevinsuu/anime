import { isIndexableAnime } from '../../../app/utils/indexability'

interface SitemapAnimeItem {
  id: number
  description: string | null
  episode_count: number | null
  streams?: unknown[]
  cast?: unknown[]
  air_date: string | null
  updated_at: string | null
  season_year: number | null
  season_code: string | null
}

// Same rule the detail page uses for its robots meta, so the sitemap never
// lists a page that answers `noindex`.
function isIndexableItem(item: SitemapAnimeItem): boolean {
  return isIndexableAnime({
    description: item.description,
    episodeCount: item.episode_count,
    streamCount: item.streams?.length ?? 0,
    castCount: item.cast?.length ?? 0
  })
}

export default defineSitemapEventHandler(async () => {
  const config = useRuntimeConfig()
  const apiBaseUrl = config.apiBaseUrlInternal as string
  // Include next year: upcoming seasons (e.g. 2027年1月新番) are searched for
  // weeks before they air, as soon as their line-up is announced.
  const lastYear = new Date().getFullYear() + 1
  const startYear = 2016
  const years = Array.from({ length: lastYear - startYear + 1 }, (_, i) => startYear + i)
  const seasons = new Set(['winter', 'spring', 'summer', 'fall'])

  const results = await Promise.all(
    years.map(async (year) => {
      try {
        const res = await $fetch<{ items: SitemapAnimeItem[] }>(`${apiBaseUrl}/anime`, { query: { year } })
        return res.items || []
      } catch {
        return []
      }
    })
  )

  // Only list seasons that actually have titles (an empty season page is thin
  // and served as noindex), dated by the newest change among its titles.
  const seasonLastmod = new Map<string, string>()
  for (const item of results.flat()) {
    if (!item.season_year || !item.season_code || !seasons.has(item.season_code)) continue
    const key = `${item.season_year}:${item.season_code}`
    const modified = item.updated_at || item.air_date || ''
    if (!seasonLastmod.has(key) || modified > (seasonLastmod.get(key) ?? '')) {
      seasonLastmod.set(key, modified)
    }
  }
  const seasonalUrls = [...seasonLastmod.entries()].map(([key, lastmod]) => {
    const [year, season] = key.split(':')
    return { loc: `/?year=${year}&season=${season}`, lastmod: lastmod || undefined }
  })
  const animeUrls = results.flat()
    .filter(isIndexableItem)
    .map((item) => ({
      loc: `/anime/${item.id}`,
      lastmod: item.updated_at || item.air_date || undefined
    }))

  return [...seasonalUrls, ...animeUrls]
})
