import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { defineConfig } from '@playwright/test'

const mockApiPort = 41731
const appPort = 41732
const mockApiOrigin = `http://127.0.0.1:${mockApiPort}`
const appOrigin = `http://127.0.0.1:${appPort}`

export default defineConfig({
  testDir: './test/e2e',
  testMatch: '**/*.spec.ts',
  fullyParallel: false,
  workers: 1,
  timeout: 45_000,
  expect: {
    timeout: 10_000
  },
  reporter: [['line']],
  outputDir: join(tmpdir(), 'anime-playwright-results'),
  use: {
    baseURL: appOrigin,
    locale: 'zh-TW',
    timezoneId: 'Asia/Taipei',
    reducedMotion: 'reduce',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off'
  },
  webServer: [
    {
      command: 'node test/e2e/mock-api.mjs',
      url: `${mockApiOrigin}/health`,
      env: {
        MOCK_API_PORT: String(mockApiPort)
      },
      reuseExistingServer: false,
      timeout: 15_000,
      stdout: 'ignore',
      stderr: 'pipe'
    },
    {
      command: 'node .output/server/index.mjs',
      url: `${appOrigin}/seasonal?year=2026&season=summer`,
      env: {
        NITRO_HOST: '127.0.0.1',
        NITRO_PORT: String(appPort),
        NUXT_API_BASE_URL_INTERNAL: `${mockApiOrigin}/internal`,
        NUXT_PUBLIC_API_BASE_URL: `${mockApiOrigin}/public`,
        NUXT_PUBLIC_GOOGLE_CLIENT_ID: 'playwright-placeholder.apps.googleusercontent.com'
      },
      reuseExistingServer: false,
      timeout: 120_000,
      stdout: 'ignore',
      stderr: 'pipe'
    }
  ]
})
