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
  <div class="space-y-4 md:space-y-5">
    <section aria-labelledby="site-home-title" class="border-b border-gray-200 pb-4 md:flex md:items-end md:justify-between md:gap-8 md:pb-5">
      <div>
        <p class="text-[11px] font-extrabold uppercase tracking-[0.2em] text-primary-700">Anime Library</p>
        <h1 id="site-home-title" class="mt-1 text-3xl font-black tracking-tight text-gray-950">動漫庫</h1>
      </div>
      <p class="mt-2 max-w-xl text-sm font-medium leading-6 text-gray-600 md:mt-0 md:text-right">
        查找每季新番播出時間，瀏覽動畫、角色與聲優資料，收藏下一部想追的作品。
      </p>
    </section>

    <SeasonalPage homepage />
  </div>
</template>
