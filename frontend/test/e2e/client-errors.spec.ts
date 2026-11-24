import { expect, test } from '@playwright/test'

test('uncaught browser errors are reported once to the backend', async ({ page }) => {
  const reports: Record<string, unknown>[] = []
  await page.route('**/client-errors', async (route) => {
    reports.push(route.request().postDataJSON() as Record<string, unknown>)
    await route.fulfill({ status: 204 })
  })

  await page.goto('/seasonal?year=2026&season=summer', { waitUntil: 'domcontentloaded' })
  await expect(page.locator('#main-content a[href^="/anime/"]').first()).toBeVisible()

  await page.evaluate(() => {
    // The same error from the same place twice must be reported once.
    const boom = () => { throw new Error('e2e boom') }
    setTimeout(boom)
    setTimeout(boom)
    void Promise.reject(new Error('e2e rejected'))
  })

  await expect.poll(() => reports.length).toBe(2)
  expect(reports).toEqual(expect.arrayContaining([
    expect.objectContaining({ kind: 'error', message: 'Error: e2e boom', page: '/seasonal' }),
    expect.objectContaining({ kind: 'unhandledrejection', message: 'Error: e2e rejected', page: '/seasonal' })
  ]))
  for (const report of reports) {
    expect(JSON.stringify(report)).not.toMatch(/token|email/i)
  }
})
