import { createError, getRequestURL, getRouterParam, proxyRequest } from 'h3'

export default defineEventHandler((event) => {
  const config = useRuntimeConfig()
  const path = getRouterParam(event, 'path') || ''

  if (!path) {
    throw createError({ statusCode: 404, statusMessage: 'Storage file not found' })
  }

  // API bases conventionally end in `/api`, while Laravel's public storage is
  // mounted at the site root. This keeps relative cover URLs working both with
  // the deployed API and with a local backend override.
  const baseUrl = String(config.apiBaseUrlInternal).replace(/\/$/, '').replace(/\/api$/, '')
  const safePath = path.split('/').map(encodeURIComponent).join('/')
  const target = `${baseUrl}/storage/${safePath}${getRequestURL(event).search}`

  return proxyRequest(event, target)
})
