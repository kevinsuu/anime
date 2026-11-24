export type ClientErrorKind = 'error' | 'unhandledrejection' | 'vue'

export interface ClientErrorPayload {
  kind: ClientErrorKind
  message: string
  page: string
  source?: string
  line?: number
  column?: number
  stack?: string
  release?: string
}

export interface ClientErrorLocation {
  source?: string
  line?: number
  column?: number
}

interface ReporterOptions {
  send: (payload: ClientErrorPayload) => void
  /** Per page load, so a looping error cannot flood the server. */
  maxReports: number
  page: () => string
  release?: string
}

// Field limits mirror the backend validation in ClientErrorController.
const MESSAGE_MAX = 500
const SOURCE_MAX = 500
const PAGE_MAX = 300
const STACK_MAX = 4000

// Errors that are not ours (extensions) or are harmless browser notices.
const IGNORED_SOURCE = /^(chrome|moz|safari(-web)?)-extension:/
const IGNORED_MESSAGE = /ResizeObserver loop/

function describe(reason: unknown): { message: string; stack?: string } {
  if (reason instanceof Error) {
    return { message: `${reason.name}: ${reason.message}`, stack: reason.stack }
  }
  if (typeof reason === 'string') return { message: reason }
  try {
    return { message: JSON.stringify(reason) ?? String(reason) }
  } catch {
    return { message: String(reason) }
  }
}

export function createClientErrorReporter(options: ReporterOptions) {
  const seen = new Set<string>()
  let sent = 0

  function report(kind: ClientErrorKind, reason: unknown, location: ClientErrorLocation = {}) {
    try {
      if (sent >= options.maxReports) return
      const { message, stack } = describe(reason)
      if (IGNORED_MESSAGE.test(message)) return
      if (location.source && IGNORED_SOURCE.test(location.source)) return

      const fingerprint = `${kind}|${message}|${location.source ?? ''}|${location.line ?? ''}`
      if (seen.has(fingerprint)) return
      seen.add(fingerprint)
      sent += 1

      options.send({
        kind,
        message: message.slice(0, MESSAGE_MAX),
        page: options.page().slice(0, PAGE_MAX),
        source: location.source?.slice(0, SOURCE_MAX),
        line: location.line,
        column: location.column,
        stack: stack?.slice(0, STACK_MAX),
        release: options.release
      })
    } catch {
      // Reporting must never become a new source of errors.
    }
  }

  return { report }
}
