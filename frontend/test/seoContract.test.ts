import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const readSource = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('site identity SEO contract', () => {
  it('identifies the root URL as the branded homepage', () => {
    const indexSource = readSource('app/pages/index.vue')
    const seasonalSource = readSource('app/pages/seasonal.vue')

    expect(indexSource).toContain("'@type': 'WebSite'")
    expect(indexSource).toContain("name: '動漫庫'")
    expect(indexSource).toContain("alternateName: 'Anime Library'")
    expect(indexSource).toContain("url: 'https://anime.kaistarstudio.me/'")
    expect(indexSource).toContain('<SeasonalPage homepage />')
    expect(indexSource).toContain('id="site-home-title"')
    expect(indexSource).toContain('查找每季新番播出時間')
    expect(seasonalSource).toContain("'動漫庫｜動畫新番表、動漫資料庫與追番收藏'")
    expect(seasonalSource).toContain("'https://anime.kaistarstudio.me/'")
    expect(seasonalSource).toContain(':is="props.homepage ? \'h2\' : \'h1\'"')
  })

  it('keeps seasonal canonical URLs under the root URL used by navigation', () => {
    const seasonalSource = readSource('app/pages/seasonal.vue')
    const sitemapSource = readSource('server/api/__sitemap__/anime-urls.ts')

    expect(seasonalSource).toContain('https://anime.kaistarstudio.me/?year=')
    expect(sitemapSource).toContain('loc: `/?year=${year}&season=${season}`')
    expect(seasonalSource).not.toContain('https://anime.kaistarstudio.me/seasonal?year=')
  })

  it('includes the homepage and excludes the duplicate seasonal path from sitemap discovery', () => {
    const configSource = readSource('nuxt.config.ts')

    expect(configSource).toContain("exclude: ['/seasonal', '/list', '/list/**', '/settings', '/login']")
    expect(configSource).not.toContain("exclude: ['/',")
    expect(configSource).toContain("{ property: 'og:site_name', content: '動漫庫' }")
  })
})
