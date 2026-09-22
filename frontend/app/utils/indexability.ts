const MINIMUM_INDEXABLE_DESCRIPTION_LENGTH = 160

export interface IndexableAnime {
  description: string | null | undefined
}

/**
 * Detail pages with only aliases, a placeholder, or a very short excerpt do
 * not give search users enough standalone information. Keep them available to
 * visitors, but reserve crawl and indexing signals for pages with a readable
 * synopsis.
 */
export function isIndexableAnime(anime: IndexableAnime): boolean {
  const description = anime.description?.replace(/\s+/g, '') ?? ''
  return description.length >= MINIMUM_INDEXABLE_DESCRIPTION_LENGTH
}
