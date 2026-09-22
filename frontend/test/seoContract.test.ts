import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { isIndexableAnime } from '../app/utils/indexability'
import { serializeJsonLd } from '../app/utils/seo'

const readSource = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('site identity SEO contract', () => {
  it('identifies the root URL as the branded homepage', () => {
    const indexSource = readSource('app/pages/index.vue')
    const seasonalSource = readSource('app/pages/seasonal.vue')

    expect(indexSource).toContain("'@type': 'WebSite'")
    expect(indexSource).toContain("'@type': 'Organization'")
    expect(indexSource).toContain("name: '動漫庫'")
    expect(indexSource).toContain("alternateName: 'Anime Library'")
    expect(indexSource).toContain('url: `${SITE_URL}/`')
    expect(indexSource).toContain('<SeasonalPage homepage />')
    expect(indexSource).not.toContain('查找每季新番播出時間')
    expect(seasonalSource).toContain("'動漫庫｜動畫新番表、動漫資料庫與追番收藏'")
    expect(seasonalSource).toContain(': `${SITE_URL}/`)')
    expect(seasonalSource).toContain('<h1 class="text-xl')
  })

  it('keeps the product philosophy on the settings page, not the seasonal overview', () => {
    const indexSource = readSource('app/pages/index.vue')
    const settingsSource = readSource('app/pages/settings.vue')

    expect(indexSource).not.toContain('產品理念')
    expect(settingsSource).toContain('Anime Library')
    expect(settingsSource).toContain('產品理念')
    expect(settingsSource).toContain('查找每季新番播出時間')
  })

  it('keeps seasonal canonical URLs under the root URL used by navigation', () => {
    const seasonalSource = readSource('app/pages/seasonal.vue')
    const sitemapSource = readSource('server/api/__sitemap__/anime-urls.ts')

    expect(seasonalSource).toContain('`${SITE_URL}/?year=')
    expect(sitemapSource).toContain('loc: `/?year=${year}&season=${season}`')
    expect(seasonalSource).not.toContain('https://anime.kaistarstudio.me/seasonal?year=')
  })

  it('includes the homepage and excludes the duplicate seasonal path from sitemap discovery', () => {
    const configSource = readSource('nuxt.config.ts')

    expect(configSource).toContain("exclude: ['/seasonal', '/list', '/list/**', '/settings', '/login']")
    expect(configSource).not.toContain("exclude: ['/',")
    expect(configSource).toContain("{ property: 'og:site_name', content: '動漫庫' }")
  })

  it('separates AI search crawlers from model-training crawlers', () => {
    const robotsSource = readSource('public/robots.txt')

    expect(robotsSource).toContain('User-agent: OAI-SearchBot\nDisallow: /list')
    expect(robotsSource).toContain('User-agent: Claude-SearchBot\nDisallow: /list')
    expect(robotsSource).toContain('User-agent: PerplexityBot\nDisallow: /list')
    expect(robotsSource).toContain('User-agent: GPTBot\nDisallow: /')
    expect(robotsSource).toContain('User-agent: ClaudeBot\nDisallow: /')
    expect(robotsSource).not.toContain('User-agent: Googlebot\nDisallow: /')
  })

  it('publishes structured entities and answer-first copy where detail is needed', () => {
    const seasonalSource = readSource('app/pages/seasonal.vue')
    const catalogSource = readSource('app/pages/catalog.vue')
    const animeSource = readSource('app/pages/anime/[id].vue')

    expect(seasonalSource).toContain("'@type': 'ItemList'")
    expect(catalogSource).toContain('catalog-answer-title')
    expect(catalogSource).toContain("'noindex, follow'")
    expect(animeSource).toContain('anime-answer-title')
    expect(animeSource).toContain("'@type': 'TVSeries'")
    expect(animeSource).toContain('dateModified: anime.value.updatedAt')
    expect(animeSource).toContain('sameAs: anime.value.externalIds')
  })

  it('returns a real 404 for invalid or missing anime detail pages', () => {
    const animeSource = readSource('app/pages/anime/[id].vue')

    expect(animeSource).toContain("throw createError({ statusCode: 404")
    expect(animeSource).toContain('fetchError.value?.statusCode === 404')
    expect(animeSource).toContain('Number.isInteger(animeId)')
  })

  it('uses the actual catalog update time for sitemap freshness', () => {
    const sitemapSource = readSource('server/api/__sitemap__/anime-urls.ts')

    expect(sitemapSource).toContain('const MINIMUM_INDEXABLE_DESCRIPTION_LENGTH = 160')
    expect(sitemapSource).toContain('.filter(isIndexableAnime)')
    expect(sitemapSource).toContain('description: string | null')
    expect(sitemapSource).toContain('updated_at: string | null')
    expect(sitemapSource).toContain('lastmod: item.updated_at || item.air_date || undefined')
  })

  it('only grants indexing signals to detail pages with a substantive synopsis', () => {
    const animeSource = readSource('app/pages/anime/[id].vue')

    expect(isIndexableAnime({ description: '精簡介紹' })).toBe(false)
    expect(isIndexableAnime({ description: '作品介紹'.repeat(40) })).toBe(true)
    expect(animeSource).toContain("robots: () => isIndexablePage.value ? 'index, follow' : 'noindex, follow'")
  })

  it('escapes HTML delimiters in dynamic JSON-LD', () => {
    expect(serializeJsonLd({ name: '</script><script>alert(1)</script>' }))
      .toBe('{"name":"\\u003c/script>\\u003cscript>alert(1)\\u003c/script>"}')
  })
})
