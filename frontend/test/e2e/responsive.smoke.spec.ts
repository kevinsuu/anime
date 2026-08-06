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

async function openAuthenticatedList(page: Page) {
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

  await page.route('**/public/my/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname
    let body: object

    if (pathname === '/public/my/anime-list') {
      body = {
        items: [{
          id: 9001,
          watched: false,
          rating: null,
          note: '',
          created_at: '2026-07-01T00:00:00Z',
          updated_at: '2026-07-01T00:00:00Z',
          collections: [],
          anime: {
            id: 2320,
            name: detailTitle,
            image_url: '',
            season_year: 2026,
            season_code: 'summer',
            tags: ['奇幻']
          }
        }],
        meta: { page: 1, per_page: 50, total: 1, last_page: 1, has_more: false }
      }
    } else if (pathname === '/public/my/anime-list/counts') {
      body = { counts: { all: 1, watched: 0, unwatched: 1 } }
    } else if (pathname === '/public/my/anime-list/tags') {
      body = { tags: [{ tag: '奇幻', count: 1 }] }
    } else if (pathname === '/public/my/collections') {
      body = { items: [] }
    } else {
      await route.fallback()
      return
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: { 'Access-Control-Allow-Origin': '*' },
      body: JSON.stringify(body)
    })
  })

  await page.goto('/login', { waitUntil: 'domcontentloaded' })
  await page.locator('nav[aria-label="手機導覽"]')
    .getByRole('link', { name: '我的清單', exact: true })
    .click()
  await expect(page.getByRole('heading', { level: 1, name: '我的清單' })).toBeVisible()
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

test('393px seasonal keeps two columns, gesture guidance and uncluttered cards', async ({ page }) => {
  await page.setViewportSize(mobileViewport)
  await openReadyPage(page, '/seasonal?year=2026&season=summer', 'cards')

  const cardLinks = page.locator('#main-content a[href^="/anime/"]')
  await expect.poll(() => cardLinks.count()).toBeGreaterThanOrEqual(4)
  const cardBoxes = await cardLinks.evaluateAll(elements => elements.map(element => {
    const rect = element.getBoundingClientRect()
    return {
      x: Math.round(rect.x),
      y: Math.round(rect.y),
      width: Math.round(rect.width),
      height: Math.round(rect.height)
    }
  }).filter(box => box.width > 0 && box.y >= 0))
  const firstCardY = Math.min(...cardBoxes.map(box => box.y))
  const firstRow = cardBoxes.filter(box => Math.abs(box.y - firstCardY) <= 1)
  expect(new Set(firstRow.map(box => box.x)).size).toBe(2)
  const firstRowByX = [...firstRow].sort((a, b) => a.x - b.x)
  expect(firstRowByX[1]!.x - (firstRowByX[0]!.x + firstRowByX[0]!.width)).toBe(16)
  const secondRowY = Math.min(...cardBoxes.filter(box => box.y > firstCardY + 1).map(box => box.y))
  expect(secondRowY - (firstCardY + firstRowByX[0]!.height)).toBe(16)
  expect(firstCardY).toBeLessThanOrEqual(330)

  const firstCardShell = cardLinks.first().locator('..')
  const cardBox = await dimensions(cardLinks.first())
  const cardTitle = firstCardShell.locator('[data-mobile-card-title]')
  const cardTitleBox = await dimensions(cardTitle)
  const titleBox = await dimensions(cardLinks.first().getByRole('heading', { level: 3 }))
  expect(cardTitleBox.height).toBeLessThan(cardBox.height * 0.3)
  expect(titleBox.y).toBeGreaterThanOrEqual(cardTitleBox.y)
  expect(titleBox.y + titleBox.height).toBeLessThanOrEqual(cardTitleBox.y + cardTitleBox.height)
  await expect(firstCardShell.locator('[data-card-gradient]')).toHaveClass(/from-black\/60/)
  await expect(firstCardShell.locator('[data-card-gradient]')).toHaveClass(/to-black\/80/)
  const metadataStack = firstCardShell.locator('[data-card-meta-stack]')
  const episodeBadge = metadataStack.locator('[data-episode-badge]')
  const streamBadge = metadataStack.locator('[data-stream-badge]')
  await expect(episodeBadge).toHaveClass(/bg-green-600/)
  await expect(streamBadge).toHaveClass(/bg-gray-600/)
  const [episodeBox, streamBox] = await Promise.all([dimensions(episodeBadge), dimensions(streamBadge)])
  expect(Math.round(streamBox.y)).toBe(Math.round(episodeBox.y + episodeBox.height))

  const gestureHint = page.locator('[data-mobile-card-gesture-hint]').first()
  await expect(gestureHint).toBeVisible()
  await expect(gestureHint).toContainText('點兩下收藏')
  await expect(gestureHint).toContainText('長按設為已觀看')

  const cardLink = cardLinks.first()
  await expect(cardLink).toHaveAttribute('data-mobile-gesture-card', '')
  await expect(cardLink).toHaveCSS('touch-action', 'manipulation')
  const mobileActionLayer = await firstCardShell.locator('.anime-card-actions').evaluate(element => {
    const style = getComputedStyle(element)
    return {
      clipPath: style.clipPath,
      height: style.height,
      pointerEvents: style.pointerEvents,
      width: style.width
    }
  })
  expect(mobileActionLayer).toEqual({
    clipPath: 'inset(50%)',
    height: '1px',
    pointerEvents: 'none',
    width: '1px'
  })

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

test('393px catalog title and search card match the seasonal filter layout', async ({ page }) => {
  await page.setViewportSize(mobileViewport)
  await openReadyPage(page, '/seasonal?year=2026&season=summer', 'cards')
  const seasonalTitleLayout = await page.getByRole('heading', { level: 1 }).evaluate((element) => {
    const header = element.closest('header')!.getBoundingClientRect()
    const style = getComputedStyle(element)
    return {
      headerHeight: Math.round(header.height),
      fontSize: style.fontSize,
      lineHeight: style.lineHeight
    }
  })
  const seasonalFilterHeight = await page.getByRole('tablist', { name: '依星期篩選' }).evaluate((element) =>
    Math.round(element.closest('.rounded-2xl')!.getBoundingClientRect().height)
  )

  await openReadyPage(page, '/catalog', 'cards')
  const catalogTitleLayout = await page.getByRole('heading', { level: 1 }).evaluate((element) => {
    const header = element.closest('header')!.getBoundingClientRect()
    const style = getComputedStyle(element)
    return {
      headerHeight: Math.round(header.height),
      fontSize: style.fontSize,
      lineHeight: style.lineHeight
    }
  })
  const catalogFilterHeight = await page.locator('#catalog-search-mobile').evaluate((element) =>
    Math.round(element.closest('.rounded-2xl')!.getBoundingClientRect().height)
  )

  expect(catalogTitleLayout).toEqual(seasonalTitleLayout)
  expect(catalogFilterHeight).toBe(seasonalFilterHeight)
})

async function dispatchTouchTap(locator: Locator) {
  const touch = { clientX: 40, clientY: 40, isPrimary: true, pointerId: 1, pointerType: 'touch' }
  await locator.dispatchEvent('pointerdown', touch)
  await locator.dispatchEvent('pointerup', touch)
  await locator.dispatchEvent('pointerleave', touch)
  await locator.dispatchEvent('click', { detail: 1 })
}

test('393px card gestures map single tap, double tap and long press correctly', async ({ page }) => {
  await page.setViewportSize(mobileViewport)
  await openReadyPage(page, '/seasonal?year=2026&season=summer', 'cards')

  let firstCard = page.locator('[data-mobile-gesture-card]').first()
  const detailPath = await firstCard.getAttribute('href')
  expect(detailPath).toMatch(/^\/anime\/\d+$/)
  await dispatchTouchTap(firstCard)
  await expect(page).toHaveURL(new RegExp(`${detailPath}$`), { timeout: 2000 })

  await openReadyPage(page, '/seasonal?year=2026&season=summer', 'cards')
  firstCard = page.locator('[data-mobile-gesture-card]').first()
  await dispatchTouchTap(firstCard)
  await dispatchTouchTap(firstCard)
  await expect(page).toHaveURL(/\/login$/)

  await openReadyPage(page, '/seasonal?year=2026&season=summer', 'cards')
  const longPressCard = page.locator('[data-mobile-gesture-card]').first()
  await longPressCard.dispatchEvent('pointerdown', {
    clientX: 40,
    clientY: 40,
    isPrimary: true,
    pointerId: 1,
    pointerType: 'touch'
  })
  await expect(page).toHaveURL(/\/login$/, { timeout: 2000 })
})

test('desktop card pointer detection maps click, double click and hold correctly', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1024 })
  await openReadyPage(page, '/seasonal?year=2026&season=summer', 'cards')

  let firstCard = page.locator('[data-mobile-gesture-card]').first()
  await firstCard.dblclick()
  await expect(page).toHaveURL(/\/login$/)

  await openReadyPage(page, '/seasonal?year=2026&season=summer', 'cards')
  firstCard = page.locator('[data-mobile-gesture-card]').first()
  const cardBox = await dimensions(firstCard)
  await page.mouse.move(cardBox.x + cardBox.width / 2, cardBox.y + cardBox.height / 2)
  await page.mouse.down()
  await expect(page).toHaveURL(/\/login$/, { timeout: 2000 })
  await page.mouse.up()

  await openReadyPage(page, '/seasonal?year=2026&season=summer', 'cards')
  firstCard = page.locator('[data-mobile-gesture-card]').first()
  const detailPath = await firstCard.getAttribute('href')
  expect(detailPath).toMatch(/^\/anime\/\d+$/)
  await firstCard.click()
  await expect(page).toHaveURL(new RegExp(`${detailPath}$`), { timeout: 2000 })
})

test('393px list controls group scope clearly and keep categories collapsed', async ({ page }) => {
  await page.setViewportSize(mobileViewport)
  await openAuthenticatedList(page)

  await expect(page.getByText('顯示範圍', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: '全部 1' })).toHaveAttribute('aria-pressed', 'true')
  await expect(page.getByRole('button', { name: '未觀看 1' })).toBeVisible()
  await expect(page.getByRole('button', { name: /收藏分類.*選擇或管理分類/ })).toBeVisible()
  await expect(page.getByRole('heading', { level: 2, name: '搜尋與篩選' })).toBeVisible()

  const sort = page.getByRole('combobox', { name: '排序方式' })
  const categoryTrigger = page.getByRole('button', { name: '作品分類' })
  await expectMinimumTarget(sort)
  await expectMinimumTarget(categoryTrigger)
  await expect(categoryTrigger).toHaveAttribute('aria-expanded', 'false')
  await expect(page.locator('#list-tag-filters')).toBeHidden()

  const [sortBox, categoryBox] = await Promise.all([dimensions(sort), dimensions(categoryTrigger)])
  expect(Math.round(sortBox.y)).toBe(Math.round(categoryBox.y))

  await categoryTrigger.click()
  await expect(categoryTrigger).toHaveAttribute('aria-expanded', 'true')
  await expect(page.locator('#list-tag-filters')).toBeVisible()
  await expectNoHorizontalOverflow(page, '393px /list controls')
})

test('site header scrolls away on mobile and stays sticky on desktop', async ({ page }) => {
  await page.setViewportSize(mobileViewport)
  await openReadyPage(page, '/seasonal?year=2026&season=summer', 'cards')

  const header = page.getByRole('banner')
  await page.evaluate(() => window.scrollTo(0, 500))
  await expect.poll(async () => (await dimensions(header)).y).toBeLessThan(0)

  await page.setViewportSize({ width: 768, height: 1024 })
  await page.evaluate(() => window.scrollTo(0, 500))
  await expect.poll(async () => Math.round((await dimensions(header)).y)).toBe(0)
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
