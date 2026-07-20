import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Locator, type Page } from '@playwright/test'

const mobileViewport = { width: 393, height: 659 }
const detailTitle = '無自覺測試聖女今天也無意識地釋放力量'

const publicRoutes = [
  { path: '/', ready: 'cards' },
  { path: '/seasonal?year=2026&season=summer', ready: 'cards' },
  { path: '/catalog', ready: 'cards' },
  { path: '/anime/2320', ready: 'detail' }
] as const

async function settleLayout(page: Page) {
  await page.evaluate(async () => {
    await document.fonts.ready
    await new Promise<void>(resolve => requestAnimationFrame(() => requestAnimationFrame(() => resolve())))
  })
}

async function openReadyPage(page: Page, path: string, ready: 'cards' | 'detail') {
  const catalogTags = path.startsWith('/catalog')
    ? page.waitForResponse(response => response.url().includes('/public/anime/tags') && response.ok())
    : null
  const response = await page.goto(path, { waitUntil: 'domcontentloaded' })
  expect(response?.ok(), `${path} should return a successful document response`).toBe(true)
  await expect(page.locator('#main-content')).toBeVisible()

  if (ready === 'cards') {
    await expect(page.locator('#main-content a[href^="/anime/"]').first()).toBeVisible()
  } else {
    await expect(page.getByRole('heading', { level: 1, name: detailTitle })).toBeVisible()
  }

  await catalogTags
  await settleLayout(page)
}

async function dimensions(locator: Locator) {
  const box = await locator.boundingBox()
  expect(box, 'expected the element to have a rendered bounding box').not.toBeNull()
  return box!
}

async function expectMinimumTarget(locator: Locator, minimum = 44) {
  await expect(locator).toBeVisible()
  const box = await dimensions(locator)
  expect(box.width).toBeGreaterThanOrEqual(minimum)
  expect(box.height).toBeGreaterThanOrEqual(minimum)
}

async function expectNoHorizontalOverflow(page: Page, label: string) {
  const measurement = await page.evaluate(() => ({
    viewport: window.innerWidth,
    document: document.documentElement.scrollWidth,
    body: document.body.scrollWidth
  }))
  expect(
    Math.max(measurement.document, measurement.body) - measurement.viewport,
    `${label} horizontally overflowed: ${JSON.stringify(measurement)}`
  ).toBe(0)
}

async function expectNoSeriousOrCriticalViolations(page: Page, label: string) {
  const result = await new AxeBuilder({ page }).analyze()
  const violations = result.violations
    .filter(violation => violation.impact === 'serious' || violation.impact === 'critical')
    .map(violation => ({
      id: violation.id,
      impact: violation.impact,
      help: violation.help,
      nodes: violation.nodes.map(node => ({
        target: node.target,
        html: node.html,
        failureSummary: node.failureSummary
      }))
    }))
  expect(violations, `${label} has serious/critical axe violations`).toEqual([])
}

test('393px seasonal keeps two columns above the fold and persistent 44px controls', async ({ page }) => {
  await page.setViewportSize(mobileViewport)
  await openReadyPage(page, '/seasonal?year=2026&season=summer', 'cards')

  const cardLinks = page.locator('#main-content a[href^="/anime/"]')
  await expect.poll(() => cardLinks.count()).toBeGreaterThanOrEqual(4)
  const cardBoxes = await cardLinks.evaluateAll(elements => elements.map(element => {
    const rect = element.getBoundingClientRect()
    return { x: Math.round(rect.x), y: Math.round(rect.y), width: Math.round(rect.width) }
  }).filter(box => box.width > 0 && box.y >= 0))
  const firstCardY = Math.min(...cardBoxes.map(box => box.y))
  const firstRow = cardBoxes.filter(box => Math.abs(box.y - firstCardY) <= 1)
  expect(new Set(firstRow.map(box => box.x)).size).toBe(2)
  expect(firstCardY).toBeLessThanOrEqual(300)

  for (const actionName of ['加入收藏', '標記已看']) {
    const action = page.getByRole('button', { name: actionName }).first()
    await expectMinimumTarget(action)
    const renderedState = await action.evaluate(element => {
      const style = getComputedStyle(element)
      return { opacity: style.opacity, pointerEvents: style.pointerEvents }
    })
    expect(renderedState).toEqual({ opacity: '1', pointerEvents: 'auto' })
  }

  const filterTrigger = page.getByRole('button', { name: '篩選', exact: true })
  await expectMinimumTarget(filterTrigger)
  await filterTrigger.click()

  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()
  await expectMinimumTarget(dialog.getByRole('button', { name: '關閉' }))
  await expectMinimumTarget(dialog.locator('#filter-year-input'))
  await expectMinimumTarget(dialog.locator('#filter-season-select'))
  await expectMinimumTarget(dialog.getByRole('button', { name: /^套用/ }))

  await page.keyboard.press('Escape')
  await expect(dialog).toBeHidden()
  await expect(filterTrigger).toBeFocused()
})

test('393px detail keeps the title, CTA, stream targets and zero overflow', async ({ page }) => {
  await page.setViewportSize(mobileViewport)
  await openReadyPage(page, '/anime/2320', 'detail')

  await expect(page.getByRole('heading', { level: 1, name: detailTitle })).toBeVisible()
  await expectMinimumTarget(page.getByRole('button', { name: '加入清單' }))

  const streamSection = page.getByRole('heading', { name: '線上觀看' }).locator('..')
  for (const stream of await streamSection.locator('a, span.inline-flex').all()) {
    await expectMinimumTarget(stream)
  }

  await expectNoHorizontalOverflow(page, '393px /anime/2320')
})

test('768px detail uses desktop navigation and preserves compact desktop controls', async ({ page }) => {
  await page.setViewportSize({ width: 768, height: 1024 })
  await page.route('https://www.youtube.com/**', route => route.abort())
  await openReadyPage(page, '/anime/2320', 'detail')

  await expect(page.locator('nav[aria-label="主要導覽"]')).toBeVisible()
  await expect(page.locator('nav[aria-label="手機導覽"]')).toBeHidden()

  const streamChip = page.getByRole('heading', { name: '線上觀看' }).locator('..').locator('span.inline-flex').first()
  const streamBox = await dimensions(streamChip)
  expect(Math.round(streamBox.height)).toBe(34)

  const trailer = page.getByRole('heading', { name: '宣傳片' }).locator('..').getByRole('button').first()
  await trailer.click()
  const trailerDialog = page.getByRole('dialog')
  await expect(trailerDialog).toBeFocused()
  await page.keyboard.press('Shift+Tab')
  await expect(trailerDialog.locator('iframe')).toBeFocused()

  const closeTrailer = page.getByRole('button', { name: '關閉影片' })
  await expect(closeTrailer).toBeVisible()
  const closeBox = await dimensions(closeTrailer)
  expect(Math.round(closeBox.width)).toBe(32)
  expect(Math.round(closeBox.height)).toBe(32)
  await closeTrailer.click()

  await expectNoHorizontalOverflow(page, '768px /anime/2320')
})

for (const width of [320, 393, 768, 1440]) {
  test(`${width}px primary public pages have no horizontal overflow`, async ({ page }) => {
    await page.setViewportSize({ width, height: width < 768 ? 800 : 1024 })
    for (const route of publicRoutes) {
      await openReadyPage(page, route.path, route.ready)
      await expectNoHorizontalOverflow(page, `${width}px ${route.path}`)
    }
  })
}

test('key mobile public pages have no serious or critical axe violations', async ({ page }) => {
  await page.setViewportSize(mobileViewport)
  for (const route of publicRoutes.slice(1)) {
    await openReadyPage(page, route.path, route.ready)

    if (route.path === '/catalog') {
      const filterTrigger = page.getByRole('button', { name: '篩選', exact: true })
      await expectMinimumTarget(filterTrigger)
      await filterTrigger.click()
      const filterDialog = page.getByRole('dialog', { name: '篩選動漫資料庫' })
      await expect(filterDialog).toBeVisible()
      await page.keyboard.press('Escape')
      await expect(filterDialog).toBeHidden()
      await expect(filterTrigger).toBeFocused()
    }

    await expectNoSeriousOrCriticalViolations(page, `393px ${route.path}`)
  }
})
