const MINIMUM_INDEXABLE_DESCRIPTION_LENGTH = 160

function isIndexableAnime(item: { description: string | null }): boolean {
  return (item.description?.replace(/\s+/g, '').length ?? 0) >= MINIMUM_INDEXABLE_DESCRIPTION_LENGTH
}

export default defineSitemapEventHandler(async () => {
  const config = useRuntimeConfig()
  const apiBaseUrl = config.apiBaseUrlInternal as string
  const currentYear = new Date().getFullYear()
  const startYear = 2016
  const years = Array.from({ length: currentYear - startYear + 1 }, (_, i) => startYear + i)
  const seasons = ['winter', 'spring', 'summer', 'fall'] as const

  const results = await Promise.all(
    years.map(async (year) => {
      try {
        const res = await $fetch<{
          items: {
            id: number
            description: string | null
            air_date: string | null
            updated_at: string | null
          }[]
        }>(`${apiBaseUrl}/anime`, { query: { year } })
        return res.items || []
      } catch {
        return []
      }
    })
  )

  const seasonalUrls = years.flatMap(year => seasons.map(season => ({
    loc: `/?year=${year}&season=${season}`
  })))
  const animeUrls = results.flat()
    .filter(isIndexableAnime)
    .map((item) => ({
      loc: `/anime/${item.id}`,
      lastmod: item.updated_at || item.air_date || undefined
    }))

  return [...seasonalUrls, ...animeUrls]
})
