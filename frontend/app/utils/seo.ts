export const SITE_URL = 'https://anime.kaistarstudio.me'

export function serializeJsonLd(value: unknown): string {
  return JSON.stringify(value).replace(/</g, '\\u003c')
}
