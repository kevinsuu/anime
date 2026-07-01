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

export interface AnimeExternalId {
  provider: string
  external_id: string
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

export interface Anime {
  id: number
  name: string
  description: string
  imageUrl: string
  source: string
  seasonYear: number | null
  seasonCode: string
  airDate: string | null
  airDateText: string
  episodeCount: number | null
  status: string
  tags: string[]
  aliases: string[]
  streams: AnimeStream[]
  titleJa: string
  externalIds: AnimeExternalId[]
  themes: AnimeTheme[]
  trailers: AnimeTrailer[]
  cast: AnimeCastEntry[]
  staff: AnimeStaffEntry[]
  links: AnimeLink[]
}

export function normalizeAnime(item: Record<string, any> = {}): Anime {
  return {
    id: item.id,
    name: repairText(item.name, '未命名作品'),
    description: repairText(item.description, '尚未整理作品介紹。'),
    imageUrl: item.imageUrl || item.image_url || '',
    source: item.source || '',
    seasonYear: item.seasonYear || item.season_year || null,
    seasonCode: item.seasonCode || item.season_code || '',
    airDate: item.airDate || item.air_date || null,
    airDateText: item.airDateText || item.air_date_text || '',
    episodeCount: item.episodeCount || item.episode_count || null,
    status: item.status || '',
    tags: Array.isArray(item.tags) ? item.tags : [],
    aliases: Array.isArray(item.aliases) ? item.aliases.map((a: any) => repairText(a)) : [],
    streams: Array.isArray(item.streams)
      ? item.streams.map((s: any) => ({
          region: repairText(s.region),
          platform: repairText(s.platform),
          url: s.url || null
        }))
      : [],
    titleJa: repairText(
      (Array.isArray(item.titles) ? item.titles.find((t: any) => t.locale === 'ja')?.title : '') || ''
    ),
    externalIds: Array.isArray(item.external_ids)
      ? item.external_ids.map((e: any) => ({
          provider: e.provider,
          external_id: e.external_id,
          url: e.url || null
        }))
      : [],
    themes: Array.isArray(item.themes)
      ? item.themes.map((t: any) => ({ type: t.type || '', title: t.title || '', artist: t.artist || '' }))
      : [],
    trailers: Array.isArray(item.trailers)
      ? item.trailers.map((t: any) => ({ url: t.url || '', thumbnail: t.thumbnail || null }))
      : [],
    cast: Array.isArray(item.cast)
      ? item.cast.map((c: any) => ({ character: repairText(c.character), actor: repairText(c.actor) }))
      : [],
    staff: Array.isArray(item.staff)
      ? item.staff.map((s: any) => ({ role: repairText(s.role), name: repairText(s.name) }))
      : [],
    links: Array.isArray(item.links)
      ? item.links.map((l: any) => ({ category: l.category || '', label: l.label || '', url: l.url || '' }))
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

export function normalizeCollection(item: Record<string, any> = {}): Collection {
  return {
    id: item.id,
    name: item.name || '',
    isPublic: Boolean(item.is_public),
    publicSlug: item.public_slug || '',
    count: item.count ?? 0,
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

export function normalizeListItem(item: Record<string, any> = {}): ListItem {
  return {
    id: item.id,
    watched: Boolean(item.watched),
    rating: item.rating === null || item.rating === undefined ? null : Number(item.rating),
    note: repairText(item.note || ''),
    createdAt: item.createdAt || item.created_at || '',
    updatedAt: item.updatedAt || item.updated_at || '',
    collections: Array.isArray(item.collections)
      ? item.collections.map((c: any) => ({ id: c.id, name: c.name }))
      : [],
    anime: normalizeAnime(item.anime || {})
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
    text: `hsl(${hue}, 35%, 40%)`,
  }
}
