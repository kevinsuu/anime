import { expect, test } from '@playwright/test'

test('saved card statuses show before the bootstrap request answers', async ({ page }) => {
  await page.setViewportSize({ width: 393, height: 852 })
  await page.addInitScript(() => {
    localStorage.setItem('animeTrackerSession', JSON.stringify({
      token: 'playwright-token',
      refreshToken: 'playwright-refresh-token',
      user: { id: 1, display_name: 'Playwright 使用者', email: 'playwright@example.com', public_slug: 'playwright-user' }
    }))
    localStorage.setItem('animeCardStatuses:v1:1', JSON.stringify({
      savedAt: Date.now(),
      statuses: [{ animeId: 2320, listItemId: 9001, watched: true, collectionIds: [] }],
      collections: []
    }))
  })

  let releaseBootstrap!: () => void
  const bootstrapHeld = new Promise<void>((resolve) => { releaseBootstrap = resolve })
  await page.route(/\/public\/me\/bootstrap/, async (route) => {
    await bootstrapHeld
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: { 'Access-Control-Allow-Origin': '*' },
      body: JSON.stringify({
        user: { id: 1 },
        statuses: [{ anime_id: 2320, list_item_id: 9001, watched: true, collection_ids: [] }],
        collections: []
      })
    })
  })

  await page.goto('/seasonal?year=2026&season=summer', { waitUntil: 'domcontentloaded' })
  const card = page.locator('[data-mobile-gesture-card][href="/anime/2320"]').locator('..')

  // Visible while the bootstrap request is still held open.
  await expect(card).toHaveAttribute('data-card-status', 'watched')
  releaseBootstrap()
  await expect(card).toHaveAttribute('data-card-status', 'watched')
})
