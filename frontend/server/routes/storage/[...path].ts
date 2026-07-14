import { createError, getRequestURL, getRouterParam, proxyRequest } from 'h3'

export default defineEventHandler((event) => {
  const config = useRuntimeConfig()
  const path = getRouterParam(event, 'path') || ''

  if (!path) {
    throw createError({ statusCode: 404, statusMessage: 'Storage file not found' })
  }

  const baseUrl = String(config.apiBaseUrlInternal).replace(/\/$/, '')
  const safePath = path.split('/').map(encodeURIComponent).join('/')
  const target = `${baseUrl}/storage/${safePath}${getRequestURL(event).search}`

  return proxyRequest(event, target)
})
