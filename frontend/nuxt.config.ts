const defaultApiBaseUrl = 'https://anime.kaistarstudio.me/api'
const publicApiBaseUrl = (process.env.NUXT_PUBLIC_API_BASE_URL || defaultApiBaseUrl).replace(/\/$/, '')
const internalApiBaseUrl = (process.env.NUXT_API_BASE_URL_INTERNAL || publicApiBaseUrl).replace(/\/$/, '')
const publicRouteRules = process.env.NODE_ENV === 'development'
  ? {}
  : {
      '/': { swr: 300 },
      '/seasonal': { swr: 300 },
      '/catalog': { swr: 300 },
      '/anime/**': { swr: 300 }
    }

export default defineNuxtConfig({
  compatibilityDate: '2026-06-29',
  devtools: { enabled: true },
  ssr: true,
  modules: ['@nuxt/ui', '@nuxtjs/sitemap'],
  css: ['~/assets/css/main.css'],
  // The whole UI is designed for light mode; pin color-mode to light so
  // Nuxt UI doesn't add `.dark` to <html> when the OS is in dark mode
  // (which turned the browser's overscroll canvas navy).
  colorMode: {
    preference: 'light',
    fallback: 'light'
  },
  site: {
    url: 'https://anime.kaistarstudio.me',
    name: '動漫庫'
  },
  sitemap: {
    sources: ['/api/__sitemap__/anime-urls'],
    exclude: ['/seasonal', '/list', '/list/**', '/settings', '/login']
  },
  routeRules: publicRouteRules,
  nitro: {
    // Emit Brotli/gzip variants for immutable JS/CSS assets. Production nginx
    // can serve these directly, and the built-in preview server stays
    // representative when running throttled performance checks.
    compressPublicAssets: true
  },
  app: {
    head: {
      title: '動漫庫',
      htmlAttrs: { lang: 'zh-Hant' },
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1.0' },
        { name: 'description', content: '動漫庫｜動畫新番表、動漫資料庫，追蹤每季新番與經典動畫作品。' },
        { property: 'og:site_name', content: '動漫庫' },
        { name: 'google-site-verification', content: 'KnLHCa7un3sS1ij4wsVsfqFSRwYmZBvewLOvZfD-u_4' }
      ],
      link: [
        { rel: 'icon', type: 'image/png', sizes: '32x32', href: '/favicon-32.png' },
        { rel: 'apple-touch-icon', sizes: '180x180', href: '/favicon-180.png' }
      ]
    }
  },
  runtimeConfig: {
    // A standalone `npm run dev` uses the deployed API, so frontend work does
    // not require Laravel/MySQL locally. Docker Compose still overrides these
    // values with its backend service URL for full-stack development.
    apiBaseUrlInternal: internalApiBaseUrl,
    public: {
      apiBaseUrl: publicApiBaseUrl,
      googleClientId: process.env.NUXT_PUBLIC_GOOGLE_CLIENT_ID || '',
      enableDevLogin: process.env.NUXT_PUBLIC_ENABLE_DEV_LOGIN === 'true'
    }
  }
})
