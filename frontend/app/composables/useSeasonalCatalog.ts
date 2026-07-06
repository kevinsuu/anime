import { computed, reactive } from 'vue'
import type { Anime } from '../utils/normalize'

export const weekdayTabs = [
  { key: 'all', label: '全部', dayIndex: null },
  { key: 'mon', label: '一', dayIndex: 1 },
  { key: 'tue', label: '二', dayIndex: 2 },
  { key: 'wed', label: '三', dayIndex: 3 },
  { key: 'thu', label: '四', dayIndex: 4 },
  { key: 'fri', label: '五', dayIndex: 5 },
  { key: 'sat', label: '六', dayIndex: 6 },
  { key: 'sun', label: '日', dayIndex: 0 },
]

// Tags that indicate source/type (種類)
export const SOURCE_TAGS = new Set(['新作', '續作', '漫畫改編', '小說改編', '原創作品', '遊戲改編', '跨季續播'])

// True for tags that represent an actual genre/theme (e.g. 戀愛/戰鬥/搞笑),
// excluding source/type tags (新作/漫畫改編/…) and season-count tags (e.g. "2季度")
// — both of which are metadata about the work, not a genre a user would filter by.
export function isGenreTag(tag: string): boolean {
  return !SOURCE_TAGS.has(tag) && !tag.match(/^\d+季度/)
}

// Map Chinese weekday char to JS getDay() index (0=Sun)
const WEEKDAY_CHAR_TO_INDEX: Record<string, number> = {
  '日': 0, '一': 1, '二': 2, '三': 3, '四': 4, '五': 5, '六': 6,
}

export function weekdayIndexOf(anime: Anime): number | null {
  // Prefer air_date_text which explicitly states the weekly broadcast day
  // e.g. "7月7日起／每週二／20時30分" → 二 → 2
  // Must match 每週X or 週X to avoid matching 日期 like "7月5日" → "日"
  if (anime.airDateText) {
    const m = anime.airDateText.match(/每週([一二三四五六日])|週([一二三四五六日])/)
    if (m) return WEEKDAY_CHAR_TO_INDEX[m[1] || m[2]] ?? null
  }
  // Fallback: derive from air_date, parse as local date to avoid UTC-shift
  if (anime.airDate) {
    const [y, mo, d] = anime.airDate.split('-').map(Number)
    if (y && mo && d) return new Date(y, mo - 1, d).getDay()
  }
  return null
}

// Derive dynamic filter options from the loaded anime list
export function deriveFilterOptions(animeList: Anime[]) {
  const sourceCounts: Record<string, number> = {}
  const genreCounts: Record<string, number> = {}
  const actorCounts: Record<string, number> = {}

  for (const anime of animeList) {
    for (const tag of anime.tags) {
      if (SOURCE_TAGS.has(tag)) {
        sourceCounts[tag] = (sourceCounts[tag] ?? 0) + 1
      } else if (isGenreTag(tag)) {
        genreCounts[tag] = (genreCounts[tag] ?? 0) + 1
      }
    }
    for (const c of anime.cast) {
      if (c.actor && c.actor !== '？？？') {
        actorCounts[c.actor] = (actorCounts[c.actor] ?? 0) + 1
      }
    }
  }

  const sources = Object.entries(sourceCounts)
    .sort((a, b) => b[1] - a[1])
    .map(([tag, count]) => ({ tag, count }))

  const genres = Object.entries(genreCounts)
    .sort((a, b) => b[1] - a[1])
    .map(([tag, count]) => ({ tag, count }))

  const actors = Object.entries(actorCounts)
    .sort((a, b) => b[1] - a[1])
    .filter(([, count]) => count >= 2)
    .slice(0, 20)
    .map(([actor, count]) => ({ actor, count }))

  return { sources, genres, actors }
}

export function useSeasonalCatalog() {
  const state = reactive({
    weekday: 'all',
    sourceTag: '',
    genreTags: [] as string[],  // multi-select, OR logic
    actor: '',
    seasonalStatus: 'all',
  })

  function toggleGenreTag(tag: string) {
    const idx = state.genreTags.indexOf(tag)
    if (idx >= 0) state.genreTags.splice(idx, 1)
    else state.genreTags.push(tag)
  }

  function filterSeasonal(seasonal: Anime[], listByAnimeId: Map<number, { watched: boolean }>) {
    const activeWeekday = weekdayTabs.find(w => w.key === state.weekday) ?? weekdayTabs[0]

    return seasonal.filter(anime => {
      if (activeWeekday.dayIndex !== null && weekdayIndexOf(anime) !== activeWeekday.dayIndex) return false
      if (state.sourceTag && !anime.tags.includes(state.sourceTag)) return false
      // OR logic: anime must have at least one of the selected genre tags
      if (state.genreTags.length > 0 && !state.genreTags.some(t => anime.tags.includes(t))) return false
      if (state.actor && !anime.cast.some(c => c.actor === state.actor)) return false

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
    if (state.sourceTag) count++
    if (state.genreTags.length > 0) count++
    if (state.actor) count++
    if (state.seasonalStatus !== 'all') count++
    return count
  })

  function resetFilters() {
    state.sourceTag = ''
    state.genreTags = []
    state.actor = ''
    state.seasonalStatus = 'all'
  }

  return { state, filterSeasonal, activeFilterCount, resetFilters, toggleGenreTag }
}
