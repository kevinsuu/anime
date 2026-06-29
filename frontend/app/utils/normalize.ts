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

export interface Anime {
  id: number
  name: string
  description: string
  imageUrl: string
  source: string
  seasonYear: number | null
  seasonCode: string
  airDate: string | null
  episodeCount: number | null
  status: string
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
    episodeCount: item.episodeCount || item.episode_count || null,
    status: item.status || ''
  }
}

export interface ListItem {
  id: number
  watched: boolean
  rating: number | null
  note: string
  createdAt: string
  updatedAt: string
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
    anime: normalizeAnime(item.anime || {})
  }
}
