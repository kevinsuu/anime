import { createError, setHeader } from 'h3'

// IndexNow verifies ownership by fetching this file and comparing its body to
// the key in each submission (backend `anime:submit-indexnow`). It lives at the
// site root because IndexNow only accepts URLs at or below the key location.
export default defineEventHandler((event) => {
  const key = String(useRuntimeConfig().indexnowKey || '').trim()
  if (!key) {
    throw createError({ statusCode: 404, statusMessage: 'Not Found' })
  }

  setHeader(event, 'Content-Type', 'text/plain; charset=utf-8')
  return key
})
