export default defineSitemapEventHandler(async () => {
  const config = useRuntimeConfig()
  const apiBaseUrl = config.apiBaseUrlInternal as string
  const currentYear = new Date().getFullYear()
  const startYear = 2016
  const years = Array.from({ length: currentYear - startYear + 1 }, (_, i) => startYear + i)

  const results = await Promise.all(
    years.map(async (year) => {
      try {
        const res = await $fetch<{ items: { id: number; air_date: string | null }[] }>(`${apiBaseUrl}/anime`, { query: { year } })
        return res.items || []
      } catch {
        return []
      }
    })
  )

  return results.flat().map((item) => ({ loc: `/anime/${item.id}`, lastmod: item.air_date || undefined }))
})
