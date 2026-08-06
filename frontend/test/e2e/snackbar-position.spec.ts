import { expect, test } from '@playwright/test'

test('mobile snackbars stay compact at the viewport top-right corner', async ({ page }) => {
  await page.setViewportSize({ width: 393, height: 659 })
  await page.context().grantPermissions(['clipboard-read', 'clipboard-write'])
  await page.addInitScript(() => {
    localStorage.setItem('animeTrackerSession', JSON.stringify({
      token: 'playwright-token',
      refreshToken: 'playwright-refresh-token',
      user: {
        id: 1,
        display_name: 'Playwright 使用者',
        email: 'playwright@example.com',
        public_slug: 'playwright-user'
      }
    }))
  })
  await page.goto('/login', { waitUntil: 'domcontentloaded' })
  await page.getByRole('link', { name: '設定' }).click()
  await expect(page.getByRole('heading', { level: 1, name: '設定' })).toBeVisible()

  await page.getByRole('button', { name: '複製', exact: true }).click()

  const toaster = page.locator('[data-slot="viewport"]')
  const snackbar = page.locator('li[data-slot="base"]')
  await expect(toaster).toHaveCount(1)
  await expect(snackbar).toContainText('分享連結已複製')

  const metrics = await toaster.evaluate((element) => {
    const rect = element.getBoundingClientRect()
    const snackbar = element.querySelector<HTMLElement>('li[data-slot="base"]')
    const snackbarRect = snackbar?.getBoundingClientRect()
    const title = snackbar?.querySelector<HTMLElement>('[data-slot="title"]')
    return {
      position: getComputedStyle(element).position,
      top: Math.round(rect.top),
      right: Math.round(window.innerWidth - rect.right),
      width: Math.round(rect.width),
      snackbarHeight: Math.round(snackbarRect?.height ?? 0),
      titleSize: title ? getComputedStyle(title).fontSize : '',
      expanded: snackbar?.dataset.expanded
    }
  })

  expect(metrics).toEqual({
    position: 'fixed',
    top: 12,
    right: 12,
    width: 256,
    snackbarHeight: 36,
    titleSize: '12px',
    expanded: 'false'
  })
})
