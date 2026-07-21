export type ApiPayload = Record<string, unknown>

export interface ApiUser extends ApiPayload {
  id: number
  email?: string
  display_name?: string
  avatar_url?: string
  public_slug?: string
}

export interface AuthResponse {
  token: string
  refreshToken: string
  user: ApiUser
  expiresIn: number
}

export interface OkResponse {
  ok: boolean
}

export interface ItemResponse<T> {
  item: T
}

export interface ItemsResponse<T> {
  items: T[]
}

export interface UserResponse {
  user: ApiUser
}

export interface AnimeSummaryFilters {
  year?: number | string
  season?: string
  tags?: string[]
  page?: number
  perPage?: number
}

export interface AnimeSummaryMeta {
  page: number
  per_page: number
  total: number
  last_page: number
  has_more: boolean
}

export interface AnimeSummaryResponse extends ItemsResponse<ApiPayload> {
  meta: AnimeSummaryMeta
}

export type AnimeListSort = 'airDate' | 'year' | 'added'
export type AnimeListStatus = 'all' | 'watched' | 'unwatched'

export interface AnimeListFilters {
  page?: number
  q?: string
  tags?: string[]
  status?: AnimeListStatus
  collectionId?: number
  sort?: AnimeListSort
}

export interface AnimeListResponse extends ItemsResponse<ApiPayload> {
  meta: AnimeSummaryMeta
}

export interface AnimeListCountsResponse {
  counts: Record<AnimeListStatus, number>
}

export interface TagCountPayload {
  tag: string
  count: number
}

export interface TagsResponse {
  tags: TagCountPayload[]
}

export interface ListItemPatch {
  watched?: boolean
  rating?: number | null
  note?: string
}

export interface CollectionPatch {
  name?: string
  is_public?: boolean
}

export interface CollectionPayload extends ApiPayload {
  id: number
  name: string
  is_public: boolean
  public_slug: string
  count: number
}

export interface AnimeCardBootstrapStatusPayload {
  anime_id: number
  list_item_id: number
  watched: boolean
  collection_ids: number[]
}

export interface AnimeCardBootstrapResponse {
  user: ApiUser
  statuses: AnimeCardBootstrapStatusPayload[]
  collections: CollectionPayload[]
}

export interface PublicListResponse extends ItemsResponse<ApiPayload> {
  user: ApiUser
}

export interface PublicCollectionAnimePayload {
  id: number
  name: string
  image_url: string
  season_year: number | null
  season_code: string
}

export interface PublicCollectionListItemPayload {
  id: number
  watched: boolean
  rating: number | null
  anime: PublicCollectionAnimePayload
}

export interface PublicCollectionPayload extends CollectionPayload {
  list_items: PublicCollectionListItemPayload[]
}
