import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const readSource = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')
const packageJson = JSON.parse(readSource('package.json'))
const nuxtConfig = readSource('nuxt.config.ts')
const loginPage = readSource('app/pages/login.vue')

describe('Google 登入設定', () => {
  it('本機開發會載入專案根目錄的單一 .env', () => {
    expect(packageJson.scripts.dev).toContain('--dotenv ../.env')
    expect(nuxtConfig).toContain('process.env.GOOGLE_CLIENT_ID')
  })

  it('設定缺失或 Google script 載入失敗時不會只留下空白', () => {
    expect(loginPage).toContain("'missing-config'")
    expect(loginPage).toContain("'error'")
    expect(loginPage).toContain('Google 登入尚未設定')
    expect(loginPage).toContain('重新載入 Google 登入')
  })
})
