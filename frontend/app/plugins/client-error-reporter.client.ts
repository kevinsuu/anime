import { createClientErrorReporter } from '../utils/clientErrorReporter'

const MAX_REPORTS_PER_PAGE_LOAD = 5

/**
 * Sends uncaught JavaScript errors to the backend log (client-errors-*.log),
 * so problems visitors hit in production are visible without a bug report.
 * Reports are anonymous: no user id, email or token is included.
 */
export default defineNuxtPlugin((nuxtApp) => {
  const config = useRuntimeConfig()
  const endpoint = `${config.public.apiBaseUrl}/client-errors`

  const reporter = createClientErrorReporter({
    maxReports: MAX_REPORTS_PER_PAGE_LOAD,
    page: () => window.location.pathname,
    release: String(config.public.appVersion || 'dev'),
    send(payload) {
      // keepalive lets the report finish even if the page is navigating away.
      void fetch(endpoint, {
        method: 'POST',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).catch(() => {})
    }
  })

  // Bubbling listener only: failed <img>/<script> loads do not bubble, so
  // missing covers (already handled by a placeholder) are not reported.
  window.addEventListener('error', (event) => {
    reporter.report('error', event.error ?? event.message, {
      source: event.filename || undefined,
      line: event.lineno || undefined,
      column: event.colno || undefined
    })
  })
  window.addEventListener('unhandledrejection', (event) => {
    reporter.report('unhandledrejection', event.reason)
  })
  nuxtApp.hook('vue:error', (error) => {
    reporter.report('vue', error)
  })
})
