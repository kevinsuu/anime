const MINIMUM_INDEXABLE_DESCRIPTION_LENGTH = 160

function isIndexableAnime(item: { description: string | null }): boolean {
  return (item.description?.replace(/\s+/g, '').length ?? 0) >= MINIMUM_INDEXABLE_DESCRIPTION_LENGTH
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
        const res = await $fetch<{
          items: {
            id: number
            description: string | null
            air_date: string | null
            updated_at: string | null
            season_year: number | null
            season_code: string | null
          }[]
        }>(`${apiBaseUrl}/anime`, { query: { year } })
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
    .filter(isIndexableAnime)
    .map((item) => ({
      loc: `/anime/${item.id}`,
      lastmod: item.updated_at || item.air_date || undefined
    }))

  return [...seasonalUrls, ...animeUrls]
})
