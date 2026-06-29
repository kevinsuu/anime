import { computed, reactive } from 'vue'
import type { Anime } from '../utils/normalize'

export interface GenreCategory {
  key: string
  label: string
  terms: string[]
}

export const genreCategories: GenreCategory[] = [
  { key: 'all', label: '全部分類', terms: [] },
  { key: 'fantasy', label: '奇幻異世界', terms: ['奇幻', '異世界', '魔法', '冒險', '勇者', '幻想', 'fantasy', 'adventure'] },
  { key: 'romance', label: '戀愛青春', terms: ['戀愛', '青春', '愛情', '青梅竹馬', 'romance', 'love'] },
  { key: 'school', label: '校園社團', terms: ['校園', '學園', '高中', '社團', '學生', 'school', 'club'] },
  { key: 'action', label: '動作戰鬥', terms: ['動作', '戰鬥', '格鬥', '戰爭', '英雄', 'action', 'battle'] },
  { key: 'daily', label: '日常喜劇', terms: ['日常', '喜劇', '搞笑', '生活', 'comedy', 'slice of life'] },
  { key: 'sci-fi', label: '科幻機戰', terms: ['科幻', '機戰', '機器人', '未來', '宇宙', 'sci-fi', 'science fiction', 'robot'] }
]

export const weekdayTabs = [
  { key: 'all', label: '全部', dayIndex: null },
  { key: 'mon', label: '一', dayIndex: 1 },
  { key: 'tue', label: '二', dayIndex: 2 },
  { key: 'wed', label: '三', dayIndex: 3 },
  { key: 'thu', label: '四', dayIndex: 4 },
  { key: 'fri', label: '五', dayIndex: 5 },
  { key: 'sat', label: '六', dayIndex: 6 },
  { key: 'sun', label: '日', dayIndex: 0 }
]

export function matchesAnimeCategory(anime: Anime, category: GenreCategory): boolean {
  if (category.key === 'all') return true
  const searchableText = [anime.name, anime.description, anime.source, anime.status]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()

  return category.terms.some(term => searchableText.includes(term.toLowerCase()))
}

export function weekdayIndexOf(anime: Anime): number | null {
  if (!anime.airDate) return null
  const date = new Date(anime.airDate)
  return Number.isNaN(date.getTime()) ? null : date.getDay()
}

export function useSeasonalCatalog() {
  const state = reactive({
    seasonalCategory: 'all',
    seasonalStatus: 'all',
    weekday: 'all'
  })

  function filterSeasonal(seasonal: Anime[], listByAnimeId: Map<number, { watched: boolean }>) {
    const selectedCategory = genreCategories.find(c => c.key === state.seasonalCategory) || genreCategories[0]
    const activeWeekday = weekdayTabs.find(w => w.key === state.weekday) || weekdayTabs[0]

    return seasonal.filter(anime => {
      if (!matchesAnimeCategory(anime, selectedCategory)) return false
      if (activeWeekday.dayIndex !== null && weekdayIndexOf(anime) !== activeWeekday.dayIndex) return false

      const listItem = listByAnimeId.get(anime.id)
      if (state.seasonalStatus === 'listed') return Boolean(listItem)
      if (state.seasonalStatus === 'unlisted') return !listItem
      if (state.seasonalStatus === 'watched') return Boolean(listItem?.watched)
      if (state.seasonalStatus === 'queued') return Boolean(listItem && !listItem.watched)
      if (state.seasonalStatus === 'with-cover') return Boolean(anime.imageUrl)
      return true
    })
  }

  const activeFilterCount = computed(() => {
    let count = 0
    if (state.seasonalCategory !== 'all') count += 1
    if (state.seasonalStatus !== 'all') count += 1
    return count
  })

  return { state, filterSeasonal, activeFilterCount }
}
