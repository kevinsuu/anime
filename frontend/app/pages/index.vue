<script setup lang="ts">
import SeasonalPage from './seasonal.vue'
import { isSeasonSelection } from '../utils/season'
import { serializeJsonLd, SITE_URL } from '../utils/seo'

const route = useRoute()
const hasExplicitSeasonQuery = computed(() => isSeasonSelection(route.query.year, route.query.season))

useHead(() => {
  if (hasExplicitSeasonQuery.value) return { script: [] }

  return {
    script: [{
      key: 'website-identity',
      type: 'application/ld+json',
      innerHTML: serializeJsonLd({
        '@context': 'https://schema.org',
        '@graph': [
          {
            '@type': 'Organization',
            '@id': `${SITE_URL}/#organization`,
            name: '動漫庫',
            alternateName: 'Anime Library',
            url: `${SITE_URL}/`,
            logo: {
              '@type': 'ImageObject',
              url: `${SITE_URL}/animelibrary.png`
            }
          },
          {
            '@type': 'WebSite',
            '@id': `${SITE_URL}/#website`,
            name: '動漫庫',
            alternateName: 'Anime Library',
            url: `${SITE_URL}/`,
            description: '每季動畫新番表、歷年動漫資料與個人追番收藏服務。',
            inLanguage: 'zh-Hant',
            publisher: { '@id': `${SITE_URL}/#organization` },
            potentialAction: {
              '@type': 'SearchAction',
              target: `${SITE_URL}/catalog?q={search_term_string}`,
              'query-input': 'required name=search_term_string'
            }
          }
        ]
      })
    }]
  }
})
</script>

<template>
  <SeasonalPage homepage />
</template>
