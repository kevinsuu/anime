<script setup lang="ts">
import SeasonalPage from './seasonal.vue'
import { isSeasonSelection } from '../utils/season'

const route = useRoute()
const hasExplicitSeasonQuery = computed(() => isSeasonSelection(route.query.year, route.query.season))

useHead(() => {
  if (hasExplicitSeasonQuery.value) return { script: [] }

  return {
    script: [{
      key: 'website-identity',
      type: 'application/ld+json',
      innerHTML: JSON.stringify({
        '@context': 'https://schema.org',
        '@type': 'WebSite',
        name: '動漫庫',
        alternateName: 'Anime Library',
        url: 'https://anime.kaistarstudio.me/'
      })
    }]
  }
})
</script>

<template>
  <SeasonalPage homepage />
</template>
