// Keep these thresholds in sync with backend App\Services\Seo\AnimeIndexability,
// which decides what IndexNow pushes.
const STANDALONE_DESCRIPTION_LENGTH = 160
const SUPPORTED_DESCRIPTION_LENGTH = 60
const MINIMUM_SUPPORTING_FACTS = 2
const MINIMUM_CAST_FOR_FACT = 3

export interface IndexableAnime {
  description: string | null | undefined
  episodeCount: number | null | undefined
  streamCount: number
  castCount: number
}

/**
 * Detail pages with only aliases, a placeholder, or a very short excerpt do
 * not give search users enough standalone information. Keep them available to
 * visitors, but reserve crawl and indexing signals for pages that either have
 * a readable synopsis, or a shorter one backed by first-party facts the page
 * shows (episode count, where to watch, voice cast) — the verifiable details
 * answer engines quote.
 */
export function isIndexableAnime(anime: IndexableAnime): boolean {
  const descriptionLength = anime.description?.replace(/\s+/g, '').length ?? 0
  if (descriptionLength >= STANDALONE_DESCRIPTION_LENGTH) return true
  if (descriptionLength < SUPPORTED_DESCRIPTION_LENGTH) return false

  const supportingFacts = [
    Boolean(anime.episodeCount && anime.episodeCount > 0),
    anime.streamCount > 0,
    anime.castCount >= MINIMUM_CAST_FOR_FACT
  ].filter(Boolean).length
  return supportingFacts >= MINIMUM_SUPPORTING_FACTS
}
