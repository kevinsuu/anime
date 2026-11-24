import { describe, expect, it, vi } from 'vitest'
import { createClientErrorReporter } from '../app/utils/clientErrorReporter'

function setup(maxReports = 5) {
  const send = vi.fn()
  const reporter = createClientErrorReporter({ send, maxReports, page: () => '/seasonal', release: 'v1' })
  return { send, reporter }
}

describe('client error reporter', () => {
  it('sends an Error with its message, stack and page', () => {
    const { send, reporter } = setup()
    const error = new TypeError('boom')

    reporter.report('error', error, { source: 'https://site/_nuxt/a.js', line: 3, column: 7 })

    expect(send).toHaveBeenCalledOnce()
    expect(send.mock.calls[0]![0]).toMatchObject({
      kind: 'error',
      message: 'TypeError: boom',
      page: '/seasonal',
      source: 'https://site/_nuxt/a.js',
      line: 3,
      column: 7,
      release: 'v1'
    })
    expect(send.mock.calls[0]![0].stack).toContain('boom')
  })

  it('describes non-Error rejection reasons', () => {
    const { send, reporter } = setup()

    reporter.report('unhandledrejection', 'plain string')
    reporter.report('unhandledrejection', { code: 42 })
    reporter.report('unhandledrejection', undefined)

    expect(send.mock.calls.map(call => call[0].message)).toEqual(['plain string', '{"code":42}', 'undefined'])
  })

  it('reports the same error only once per page load', () => {
    const { send, reporter } = setup()

    reporter.report('vue', new Error('same'))
    reporter.report('vue', new Error('same'))

    expect(send).toHaveBeenCalledOnce()
  })

  it('stops after maxReports so a looping error cannot flood the server', () => {
    const { send, reporter } = setup(2)

    for (let i = 0; i < 5; i++) reporter.report('error', new Error(`e${i}`))

    expect(send).toHaveBeenCalledTimes(2)
  })

  it('ignores browser-extension scripts and benign ResizeObserver warnings', () => {
    const { send, reporter } = setup()

    reporter.report('error', new Error('ext'), { source: 'chrome-extension://abc/content.js' })
    reporter.report('error', 'ResizeObserver loop completed with undelivered notifications.')

    expect(send).not.toHaveBeenCalled()
  })

  it('truncates long fields to what the API accepts', () => {
    const { send, reporter } = setup()
    const error = new Error('x'.repeat(2000))
    error.stack = 'y'.repeat(10_000)

    reporter.report('error', error)

    const payload = send.mock.calls[0]![0]
    expect(payload.message.length).toBeLessThanOrEqual(500)
    expect(payload.stack.length).toBeLessThanOrEqual(4000)
  })

  it('never throws even if sending fails', () => {
    const reporter = createClientErrorReporter({
      send: () => { throw new Error('network') },
      maxReports: 5,
      page: () => '/'
    })

    expect(() => reporter.report('error', new Error('x'))).not.toThrow()
  })
})
