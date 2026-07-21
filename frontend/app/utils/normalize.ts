const WINDOWS_1252_BYTES: Record<string, number> = {
  '€': 0x80, '‚': 0x82, 'ƒ': 0x83, '„': 0x84, '…': 0x85, '†': 0x86, '‡': 0x87,
  'ˆ': 0x88, '‰': 0x89, 'Š': 0x8a, '‹': 0x8b, 'Œ': 0x8c, 'Ž': 0x8e, '‘': 0x91,
  '’': 0x92, '“': 0x93, '”': 0x94, '•': 0x95, '–': 0x96, '—': 0x97, '˜': 0x98,
  '™': 0x99, 'š': 0x9a, '›': 0x9b, 'œ': 0x9c, 'ž': 0x9e, 'Ÿ': 0x9f
}

function looksLikeMojibake(value: string): boolean {
  return /[ÃÂâåæçèéäãï]/.test(value)
}

function byteFromMojibakeChar(char: string): number {
  if (WINDOWS_1252_BYTES[char] !== undefined) return WINDOWS_1252_BYTES[char]
  return char.charCodeAt(0) & 0xff
}

export function repairText(value: unknown, fallback = ''): string {
  if (typeof value !== 'string' || value === '') return fallback
  if (!looksLikeMojibake(value)) return value

  try {
    const bytes = Uint8Array.from(Array.from(value), byteFromMojibakeChar)
    const decoded = new TextDecoder('utf-8', { fatal: false }).decode(bytes)
    return looksLikeMojibake(decoded) ? value : decoded || fallback
  } catch {
    return value
  }
}

export interface AnimeStream {
  region: string
  platform: string
  url: string | null
}

export interface AnimeTheme {
  type: string
  title: string
  artist: string
}

export interface AnimeTrailer {
  url: string
  thumbnail: string | null
}

export interface AnimeCastEntry {
  character: string
  actor: string
}

export interface AnimeStaffEntry {
  role: string
  name: string
}

export interface AnimeLink {
  category: string
  label: string
  url: string
}

export interface AnimeCardData {
  id: number
  name: string
  imageUrl: string
  airDate: string | null
  airDateText: string
  episodeCount: number | null
  tags: string[]
  streamCount: number
}

export interface AnimeSummary extends AnimeCardData {
  source: string
  seasonYear: number | null
  seasonCode: string
  status: string
  actors: string[]
}

export interface Anime extends AnimeCardData {
  description: string
  source: string
  seasonYear: number | null
  seasonCode: string
  status: string
  actors: string[]
  aliases: string[]
  streams: AnimeStream[]
  titleJa: string
  themes: AnimeTheme[]
  trailers: AnimeTrailer[]
  cast: AnimeCastEntry[]
  staff: AnimeStaffEntry[]
  links: AnimeLink[]
}

function asRecord(value: unknown): Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    ? value as Record<string, unknown>
    : {}
}

function stringValue(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback
}

function nullableString(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

function numberValue(value: unknown, fallback = 0): number {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : fallback
}

function nullableNumber(value: unknown): number | null {
  if (value === null || value === undefined || value === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

function arrayRecords(value: unknown): Record<string, unknown>[] {
  return Array.isArray(value) ? value.map(asRecord) : []
}

function stringArray(value: unknown): string[] {
  return Array.isArray(value)
    ? value.filter((entry): entry is string => typeof entry === 'string')
    : []
}

function normalizeAnimeCard(item: Record<string, unknown>): AnimeCardData {
  return {
    id: numberValue(item.id),
    name: repairText(item.name, '未命名作品'),
    imageUrl: stringValue(item.imageUrl) || stringValue(item.image_url),
    airDate: nullableString(item.airDate) ?? nullableString(item.air_date),
    airDateText: stringValue(item.airDateText) || stringValue(item.air_date_text),
    episodeCount: nullableNumber(item.episodeCount ?? item.episode_count),
    tags: stringArray(item.tags),
    streamCount: numberValue(
      item.streamCount ?? item.stream_count,
      Array.isArray(item.streams) ? item.streams.length : 0
    )
  }
}

export function normalizeAnime(value: unknown = {}): Anime {
  const item = asRecord(value)
  const cast = arrayRecords(item.cast)
  const titles = arrayRecords(item.titles)

  return {
    ...normalizeAnimeCard(item),
    description: repairText(item.description, '尚未整理作品介紹。'),
    source: stringValue(item.source),
    seasonYear: nullableNumber(item.seasonYear ?? item.season_year),
    seasonCode: stringValue(item.seasonCode) || stringValue(item.season_code),
    status: stringValue(item.status),
    actors: [...new Set(cast.map(entry => repairText(entry.actor)).filter(Boolean))],
    aliases: stringArray(item.aliases).map(alias => repairText(alias)),
    streams: arrayRecords(item.streams)
      .map(stream => ({
        region: repairText(stream.region),
        platform: repairText(stream.platform),
        url: nullableString(stream.url)
      })),
    titleJa: repairText(
      titles.find(title => title.locale === 'ja')?.title
    ),
    themes: arrayRecords(item.themes).map(theme => ({
      type: stringValue(theme.type),
      title: stringValue(theme.title),
      artist: stringValue(theme.artist)
    })),
    trailers: arrayRecords(item.trailers).map(trailer => ({
      url: stringValue(trailer.url),
      thumbnail: nullableString(trailer.thumbnail)
    })),
    cast: cast.map(entry => ({
      character: repairText(entry.character),
      actor: repairText(entry.actor)
    })),
    staff: arrayRecords(item.staff).map(member => ({
      role: repairText(member.role),
      name: repairText(member.name)
    })),
    links: arrayRecords(item.links).map(link => ({
      category: stringValue(link.category),
      label: stringValue(link.label),
      url: stringValue(link.url)
    }))
  }
}

export function normalizeAnimeSummary(value: unknown = {}): AnimeSummary {
  const item = asRecord(value)

  return {
    ...normalizeAnimeCard(item),
    source: stringValue(item.source),
    seasonYear: nullableNumber(item.seasonYear ?? item.season_year),
    seasonCode: stringValue(item.seasonCode) || stringValue(item.season_code),
    status: stringValue(item.status),
    actors: Array.isArray(item.actors)
      ? item.actors.map(actor => repairText(actor)).filter(Boolean)
      : []
  }
}

export interface Collection {
  id: number
  name: string
  isPublic: boolean
  publicSlug: string
  count: number
}

export function normalizeCollection(value: unknown = {}): Collection {
  const item = asRecord(value)

  return {
    id: numberValue(item.id),
    name: stringValue(item.name),
    isPublic: Boolean(item.is_public),
    publicSlug: stringValue(item.public_slug),
    count: numberValue(item.count),
  }
}

export interface ListItem {
  id: number
  watched: boolean
  rating: number | null
  note: string
  createdAt: string
  updatedAt: string
  collections: { id: number; name: string }[]
  anime: Anime
}

export function normalizeListItem(value: unknown = {}): ListItem {
  const item = asRecord(value)

  return {
    id: numberValue(item.id),
    watched: Boolean(item.watched),
    rating: nullableNumber(item.rating),
    note: repairText(item.note),
    createdAt: stringValue(item.createdAt) || stringValue(item.created_at),
    updatedAt: stringValue(item.updatedAt) || stringValue(item.updated_at),
    collections: arrayRecords(item.collections).map(collection => ({
      id: numberValue(collection.id),
      name: stringValue(collection.name)
    })),
    anime: normalizeAnime(item.anime)
  }
}

// Source/type tags (種類) get a fixed neutral palette so they read as a
// distinct category from genre tags, independent of season contents.
const SOURCE_TAG_COLORS: Record<string, { bg: string; text: string }> = {
  '新作': { bg: '#dbeafe', text: '#1d4ed8' },
  '續作': { bg: '#e0e7ff', text: '#4338ca' },
  '跨季續播': { bg: '#e0e7ff', text: '#4338ca' },
  '漫畫改編': { bg: '#fef3c7', text: '#b45309' },
  '小說改編': { bg: '#fde68a', text: '#92400e' },
  '遊戲改編': { bg: '#dcfce7', text: '#15803d' },
  '原創作品': { bg: '#fee2e2', text: '#b91c1c' },
  '改編作品': { bg: '#fef9c3', text: '#a16207' },
}

function hashString(value: string): number {
  let hash = 0
  for (let i = 0; i < value.length; i++) {
    hash = (hash << 5) - hash + value.charCodeAt(i)
    hash |= 0
  }
  return Math.abs(hash)
}

// Deterministic color per tag string, stable across seasons/renders since
// each season's genre mix differs but a given tag (e.g. "奇幻") should always
// look the same to the user. Source tags use a fixed palette; everything
// else is spread around the hue wheel via hashing for a muted rainbow effect
// — low saturation keeps a dense row of chips calm instead of clashing.
export function tagColor(tag: string): { bg: string; text: string } {
  const source = SOURCE_TAG_COLORS[tag]
  if (source) return source

  const hue = hashString(tag) % 360
  return {
    bg: `hsl(${hue}, 45%, 96%)`,
    // Keep small chips above WCAG AA contrast across the full hue wheel.
    text: `hsl(${hue}, 45%, 28%)`,
  }
}
