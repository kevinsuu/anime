# Nuxt + 論壇風格前端改版 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把 `frontend/` 從 Vue 3 + Vite SPA 整套改寫為 Nuxt 4 + Nuxt UI 專案，並把新番表／資料庫搜尋／我的清單三個頁面改成密度更高的論壇風格版面（參考 acgsecrets.hk），同時保留現有的所有功能行為（登入、清單操作、新番同步等）與後端 API 完全不變。

**Architecture:** Nuxt 4 在 SPA 模式（`ssr: false`）下運作，維持「純前端靜態檔案部署到 GitHub Pages、呼叫獨立部署的 Laravel API」的架構。檔案式路由（`app/pages/`）取代手寫 hash router。`@nuxt/ui` 提供元件與 Tailwind 樣式，取代手寫 CSS。既有的 API client、normalize 邏輯、session 管理原樣搬遷到 Nuxt composables/utils，行為不變。

**Tech Stack:** Nuxt 4.x（SPA 模式）、@nuxt/ui v4（含 Tailwind CSS、lucide icon）、Vue 3、Node.js **22+**（Nuxt 4 工具鏈的 engine 要求，比原計畫假設的 Node 20 更新；本機與 Docker 都需要升級，見 Task 11）、Vitest（取代 node:test 做元件測試，因為 Nuxt 元件需要 Vue Test Utils 編譯）。

> **版本修正記錄（執行 Task 1 後）：** 原計畫假設 Nuxt 3.x，但 `npx nuxi@latest init` 目前（截至執行時）已不再提供 Nuxt 3 template，預設產生 Nuxt 4.4.8。決定跟隨現況改用 Nuxt 4，並將 Node 版本需求由 20+ 提升到 22+。下方所有任務中提及 "Nuxt 3" 的描述性文字，實作時請理解為 Nuxt 4 — API 與檔案結構（`app/pages/`、`app/composables/`、`definePageMeta` 等）相容，差異主要在 `vue-router` 升級到 v5 與 engine 要求。

---

## 設計依據

本計畫實作 `docs/superpowers/specs/2026-06-29-nuxt-forum-style-redesign-design.md` 所定義的設計。實作前請先讀過該文件以理解整體脈絡；以下任務是該設計的具體拆解。

## 重要約定

- **後端完全不動**：所有任務只修改 `frontend/` 目錄與專案根目錄的 `.env.example`、`docker-compose.yml`（僅 frontend service 相關設定）。不修改 `backend/` 任何檔案。
- **舊檔案保留到最後**：在 Task 12（清理舊檔案）之前，`frontend/src/`（Vue 版本）與新建的 `frontend/app/`（Nuxt 版本）會同時存在。這是刻意安排，避免中途某個 task 失敗導致網站完全不能用。`package.json` 的 `dev`/`build` script 會在 Task 2 就切換成 Nuxt 指令，所以從 Task 2 之後 `docker compose up frontend` 跑的是 Nuxt。
- **每個任務結束都要能跑起來**：每個 task 做完後執行 `cd frontend && npm run dev` 確認沒有 build error，不要求每個中間狀態視覺上完整。

---

### Task 1: 初始化 Nuxt 3 專案骨架

**Files:**
- Create: `frontend-nuxt-init/`（暫存目錄，稍後整個移進 `frontend/`）

這一步在暫存目錄建立全新 Nuxt 專案，因為 `nuxi init` 要求目標目錄是空的，而 `frontend/` 目前有既有的 Vue 專案檔案。

- [ ] **Step 1: 在暫存目錄建立 Nuxt 專案**

```bash
cd ~/anime
npx nuxi@latest init frontend-nuxt-init --packageManager npm --gitInit no
```

預期：產生 `frontend-nuxt-init/` 目錄，內含 `nuxt.config.ts`、`app/app.vue`、`package.json` 等標準 Nuxt 專案檔案。

- [ ] **Step 2: 確認產生的檔案結構**

```bash
ls frontend-nuxt-init/
```

預期看到：`app/`、`nuxt.config.ts`、`package.json`、`tsconfig.json`、`public/`。

- [ ] **Step 3: 安裝 @nuxt/ui**

```bash
cd frontend-nuxt-init
npm install @nuxt/ui
```

- [ ] **Step 4: 在 nuxt.config.ts 註冊 @nuxt/ui 模組**

Read 現有的 `frontend-nuxt-init/nuxt.config.ts`，將其內容改為：

```typescript
export default defineNuxtConfig({
  compatibilityDate: '2026-06-29',
  devtools: { enabled: true },
  ssr: false,
  modules: ['@nuxt/ui']
})
```

- [ ] **Step 5: 啟動確認 Nuxt UI 可用**

```bash
npm run dev
```

預期：終端機顯示 Nuxt dev server 啟動訊息（通常是 `http://localhost:3000/`），無 error。啟動後用 Ctrl+C 停止。

- [ ] **Step 6: 提交（在暫存目錄外的 git repo 紀錄一個檢查點）**

這個暫存目錄不算進最終 commit，先不要 `git add`。確認 Step 5 成功後即可進入 Task 2。

---

### Task 2: 把 Nuxt 骨架併入 frontend/，建立雙專案並存的過渡狀態

**Files:**
- Modify: `frontend/package.json`
- Create: `frontend/nuxt.config.ts`
- Create: `frontend/app/app.vue`
- Create: `frontend/tsconfig.json`
- Move: `frontend-nuxt-init/*` → `frontend/`（除 `package.json` 用合併方式處理）

- [ ] **Step 1: 複製 Nuxt 骨架檔案到 frontend/（不覆蓋既有的 src/、index.html）**

```bash
cd ~/anime
cp -r frontend-nuxt-init/app frontend/app
cp frontend-nuxt-init/nuxt.config.ts frontend/nuxt.config.ts
cp frontend-nuxt-init/tsconfig.json frontend/tsconfig.json
mkdir -p frontend/public
cp -r frontend-nuxt-init/public/. frontend/public/ 2>/dev/null || true
```

- [ ] **Step 2: 讀取兩份 package.json 並手動合併**

Read `frontend-nuxt-init/package.json` 與 `frontend/package.json`。

用 Edit 把 `frontend/package.json` 改為（保留現有的 `lucide-vue-next` 依賴到 Task 9 才移除，因為舊元件還在用）：

```json
{
  "name": "anime-tracker-frontend",
  "version": "0.1.0",
  "private": true,
  "type": "module",
  "scripts": {
    "dev": "nuxt dev --host 0.0.0.0",
    "build": "nuxt build",
    "generate": "nuxt generate",
    "preview": "nuxt preview"
  },
  "dependencies": {
    "@nuxt/ui": "^3.0.0",
    "lucide-vue-next": "^1.0.0",
    "nuxt": "^3.15.0",
    "vue": "^3.5.13",
    "vue-router": "^4.5.0"
  }
}
```

注意：實際版本號以 Task 1 的 `npm install` 結果為準 — Read `frontend-nuxt-init/package.json` 取得 `nuxt`、`@nuxt/ui`、`vue-router` 的確切版本號後填入，不要憑空寫版本號。

- [ ] **Step 3: 安裝相依套件**

```bash
cd frontend
npm install
```

- [ ] **Step 4: 暫時清空 app.vue 為最小可跑版本，確認建置成功**

Write `frontend/app/app.vue`：

```vue
<template>
  <UApp>
    <NuxtRouteAnnouncer />
    <NuxtPage />
  </UApp>
</template>
```

- [ ] **Step 5: 建立暫時的首頁確認可以跑**

Write `frontend/app/pages/index.vue`：

```vue
<template>
  <div class="p-8">
    <h1 class="text-2xl font-bold">Nuxt 遷移中</h1>
  </div>
</template>
```

- [ ] **Step 6: 啟動確認**

```bash
cd frontend
npm run dev
```

預期：dev server 啟動，瀏覽器開 `http://localhost:3000` 看到「Nuxt 遷移中」文字，無 console error。Ctrl+C 停止。

- [ ] **Step 7: 清理暫存目錄**

```bash
cd ~/anime
rm -rf frontend-nuxt-init
```

- [ ] **Step 8: Commit**

```bash
git add frontend/package.json frontend/nuxt.config.ts frontend/app frontend/tsconfig.json frontend/public
git commit -m "Initialize Nuxt 3 + Nuxt UI alongside existing Vue app"
```

---

### Task 3: 搬遷 API client 與資料正規化邏輯到 Nuxt composable

**Files:**
- Create: `frontend/app/composables/useApi.ts`
- Create: `frontend/app/utils/normalize.ts`
- Test: `frontend/test/normalize.test.ts`

這個任務原樣搬遷 `frontend/src/services/api.js` 的邏輯，不改變任何行為，只是換成 TypeScript 並拆成兩個檔案：純函式（`normalize.ts`）與需要 runtime config 的 API client（`useApi.ts`）。

- [ ] **Step 1: 安裝 vitest 作為測試工具**

```bash
cd frontend
npm install -D vitest @vue/test-utils happy-dom
```

- [ ] **Step 2: 建立 vitest 設定**

Write `frontend/vitest.config.ts`：

```typescript
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    environment: 'happy-dom'
  }
})
```

- [ ] **Step 3: 在 package.json 加上 test script**

Edit `frontend/package.json`，在 `scripts` 區塊加入：

```json
    "test": "vitest run"
```

- [ ] **Step 4: 寫 normalize.ts 的失敗測試**

Write `frontend/test/normalize.test.ts`：

```typescript
import { describe, expect, it } from 'vitest'
import { normalizeAnime, normalizeListItem, repairText } from '../app/utils/normalize'

describe('repairText', () => {
  it('returns the original string when it is not mojibake', () => {
    expect(repairText('芙莉蓮')).toBe('芙莉蓮')
  })

  it('returns fallback for empty input', () => {
    expect(repairText('', '預設值')).toBe('預設值')
  })
})

describe('normalizeAnime', () => {
  it('maps snake_case API fields to camelCase', () => {
    const result = normalizeAnime({
      id: 1,
      name: '葬送的芙莉蓮',
      image_url: 'https://example.com/cover.jpg',
      season_year: 2026,
      season_code: 'spring',
      air_date: '2026-04-05T23:00:00',
      episode_count: 12
    })

    expect(result).toEqual({
      id: 1,
      name: '葬送的芙莉蓮',
      description: '尚未整理作品介紹。',
      imageUrl: 'https://example.com/cover.jpg',
      source: '',
      seasonYear: 2026,
      seasonCode: 'spring',
      airDate: '2026-04-05T23:00:00',
      episodeCount: 12,
      status: ''
    })
  })

  it('falls back to placeholder name when missing', () => {
    const result = normalizeAnime({})
    expect(result.name).toBe('未命名作品')
  })
})

describe('normalizeListItem', () => {
  it('normalizes watched boolean and nested anime', () => {
    const result = normalizeListItem({
      id: 5,
      watched: 1,
      rating: '8',
      anime: { id: 1, name: '測試作品' }
    })

    expect(result.watched).toBe(true)
    expect(result.rating).toBe(8)
    expect(result.anime.name).toBe('測試作品')
  })

  it('keeps rating null when absent', () => {
    const result = normalizeListItem({ id: 1, anime: {} })
    expect(result.rating).toBeNull()
  })
})
```

- [ ] **Step 5: 跑測試確認失敗**

```bash
cd frontend
npm run test
```

預期：FAIL，錯誤訊息是找不到 `../app/utils/normalize` 模組。

- [ ] **Step 6: 實作 normalize.ts（從 services/api.js 原樣搬遷邏輯）**

Write `frontend/app/utils/normalize.ts`：

```typescript
const WINDOWS_1252_BYTES: Record<string, number> = {
  '€': 0x80, '‚': 0x82, 'ƒ': 0x83, '„': 0x84, '…': 0x85, '†': 0x86, '‡': 0x87,
  'ˆ': 0x88, '‰': 0x89, 'Š': 0x8a, '‹': 0x8b, 'Œ': 0x8c, 'Ž': 0x8e, '‘': 0x91,
  '’': 0x92, '“': 0x93, '”': 0x94, '•': 0x95, '–': 0x96, '—': 0x97, '˜': 0x98,
  '™': 0x99, 'š': 0x9a, '›': 0x9b, 'œ': 0x9c, 'ž': 0x9e, 'Ÿ': 0x9f
}

function looksLikeMojibake(value: string): boolean {
  return /[ÃÂâåæçèéäãï]/.test(value)
}

function byteFromMojibakeChar(char: string): number {
  if (WINDOWS_1252_BYTES[char] !== undefined) return WINDOWS_1252_BYTES[char]
  return char.charCodeAt(0) & 0xff
}

export function repairText(value: unknown, fallback = ''): string {
  if (typeof value !== 'string' || value === '') return fallback
  if (!looksLikeMojibake(value)) return value

  try {
    const bytes = Uint8Array.from(Array.from(value), byteFromMojibakeChar)
    const decoded = new TextDecoder('utf-8', { fatal: false }).decode(bytes)
    return looksLikeMojibake(decoded) ? value : decoded || fallback
  } catch {
    return value
  }
}

export interface Anime {
  id: number
  name: string
  description: string
  imageUrl: string
  source: string
  seasonYear: number | null
  seasonCode: string
  airDate: string | null
  episodeCount: number | null
  status: string
}

export function normalizeAnime(item: Record<string, any> = {}): Anime {
  return {
    id: item.id,
    name: repairText(item.name, '未命名作品'),
    description: repairText(item.description, '尚未整理作品介紹。'),
    imageUrl: item.imageUrl || item.image_url || '',
    source: item.source || '',
    seasonYear: item.seasonYear || item.season_year || null,
    seasonCode: item.seasonCode || item.season_code || '',
    airDate: item.airDate || item.air_date || null,
    episodeCount: item.episodeCount || item.episode_count || null,
    status: item.status || ''
  }
}

export interface ListItem {
  id: number
  watched: boolean
  rating: number | null
  note: string
  createdAt: string
  updatedAt: string
  anime: Anime
}

export function normalizeListItem(item: Record<string, any> = {}): ListItem {
  return {
    id: item.id,
    watched: Boolean(item.watched),
    rating: item.rating === null || item.rating === undefined ? null : Number(item.rating),
    note: repairText(item.note || ''),
    createdAt: item.createdAt || item.created_at || '',
    updatedAt: item.updatedAt || item.updated_at || '',
    anime: normalizeAnime(item.anime || {})
  }
}
```

- [ ] **Step 7: 跑測試確認通過**

```bash
cd frontend
npm run test
```

預期：PASS，6 個測試全過。

- [ ] **Step 8: 建立 useApi composable（原樣搬遷 request 邏輯）**

Write `frontend/app/composables/useApi.ts`：

```typescript
import { normalizeAnime, normalizeListItem, type Anime, type ListItem } from '../utils/normalize'

interface RequestOptions {
  method?: string
  body?: string
  headers?: Record<string, string>
}

export function useApi() {
  const config = useRuntimeConfig()
  const apiBaseUrl = config.public.apiBaseUrl as string

  function getToken(): string {
    if (typeof window === 'undefined') return ''
    try {
      const stored = JSON.parse(localStorage.getItem('animeTrackerSession') || '{}')
      return stored.token || ''
    } catch {
      return ''
    }
  }

  async function request(path: string, options: RequestOptions = {}): Promise<any> {
    const headers: Record<string, string> = { 'Content-Type': 'application/json', ...(options.headers || {}) }
    const token = getToken()
    if (token) headers.Authorization = `Bearer ${token}`

    let response: Response
    try {
      response = await fetch(`${apiBaseUrl}${path}`, { ...options, headers })
    } catch {
      throw new Error('無法連線到後端 API，請確認 backend container 是否啟動。')
    }

    const body = await response.json().catch(() => ({}))
    if (!response.ok) {
      const error: any = new Error(body.message || '請求失敗')
      error.status = response.status
      error.body = body
      throw error
    }

    return body
  }

  return {
    apiBaseUrl,
    login: (idToken: string) => request('/auth/google', { method: 'POST', body: JSON.stringify({ idToken }) }),
    me: () => request('/me'),
    searchAnime: (query: string, filters: { year?: number | string; season?: string } = {}) => {
      const params = new URLSearchParams()
      if (query) params.set('q', query)
      if (filters.year) params.set('year', String(filters.year))
      if (filters.season) params.set('season', filters.season)
      const queryString = params.toString()
      return request(`/anime${queryString ? `?${queryString}` : ''}`)
    },
    createAnime: (payload: Record<string, any>) => request('/anime', { method: 'POST', body: JSON.stringify(payload) }),
    syncSeasonalAnime: (payload: Record<string, any>) => request('/anime/sync-seasonal', { method: 'POST', body: JSON.stringify(payload) }),
    myList: () => request('/my/anime-list'),
    addToList: (animeId: number) => request('/my/anime-list', { method: 'POST', body: JSON.stringify({ animeId }) }),
    updateListItem: (id: number, payload: Record<string, any>) => request(`/my/anime-list/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
    deleteListItem: (id: number) => request(`/my/anime-list/${id}`, { method: 'DELETE' }),
    publicList: (slug: string) => request(`/public/lists/${encodeURIComponent(slug)}`),
    regenerateSlug: () => request('/me/share-slug/regenerate', { method: 'POST' })
  }
}

export type { Anime, ListItem }
```

- [ ] **Step 9: 在 nuxt.config.ts 加上 runtimeConfig**

Edit `frontend/nuxt.config.ts`，加入 `runtimeConfig`：

```typescript
export default defineNuxtConfig({
  compatibilityDate: '2026-06-29',
  devtools: { enabled: true },
  ssr: false,
  modules: ['@nuxt/ui'],
  runtimeConfig: {
    public: {
      apiBaseUrl: process.env.NUXT_PUBLIC_API_BASE_URL || 'http://localhost:8080',
      googleClientId: process.env.NUXT_PUBLIC_GOOGLE_CLIENT_ID || '',
      enableDevLogin: process.env.NUXT_PUBLIC_ENABLE_DEV_LOGIN === 'true'
    }
  }
})
```

- [ ] **Step 10: 確認 build 沒有 TypeScript 錯誤**

```bash
cd frontend
npx nuxi typecheck 2>&1 | head -40
```

預期：沒有 `useApi.ts` 或 `normalize.ts` 相關的型別錯誤（若 typecheck 指令本身需要額外安裝 `vue-tsc`，先執行 `npm install -D vue-tsc` 再重跑）。

- [ ] **Step 11: Commit**

```bash
git add frontend/app/composables frontend/app/utils frontend/test frontend/vitest.config.ts frontend/package.json frontend/package-lock.json frontend/nuxt.config.ts
git commit -m "Add Nuxt API client and data normalization with tests"
```

---

### Task 4: 搬遷 session 管理 composable

**Files:**
- Create: `frontend/app/composables/useSession.ts`
- Test: `frontend/test/useSession.test.ts`

- [ ] **Step 1: 寫失敗測試**

Write `frontend/test/useSession.test.ts`：

```typescript
import { beforeEach, describe, expect, it } from 'vitest'
import { useSession } from '../app/composables/useSession'

describe('useSession', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('starts unauthenticated when nothing is stored', () => {
    const { isAuthed, session } = useSession()
    expect(isAuthed.value).toBe(false)
    expect(session.token).toBe('')
  })

  it('setSession persists token and user, marks authed', () => {
    const { setSession, isAuthed, session } = useSession()
    setSession('abc123', { id: 1, email: 'a@b.com' })

    expect(isAuthed.value).toBe(true)
    expect(session.token).toBe('abc123')
    expect(JSON.parse(localStorage.getItem('animeTrackerSession') || '{}').token).toBe('abc123')
  })

  it('clearSession removes stored session', () => {
    const { setSession, clearSession, isAuthed } = useSession()
    setSession('abc123', { id: 1 })
    clearSession()

    expect(isAuthed.value).toBe(false)
    expect(localStorage.getItem('animeTrackerSession')).toBeNull()
  })

  it('setUser updates user without touching token', () => {
    const { setSession, setUser, session } = useSession()
    setSession('abc123', { id: 1, email: 'old@b.com' })
    setUser({ id: 1, email: 'new@b.com' })

    expect(session.token).toBe('abc123')
    expect(session.user.email).toBe('new@b.com')
  })
})
```

- [ ] **Step 2: 跑測試確認失敗**

```bash
cd frontend
npm run test -- useSession
```

預期：FAIL，找不到模組。

- [ ] **Step 3: 實作 useSession.ts（原樣搬遷自 src/composables/useSession.js）**

Write `frontend/app/composables/useSession.ts`：

```typescript
import { computed, reactive } from 'vue'

const SESSION_KEY = 'animeTrackerSession'

export interface SessionUser {
  id: number
  email?: string
  display_name?: string
  avatar_url?: string
  public_slug?: string
  [key: string]: any
}

function readStoredSession(): { token?: string; user?: SessionUser } {
  if (typeof window === 'undefined') return {}
  try {
    const value = JSON.parse(localStorage.getItem(SESSION_KEY) || '{}')
    return value && typeof value === 'object' ? value : {}
  } catch {
    localStorage.removeItem(SESSION_KEY)
    return {}
  }
}

export function useSession() {
  const stored = readStoredSession()
  const session = reactive({
    token: stored.token || '',
    user: stored.user || null as SessionUser | null
  })

  const isAuthed = computed(() => Boolean(session.token && session.user))

  function save() {
    localStorage.setItem(SESSION_KEY, JSON.stringify({
      token: session.token,
      user: session.user
    }))
  }

  function setSession(token: string, user: SessionUser) {
    session.token = token
    session.user = user
    save()
  }

  function setUser(user: SessionUser) {
    session.user = user
    save()
  }

  function clearSession() {
    session.token = ''
    session.user = null
    localStorage.removeItem(SESSION_KEY)
  }

  return {
    session,
    isAuthed,
    setSession,
    setUser,
    clearSession
  }
}
```

- [ ] **Step 4: 跑測試確認通過**

```bash
cd frontend
npm run test -- useSession
```

預期：PASS，4 個測試全過。

- [ ] **Step 5: Commit**

```bash
git add frontend/app/composables/useSession.ts frontend/test/useSession.test.ts
git commit -m "Add Nuxt session composable with tests"
```

---

### Task 5: 建立共用版面元件（導覽列）

**Files:**
- Create: `frontend/app/components/AppHeader.vue`
- Create: `frontend/app/components/AppMobileNav.vue`
- Modify: `frontend/app/app.vue`

導覽項目與保護路由規則原樣沿用 `src/components/AppNavigation.vue`。

- [ ] **Step 1: 建立桌機頂部導覽**

Write `frontend/app/components/AppHeader.vue`：

```vue
<script setup lang="ts">
const route = useRoute()
const { isAuthed } = useSession()
const config = useRuntimeConfig()

const navItems = [
  { label: '總覽', to: '/', protected: false },
  { label: '資料庫', to: '/catalog', protected: false },
  { label: '本季新番', to: '/seasonal', protected: false },
  { label: '我的清單', to: '/list', protected: true },
  { label: '設定', to: '/settings', protected: true }
]

function isActive(path: string): boolean {
  if (path === '/list') return route.path.startsWith('/list')
  if (path === '/') return route.path === '/' || route.path === '/seasonal'
  return route.path === path
}

function targetFor(item: typeof navItems[number]): string {
  return item.protected && !isAuthed.value ? '/login' : item.to
}
</script>

<template>
  <header class="sticky top-0 z-30 border-b border-gray-200 bg-white/96 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4">
      <NuxtLink to="/" class="flex items-center gap-3 font-bold text-gray-900">
        <span class="grid h-10 w-10 place-items-center rounded-full bg-primary-600 text-white">
          <UIcon name="i-lucide-sparkles" class="size-5" />
        </span>
        <span class="leading-tight">
          <strong class="block">動漫庫</strong>
          <small class="block text-xs font-normal text-gray-500">Anime Vault</small>
        </span>
      </NuxtLink>

      <nav class="hidden flex-1 items-center gap-1 overflow-x-auto md:flex" aria-label="主要導覽">
        <NuxtLink
          v-for="item in navItems"
          :key="item.to"
          :to="targetFor(item)"
          class="rounded-md px-3 py-2 text-sm font-semibold"
          :class="isActive(item.to) ? 'bg-primary-50 text-primary-700' : 'text-gray-600 hover:text-gray-900'"
        >
          {{ item.label }}
        </NuxtLink>
      </nav>

      <div class="hidden text-right text-xs text-gray-500 md:block">
        <span class="block">{{ isAuthed ? '已登入' : '訪客' }}</span>
        <strong class="block truncate text-gray-700">{{ config.public.apiBaseUrl }}</strong>
      </div>
    </div>
  </header>
</template>
```

- [ ] **Step 2: 建立手機底部導覽**

Write `frontend/app/components/AppMobileNav.vue`：

```vue
<script setup lang="ts">
const route = useRoute()
const { isAuthed } = useSession()

const navItems = [
  { label: '總覽', to: '/', icon: 'i-lucide-home', protected: false },
  { label: '資料庫', to: '/catalog', icon: 'i-lucide-search', protected: false },
  { label: '本季新番', to: '/seasonal', icon: 'i-lucide-calendar-days', protected: false },
  { label: '我的清單', to: '/list', icon: 'i-lucide-library', protected: true },
  { label: '設定', to: '/settings', icon: 'i-lucide-settings', protected: true }
]

function isActive(path: string): boolean {
  if (path === '/list') return route.path.startsWith('/list')
  if (path === '/') return route.path === '/' || route.path === '/seasonal'
  return route.path === path
}

function targetFor(item: typeof navItems[number]): string {
  return item.protected && !isAuthed.value ? '/login' : item.to
}
</script>

<template>
  <nav
    class="fixed inset-x-0 bottom-0 z-40 grid grid-cols-5 gap-1 border-t border-gray-200 bg-white p-2 shadow-[0_-12px_28px_rgba(15,23,42,0.1)] md:hidden"
    aria-label="手機導覽"
  >
    <NuxtLink
      v-for="item in navItems"
      :key="item.to"
      :to="targetFor(item)"
      class="flex flex-col items-center gap-1 rounded-md py-2 text-[11px]"
      :class="isActive(item.to) ? 'text-primary-700' : 'text-gray-600'"
    >
      <UIcon :name="item.icon" class="size-5" />
      <span>{{ item.label }}</span>
    </NuxtLink>
  </nav>
</template>
```

- [ ] **Step 3: 把元件接進 app.vue**

Write `frontend/app/app.vue`：

```vue
<template>
  <UApp>
    <NuxtRouteAnnouncer />
    <div class="min-h-dvh bg-gray-50">
      <AppHeader />
      <main class="mx-auto w-full max-w-6xl px-4 pb-24 pt-6 md:pb-12">
        <NuxtPage />
      </main>
      <AppMobileNav />
    </div>
  </UApp>
</template>
```

- [ ] **Step 4: 啟動確認導覽顯示正常**

```bash
cd frontend
npm run dev
```

預期：開 `http://localhost:3000`，看到頂部導覽列（桌機寬度）含「動漫庫」品牌與 5 個選單項目，無 console error。把瀏覽器縮到手機寬度應看到底部 5 個圖示導覽。Ctrl+C 停止。

- [ ] **Step 5: Commit**

```bash
git add frontend/app/components/AppHeader.vue frontend/app/components/AppMobileNav.vue frontend/app/app.vue
git commit -m "Add Nuxt UI top header and mobile bottom navigation"
```

---

### Task 6: 實作新番表頁面 — 星期 Tab + 篩選 Slideover + 卡片網格

**Files:**
- Create: `frontend/app/pages/seasonal.vue`
- Create: `frontend/app/pages/index.vue`（改為重新導向到 seasonal 的內容，與 seasonal 共用同一份畫面）
- Create: `frontend/app/components/SeasonalFilterPanel.vue`
- Create: `frontend/app/components/AnimeGridCard.vue`
- Create: `frontend/app/composables/useSeasonalCatalog.ts`

這是最大的一個任務，拆成多個 step。先建立資料邏輯（composable），再建立卡片元件，再建立篩選面板，最後組裝頁面。

- [ ] **Step 1: 建立 seasonal catalog composable（移植 genreCategories、過濾邏輯、星期推算）**

Write `frontend/app/composables/useSeasonalCatalog.ts`：

```typescript
import { computed, reactive } from 'vue'
import type { Anime } from '../utils/normalize'

export interface GenreCategory {
  key: string
  label: string
  terms: string[]
}

export const genreCategories: GenreCategory[] = [
  { key: 'all', label: '全部分類', terms: [] },
  { key: 'fantasy', label: '奇幻異世界', terms: ['奇幻', '異世界', '魔法', '冒險', '勇者', '幻想', 'fantasy', 'adventure'] },
  { key: 'romance', label: '戀愛青春', terms: ['戀愛', '青春', '愛情', '青梅竹馬', 'romance', 'love'] },
  { key: 'school', label: '校園社團', terms: ['校園', '學園', '高中', '社團', '學生', 'school', 'club'] },
  { key: 'action', label: '動作戰鬥', terms: ['動作', '戰鬥', '格鬥', '戰爭', '英雄', 'action', 'battle'] },
  { key: 'daily', label: '日常喜劇', terms: ['日常', '喜劇', '搞笑', '生活', 'comedy', 'slice of life'] },
  { key: 'sci-fi', label: '科幻機戰', terms: ['科幻', '機戰', '機器人', '未來', '宇宙', 'sci-fi', 'science fiction', 'robot'] }
]

export const weekdayTabs = [
  { key: 'all', label: '全部', dayIndex: null },
  { key: 'mon', label: '一', dayIndex: 1 },
  { key: 'tue', label: '二', dayIndex: 2 },
  { key: 'wed', label: '三', dayIndex: 3 },
  { key: 'thu', label: '四', dayIndex: 4 },
  { key: 'fri', label: '五', dayIndex: 5 },
  { key: 'sat', label: '六', dayIndex: 6 },
  { key: 'sun', label: '日', dayIndex: 0 }
]

export function matchesAnimeCategory(anime: Anime, category: GenreCategory): boolean {
  if (category.key === 'all') return true
  const searchableText = [anime.name, anime.description, anime.source, anime.status]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()

  return category.terms.some(term => searchableText.includes(term.toLowerCase()))
}

export function weekdayIndexOf(anime: Anime): number | null {
  if (!anime.airDate) return null
  const date = new Date(anime.airDate)
  return Number.isNaN(date.getTime()) ? null : date.getDay()
}

export function useSeasonalCatalog() {
  const state = reactive({
    seasonalCategory: 'all',
    seasonalStatus: 'all',
    weekday: 'all'
  })

  function filterSeasonal(seasonal: Anime[], listByAnimeId: Map<number, { watched: boolean }>) {
    const selectedCategory = genreCategories.find(c => c.key === state.seasonalCategory) || genreCategories[0]
    const activeWeekday = weekdayTabs.find(w => w.key === state.weekday) || weekdayTabs[0]

    return seasonal.filter(anime => {
      if (!matchesAnimeCategory(anime, selectedCategory)) return false
      if (activeWeekday.dayIndex !== null && weekdayIndexOf(anime) !== activeWeekday.dayIndex) return false

      const listItem = listByAnimeId.get(anime.id)
      if (state.seasonalStatus === 'listed') return Boolean(listItem)
      if (state.seasonalStatus === 'unlisted') return !listItem
      if (state.seasonalStatus === 'watched') return Boolean(listItem?.watched)
      if (state.seasonalStatus === 'queued') return Boolean(listItem && !listItem.watched)
      if (state.seasonalStatus === 'with-cover') return Boolean(anime.imageUrl)
      return true
    })
  }

  const activeFilterCount = computed(() => {
    let count = 0
    if (state.seasonalCategory !== 'all') count += 1
    if (state.seasonalStatus !== 'all') count += 1
    return count
  })

  return { state, filterSeasonal, activeFilterCount }
}
```

- [ ] **Step 2: 寫 useSeasonalCatalog 的測試**

Write `frontend/test/useSeasonalCatalog.test.ts`：

```typescript
import { describe, expect, it } from 'vitest'
import { matchesAnimeCategory, weekdayIndexOf, genreCategories } from '../app/composables/useSeasonalCatalog'
import { normalizeAnime } from '../app/utils/normalize'

describe('matchesAnimeCategory', () => {
  it('matches "all" category for anything', () => {
    const anime = normalizeAnime({ name: '任意作品' })
    expect(matchesAnimeCategory(anime, genreCategories[0])).toBe(true)
  })

  it('matches fantasy category by keyword in name', () => {
    const anime = normalizeAnime({ name: '異世界悠閒農家' })
    const fantasy = genreCategories.find(c => c.key === 'fantasy')!
    expect(matchesAnimeCategory(anime, fantasy)).toBe(true)
  })

  it('does not match unrelated category', () => {
    const anime = normalizeAnime({ name: '異世界悠閒農家' })
    const romance = genreCategories.find(c => c.key === 'romance')!
    expect(matchesAnimeCategory(anime, romance)).toBe(false)
  })
})

describe('weekdayIndexOf', () => {
  it('returns null when airDate is missing', () => {
    const anime = normalizeAnime({})
    expect(weekdayIndexOf(anime)).toBeNull()
  })

  it('returns the day-of-week index for a valid date', () => {
    const anime = normalizeAnime({ air_date: '2026-04-05T23:00:00' })
    const expected = new Date('2026-04-05T23:00:00').getDay()
    expect(weekdayIndexOf(anime)).toBe(expected)
  })
})
```

- [ ] **Step 3: 跑測試確認通過**

```bash
cd frontend
npm run test -- useSeasonalCatalog
```

預期：PASS，5 個測試全過（若 FAIL 先確認是因為檔案不存在才符合 TDD 順序——這裡因為 composable 已在 Step 1 寫好，這個測試是驗證行為，預期直接 PASS）。

- [ ] **Step 4: 建立作品卡片元件（封面疊字 + 時間角標 + 已加入狀態）**

Write `frontend/app/components/AnimeGridCard.vue`：

```vue
<script setup lang="ts">
import type { Anime } from '../utils/normalize'

const props = defineProps<{
  anime: Anime
  inList: boolean
  watched: boolean
}>()

defineEmits<{ add: [animeId: number] }>()

const badgeColors = ['warning', 'info', 'success', 'error'] as const

function badgeColorFor(animeId: number) {
  return badgeColors[animeId % badgeColors.length]
}

function timeLabel(airDate: string | null): string {
  if (!airDate) return '未定'
  const match = airDate.match(/(?:T|\s)(\d{1,2}:\d{2})/)
  return match ? match[1] : '首播'
}
</script>

<template>
  <button
    type="button"
    class="group relative aspect-[3/4] w-full overflow-hidden rounded-md bg-gray-800 text-left"
    @click="$emit('add', anime.id)"
  >
    <img
      v-if="anime.imageUrl"
      :src="anime.imageUrl"
      :alt="anime.name"
      loading="lazy"
      class="h-full w-full object-cover transition-transform duration-200 group-hover:scale-105"
    />
    <div v-else class="grid h-full w-full place-items-center bg-primary-700 text-3xl font-bold text-white">
      {{ anime.name.slice(0, 1) }}
    </div>

    <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/80 via-black/10 to-transparent" />

    <UBadge
      :color="badgeColorFor(anime.id)"
      class="absolute right-1 top-1"
      size="sm"
    >
      {{ timeLabel(anime.airDate) }}
    </UBadge>

    <UBadge
      v-if="inList"
      :color="watched ? 'success' : 'neutral'"
      class="absolute left-1 top-1"
      size="sm"
    >
      {{ watched ? '已看' : '已加入' }}
    </UBadge>

    <h3 class="absolute inset-x-1 bottom-1 line-clamp-2 text-xs font-bold text-white drop-shadow">
      {{ anime.name }}
    </h3>
  </button>
</template>
```

- [ ] **Step 5: 建立篩選 Slideover 面板**

Write `frontend/app/components/SeasonalFilterPanel.vue`：

```vue
<script setup lang="ts">
import { genreCategories } from '../composables/useSeasonalCatalog'

const props = defineProps<{
  open: boolean
  year: number
  season: string
  category: string
  status: string
  syncResult: { fetched: number; imported: number; skipped: number } | null
  loading: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'update:year': [value: number]
  'update:season': [value: string]
  'update:category': [value: string]
  'update:status': [value: string]
  sync: []
  apply: []
}>()

const seasonOptions = [
  { value: 'winter', label: '冬番（1-3 月）' },
  { value: 'spring', label: '春番（4-6 月）' },
  { value: 'summer', label: '夏番（7-9 月）' },
  { value: 'fall', label: '秋番（10-12 月）' }
]

const statusOptions = [
  { value: 'all', label: '全部作品' },
  { value: 'listed', label: '已加入清單' },
  { value: 'unlisted', label: '未加入清單' },
  { value: 'watched', label: '已看' },
  { value: 'queued', label: '待補' },
  { value: 'with-cover', label: '有封面' }
]
</script>

<template>
  <USlideover :open="open" @update:open="value => emit('update:open', value)">
    <template #content>
      <div class="flex flex-col gap-6 p-4">
        <h2 class="text-lg font-bold">篩選與同步</h2>

        <section class="space-y-2">
          <p class="text-xs font-bold uppercase text-gray-500">季度選擇</p>
          <div class="flex gap-2">
            <UInput
              :model-value="year"
              type="number"
              class="w-24"
              @update:model-value="value => emit('update:year', Number(value))"
            />
            <USelect
              :model-value="season"
              :items="seasonOptions"
              class="flex-1"
              @update:model-value="value => emit('update:season', value)"
            />
          </div>
          <UButton block :loading="loading" @click="emit('sync')">同步新番資料</UButton>
          <div v-if="syncResult" class="flex flex-wrap gap-2 text-xs text-gray-600">
            <span>抓取 {{ syncResult.fetched }}</span>
            <span>匯入 {{ syncResult.imported }}</span>
            <span>略過 {{ syncResult.skipped }}</span>
          </div>
        </section>

        <section class="space-y-2">
          <p class="text-xs font-bold uppercase text-gray-500">分類</p>
          <div class="flex flex-wrap gap-2">
            <UButton
              v-for="genre in genreCategories"
              :key="genre.key"
              size="sm"
              :color="category === genre.key ? 'primary' : 'neutral'"
              :variant="category === genre.key ? 'solid' : 'outline'"
              @click="emit('update:category', genre.key)"
            >
              {{ genre.label }}
            </UButton>
          </div>
        </section>

        <section class="space-y-2">
          <p class="text-xs font-bold uppercase text-gray-500">觀看狀態</p>
          <div class="flex flex-wrap gap-2">
            <UButton
              v-for="option in statusOptions"
              :key="option.value"
              size="sm"
              :color="status === option.value ? 'primary' : 'neutral'"
              :variant="status === option.value ? 'solid' : 'outline'"
              @click="emit('update:status', option.value)"
            >
              {{ option.label }}
            </UButton>
          </div>
        </section>
      </div>
    </template>
  </USlideover>
</template>
```

- [ ] **Step 6: 建立 seasonal 頁面，組裝星期 Tab + 篩選按鈕 + 卡片網格**

Write `frontend/app/pages/seasonal.vue`：

```vue
<script setup lang="ts">
import { weekdayTabs, useSeasonalCatalog } from '../composables/useSeasonalCatalog'
import { normalizeAnime, normalizeListItem } from '../utils/normalize'
import type { Anime, ListItem } from '../utils/normalize'

// ref/computed/reactive/onMounted 由 Nuxt 自動匯入，不需要手動 import 'vue'
const api = useApi()
const { session, isAuthed } = useSession()
const { state: filterState, filterSeasonal, activeFilterCount } = useSeasonalCatalog()

const seasonalControls = reactive({
  year: new Date().getFullYear(),
  season: (() => {
    const month = new Date().getMonth() + 1
    return month <= 3 ? 'winter' : month <= 6 ? 'spring' : month <= 9 ? 'summer' : 'fall'
  })()
})

const seasonal = ref<Anime[]>([])
const list = ref<ListItem[]>([])
const loading = ref(false)
const error = ref('')
const notice = ref('')
const syncResult = ref<{ fetched: number; imported: number; skipped: number } | null>(null)
const filterPanelOpen = ref(false)

const listByAnimeId = computed(() => {
  const map = new Map<number, ListItem>()
  list.value.forEach(item => map.set(item.anime.id, item))
  return map
})

const filteredSeasonal = computed(() => filterSeasonal(seasonal.value, listByAnimeId.value))

async function loadSeasonal() {
  loading.value = true
  error.value = ''
  try {
    const result = await api.searchAnime('', { year: seasonalControls.year, season: seasonalControls.season })
    seasonal.value = (result.items || []).map(normalizeAnime)
  } catch (err: any) {
    error.value = err.message || '載入失敗'
  } finally {
    loading.value = false
  }
}

async function loadMyList() {
  if (!session.token) return
  try {
    const result = await api.myList()
    list.value = (result.items || []).map(normalizeListItem)
  } catch {
    // 清單載入失敗不阻擋新番表瀏覽，沿用既有行為（不顯示清單狀態即可）
  }
}

async function syncSeasonal() {
  if (!isAuthed.value) return navigateTo('/login')

  loading.value = true
  error.value = ''
  try {
    const result = await api.syncSeasonalAnime({ year: Number(seasonalControls.year), season: seasonalControls.season })
    syncResult.value = result.result
    await loadSeasonal()
    notice.value = '新番資料已同步'
  } catch (err: any) {
    error.value = err.message || '同步失敗'
  } finally {
    loading.value = false
  }
}

async function addAnime(animeId: number) {
  if (!isAuthed.value) return navigateTo('/login')
  try {
    await api.addToList(animeId)
    await loadMyList()
    notice.value = '已加入你的清單'
  } catch (err: any) {
    error.value = err.message || '加入失敗'
  }
}

onMounted(async () => {
  await loadSeasonal()
  await loadMyList()
})
</script>

<template>
  <div class="space-y-4">
    <header>
      <p class="text-xs font-bold uppercase text-amber-600">新番表</p>
      <h1 class="text-3xl font-bold">{{ seasonalControls.year }}年 {{ seasonalControls.season }} 新番表</h1>
    </header>

    <UAlert v-if="error" color="error" :title="error" />
    <UAlert v-if="notice && !error" color="success" :title="notice" />

    <div class="flex gap-1 overflow-x-auto border-b border-gray-200 pb-2">
      <UButton
        v-for="tab in weekdayTabs"
        :key="tab.key"
        size="sm"
        :color="filterState.weekday === tab.key ? 'primary' : 'neutral'"
        :variant="filterState.weekday === tab.key ? 'solid' : 'ghost'"
        @click="filterState.weekday = tab.key"
      >
        {{ tab.label }}
      </UButton>
    </div>

    <div class="flex justify-end">
      <UButton color="neutral" variant="outline" icon="i-lucide-sliders-horizontal" @click="filterPanelOpen = true">
        篩選<template v-if="activeFilterCount > 0"> ({{ activeFilterCount }})</template>
      </UButton>
    </div>

    <div v-if="filteredSeasonal.length === 0 && !error" class="rounded-md border border-dashed border-gray-300 p-6 text-center text-gray-500">
      這個篩選條件目前沒有資料，試試切換星期或同步新番資料。
    </div>

    <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-7">
      <AnimeGridCard
        v-for="anime in filteredSeasonal"
        :key="anime.id"
        :anime="anime"
        :in-list="listByAnimeId.has(anime.id)"
        :watched="Boolean(listByAnimeId.get(anime.id)?.watched)"
        @add="addAnime"
      />
    </div>

    <SeasonalFilterPanel
      v-model:open="filterPanelOpen"
      v-model:year="seasonalControls.year"
      v-model:season="seasonalControls.season"
      v-model:category="filterState.seasonalCategory"
      v-model:status="filterState.seasonalStatus"
      :sync-result="syncResult"
      :loading="loading"
      @sync="syncSeasonal"
    />
  </div>
</template>
```

- [ ] **Step 7: 讓首頁重新導向到新番表**

Write `frontend/app/pages/index.vue`：

```vue
<script setup lang="ts">
await navigateTo('/seasonal')
</script>

<template>
  <div />
</template>
```

- [ ] **Step 8: 啟動確認新番表頁面運作**

```bash
cd frontend
npm run dev
```

預期：開 `http://localhost:3000`，自動導向 `/seasonal`，看到星期 Tab 列、右側篩選按鈕、卡片網格（若後端未啟動會顯示連線錯誤訊息，這是預期行為）。

啟動 docker 後端確認真實資料：

```bash
cd ~/anime
docker compose up -d mysql backend
```

重新整理頁面，確認卡片網格能顯示作品（若該季沒資料，點開篩選面板按「同步新番資料」測試）。

- [ ] **Step 9: Commit**

```bash
cd ~/anime
git add frontend/app/pages frontend/app/components frontend/app/composables/useSeasonalCatalog.ts frontend/test/useSeasonalCatalog.test.ts
git commit -m "Add seasonal page with weekday tabs, filter slideover, and poster grid"
```

---

### Task 7: 實作資料庫搜尋頁面（沿用相同卡片網格風格）

**Files:**
- Create: `frontend/app/pages/catalog.vue`
- Create: `frontend/app/components/ManualAnimeForm.vue`

- [ ] **Step 1: 建立手動建立作品表單元件**

Write `frontend/app/components/ManualAnimeForm.vue`：

```vue
<script setup lang="ts">
const props = defineProps<{
  disabled: boolean
  loading: boolean
}>()

const emit = defineEmits<{ submit: [payload: { name: string; description: string; imageUrl: string }] }>()

const form = reactive({ name: '', description: '', imageUrl: '' })

function handleSubmit() {
  emit('submit', { ...form })
  form.name = ''
  form.description = ''
  form.imageUrl = ''
}
</script>

<template>
  <UCard>
    <template #header>
      <h2 class="text-lg font-bold">手動建立</h2>
    </template>

    <form class="space-y-3" @submit.prevent="handleSubmit">
      <UFormField label="名稱" required>
        <UInput v-model="form.name" :disabled="disabled || loading" maxlength="160" placeholder="作品名稱" />
      </UFormField>

      <UFormField label="敘述">
        <UTextarea v-model="form.description" :disabled="disabled || loading" :rows="5" placeholder="補上簡短介紹" />
      </UFormField>

      <UFormField label="圖片 URL">
        <UInput v-model="form.imageUrl" :disabled="disabled || loading" type="url" placeholder="https://..." />
      </UFormField>

      <UButton type="submit" block :disabled="disabled || loading" :loading="loading">
        {{ loading ? '建立中' : '建立動漫資料' }}
      </UButton>
      <p v-if="disabled" class="text-xs text-gray-500">需要登入才能建立資料。</p>
    </form>
  </UCard>
</template>
```

- [ ] **Step 2: 建立 catalog 頁面**

Write `frontend/app/pages/catalog.vue`：

```vue
<script setup lang="ts">
import { normalizeAnime } from '../utils/normalize'
import type { Anime } from '../utils/normalize'

const api = useApi()
const { isAuthed } = useSession()

const query = ref('')
const catalog = ref<Anime[]>([])
const loading = ref(false)
const error = ref('')
const notice = ref('')

async function search() {
  loading.value = true
  error.value = ''
  try {
    const result = await api.searchAnime(query.value)
    catalog.value = (result.items || []).map(normalizeAnime)
  } catch (err: any) {
    error.value = err.message || '搜尋失敗'
  } finally {
    loading.value = false
  }
}

async function addAnime(animeId: number) {
  if (!isAuthed.value) return navigateTo('/login')
  try {
    await api.addToList(animeId)
    notice.value = '已加入你的清單'
  } catch (err: any) {
    error.value = err.message || '加入失敗'
  }
}

async function createAnime(payload: { name: string; description: string; imageUrl: string }) {
  loading.value = true
  error.value = ''
  try {
    const result = await api.createAnime(payload)
    catalog.value = [normalizeAnime(result.item), ...catalog.value]
    notice.value = '已建立作品資料'
  } catch (err: any) {
    error.value = err.message || '建立失敗'
  } finally {
    loading.value = false
  }
}

onMounted(search)
</script>

<template>
  <div class="grid gap-4 md:grid-cols-[1fr_320px]">
    <div class="space-y-4">
      <header class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">搜尋動漫資料庫</h1>
        <UBadge color="neutral">{{ catalog.length }} 筆</UBadge>
      </header>

      <UAlert v-if="error" color="error" :title="error" />
      <UAlert v-if="notice && !error" color="success" :title="notice" />

      <form class="flex gap-2" @submit.prevent="search">
        <UInput v-model="query" class="flex-1" placeholder="例如：芙莉蓮、Bocchi、排球" />
        <UButton type="submit" :loading="loading">搜尋</UButton>
      </form>

      <div v-if="catalog.length === 0 && !error" class="rounded-md border border-dashed border-gray-300 p-6 text-center text-gray-500">
        沒有找到作品，可以換個關鍵字，或在右側手動建立資料。
      </div>

      <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6">
        <AnimeGridCard
          v-for="anime in catalog"
          :key="anime.id"
          :anime="anime"
          :in-list="false"
          :watched="false"
          @add="addAnime"
        />
      </div>
    </div>

    <ManualAnimeForm :disabled="!isAuthed" :loading="loading" @submit="createAnime" />
  </div>
</template>
```

- [ ] **Step 3: 啟動確認**

```bash
cd frontend
npm run dev
```

預期：開 `http://localhost:3000/catalog`，看到搜尋框、手動建立表單、卡片網格，視覺風格與 seasonal 頁一致。

- [ ] **Step 4: Commit**

```bash
cd ~/anime
git add frontend/app/pages/catalog.vue frontend/app/components/ManualAnimeForm.vue
git commit -m "Add catalog page reusing the poster grid style"
```

---

### Task 8: 實作我的清單頁面（保留條列式排版）

**Files:**
- Create: `frontend/app/pages/list/index.vue`
- Create: `frontend/app/components/ListItemRow.vue`
- Create: `frontend/app/middleware/auth.ts`

- [ ] **Step 1: 建立路由保護 middleware**

Write `frontend/app/middleware/auth.ts`：

```typescript
export default defineNuxtRouteMiddleware(() => {
  const { isAuthed } = useSession()
  if (!isAuthed.value) {
    return navigateTo('/login')
  }
})
```

- [ ] **Step 2: 建立清單項目條列元件**

Write `frontend/app/components/ListItemRow.vue`：

```vue
<script setup lang="ts">
import type { ListItem } from '../utils/normalize'

const props = defineProps<{
  item: ListItem
  disabled: boolean
}>()

const emit = defineEmits<{
  update: [patch: Record<string, any>]
  remove: []
}>()

const confirmingRemove = ref(false)

const ratingOptions = Array.from({ length: 10 }, (_, i) => ({ value: String(i + 1), label: `${i + 1} 分` }))

function updateWatched(value: boolean) {
  emit('update', { watched: value })
}

function updateRating(value: string) {
  emit('update', { rating: value ? Number(value) : null })
}

function updateNote(value: string) {
  emit('update', { note: value })
}
</script>

<template>
  <UCard>
    <div class="grid grid-cols-[88px_1fr] gap-3 sm:grid-cols-[110px_1fr_180px]">
      <img
        v-if="item.anime.imageUrl"
        :src="item.anime.imageUrl"
        :alt="item.anime.name"
        loading="lazy"
        class="aspect-[3/4] w-full rounded-md object-cover"
      />
      <div v-else class="grid aspect-[3/4] w-full place-items-center rounded-md bg-primary-600 text-2xl font-bold text-white">
        {{ item.anime.name.slice(0, 1) }}
      </div>

      <div class="space-y-1">
        <UBadge :color="item.watched ? 'success' : 'neutral'">
          {{ item.watched ? '已看完' : '待補完' }}
        </UBadge>
        <h3 class="text-lg font-bold">{{ item.anime.name }}</h3>
        <p class="line-clamp-2 text-sm text-gray-500">{{ item.anime.description }}</p>
        <UTextarea
          :model-value="item.note"
          :rows="2"
          :disabled="disabled"
          placeholder="記錄集數進度、推薦理由或心得"
          @change="event => updateNote((event.target as HTMLTextAreaElement).value)"
        />
      </div>

      <div class="col-span-2 flex flex-wrap items-center gap-3 sm:col-span-1 sm:flex-col sm:items-stretch">
        <USwitch :model-value="item.watched" :disabled="disabled" label="已看" @update:model-value="updateWatched" />

        <USelect
          :model-value="item.rating ? String(item.rating) : ''"
          :items="[{ value: '', label: '未評分' }, ...ratingOptions]"
          :disabled="disabled"
          @update:model-value="updateRating"
        />

        <UButton
          v-if="!confirmingRemove"
          color="error"
          variant="outline"
          :disabled="disabled"
          @click="confirmingRemove = true"
        >
          移除
        </UButton>
        <div v-else class="flex gap-2">
          <UButton color="neutral" variant="ghost" :disabled="disabled" @click="confirmingRemove = false">取消</UButton>
          <UButton color="error" :disabled="disabled" @click="confirmingRemove = false; $emit('remove')">確認移除</UButton>
        </div>
      </div>
    </div>
  </UCard>
</template>
```

- [ ] **Step 3: 建立我的清單頁面**

Write `frontend/app/pages/list/index.vue`：

```vue
<script setup lang="ts">
import { normalizeListItem } from '../../utils/normalize'
import type { ListItem } from '../../utils/normalize'

definePageMeta({ middleware: 'auth' })

const api = useApi()
const route = useRoute()

const list = ref<ListItem[]>([])
const loading = ref(false)
const error = ref('')
const notice = ref('')

const activeFilter = computed(() => (route.query.filter as string) || 'all')

const filteredList = computed(() => {
  if (activeFilter.value === 'watched') return list.value.filter(item => item.watched)
  if (activeFilter.value === 'unwatched') return list.value.filter(item => !item.watched)
  return list.value
})

async function loadList() {
  loading.value = true
  error.value = ''
  try {
    const result = await api.myList()
    list.value = (result.items || []).map(normalizeListItem)
  } catch (err: any) {
    error.value = err.message || '載入失敗'
  } finally {
    loading.value = false
  }
}

async function updateItem(item: ListItem, patch: Record<string, any>) {
  try {
    const result = await api.updateListItem(item.id, patch)
    const index = list.value.findIndex(existing => existing.id === item.id)
    if (index >= 0) list.value[index] = normalizeListItem(result.item)
    notice.value = '清單已更新'
  } catch (err: any) {
    error.value = err.message || '更新失敗'
  }
}

async function removeItem(item: ListItem) {
  try {
    await api.deleteListItem(item.id)
    list.value = list.value.filter(existing => existing.id !== item.id)
    notice.value = '已從清單移除'
  } catch (err: any) {
    error.value = err.message || '移除失敗'
  }
}

onMounted(loadList)
</script>

<template>
  <div class="space-y-4">
    <header class="flex items-center justify-between">
      <h1 class="text-2xl font-bold">我的清單</h1>
      <UTabs
        :model-value="activeFilter"
        :items="[
          { value: 'all', label: '全部', to: '/list' },
          { value: 'watched', label: '已看', to: '/list?filter=watched' },
          { value: 'unwatched', label: '未看', to: '/list?filter=unwatched' }
        ]"
      />
    </header>

    <UAlert v-if="error" color="error" :title="error" />
    <UAlert v-if="notice && !error" color="success" :title="notice" />

    <div v-if="filteredList.length === 0" class="rounded-md border border-dashed border-gray-300 p-6 text-center text-gray-500">
      清單目前是空的，先到資料庫搜尋作品，再加入你的追番清單。
      <UButton class="mt-3" to="/catalog">去搜尋作品</UButton>
    </div>

    <div class="space-y-3">
      <ListItemRow
        v-for="item in filteredList"
        :key="item.id"
        :item="item"
        :disabled="loading"
        @update="patch => updateItem(item, patch)"
        @remove="removeItem(item)"
      />
    </div>
  </div>
</template>
```

- [ ] **Step 4: 啟動確認（需登入才能存取）**

```bash
cd frontend
npm run dev
```

未登入直接訪問 `http://localhost:3000/list`，預期自動導向 `/login`。

- [ ] **Step 5: Commit**

```bash
cd ~/anime
git add frontend/app/pages/list frontend/app/components/ListItemRow.vue frontend/app/middleware/auth.ts
git commit -m "Add my-list page with auth middleware and Nuxt UI list rows"
```

---

### Task 9: 實作登入、設定、公開分享頁

**Files:**
- Create: `frontend/app/pages/login.vue`
- Create: `frontend/app/pages/settings.vue`
- Create: `frontend/app/pages/public/[slug].vue`

這三頁不重新設計版面骨架，只用 Nuxt UI 元件重新實作既有結構。

- [ ] **Step 1: 建立登入頁**

Write `frontend/app/pages/login.vue`：

```vue
<script setup lang="ts">
const api = useApi()
const { setSession } = useSession()
const config = useRuntimeConfig()

const error = ref('')

declare global {
  interface Window {
    google?: any
  }
}

async function afterLogin(result: { token: string; user: any }) {
  setSession(result.token, result.user)
  await navigateTo('/list')
}

async function handleCredentialResponse(response: { credential: string }) {
  try {
    const result = await api.login(response.credential)
    await afterLogin(result)
  } catch (err: any) {
    error.value = err.message || '登入失敗'
  }
}

async function devLogin() {
  try {
    const result = await api.login('dev:dev@example.com')
    await afterLogin(result)
  } catch (err: any) {
    error.value = err.message || '登入失敗'
  }
}

function renderGoogleButton() {
  if (!window.google || !config.public.googleClientId) return
  window.google.accounts.id.initialize({
    client_id: config.public.googleClientId,
    callback: handleCredentialResponse
  })
  const target = document.getElementById('google-signin')
  if (target && target.childElementCount === 0) {
    window.google.accounts.id.renderButton(target, { theme: 'outline', size: 'large', width: 280 })
  }
}

onMounted(() => {
  const script = document.createElement('script')
  script.src = 'https://accounts.google.com/gsi/client'
  script.async = true
  script.defer = true
  script.onload = renderGoogleButton
  document.head.appendChild(script)
})
</script>

<template>
  <UCard class="mx-auto max-w-lg">
    <template #header>
      <h1 class="text-xl font-bold">使用 Google 登入你的追番清單</h1>
    </template>

    <p class="text-sm text-gray-600">後端會驗證 Google ID token，並簽發短效 JWT。前端只保存登入狀態，不保存 Google 密碼。</p>

    <UAlert v-if="error" color="error" :title="error" class="mt-4" />

    <div id="google-signin" class="my-4 min-h-[54px]" />

    <UButton v-if="config.public.enableDevLogin" block variant="outline" @click="devLogin">
      開發模式登入
    </UButton>
  </UCard>
</template>
```

- [ ] **Step 2: 建立設定頁**

Write `frontend/app/pages/settings.vue`：

```vue
<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const api = useApi()
const { session, setUser } = useSession()

const notice = ref('')
const error = ref('')

const shareUrl = computed(() => {
  if (typeof window === 'undefined' || !session.user) return ''
  return `${window.location.origin}/public/${session.user.public_slug}`
})

async function copyShareUrl() {
  if (!shareUrl.value) return
  await navigator.clipboard.writeText(shareUrl.value)
  notice.value = '分享連結已複製'
}

async function regenerateSlug() {
  try {
    const result = await api.regenerateSlug()
    setUser(result.user)
    notice.value = '分享連結已更新'
  } catch (err: any) {
    error.value = err.message || '更新失敗'
  }
}
</script>

<template>
  <div class="grid gap-4 md:grid-cols-2">
    <UCard class="text-center">
      <img
        v-if="session.user?.avatar_url"
        :src="session.user.avatar_url"
        alt=""
        class="mx-auto mb-3 h-20 w-20 rounded-full object-cover"
      >
      <div v-else class="mx-auto mb-3 grid h-20 w-20 place-items-center rounded-full bg-primary-50 text-primary-600">
        <UIcon name="i-lucide-user-circle" class="size-10" />
      </div>
      <h2 class="text-lg font-bold">{{ session.user?.display_name || '未命名使用者' }}</h2>
      <p class="text-sm text-gray-500">{{ session.user?.email }}</p>
    </UCard>

    <UCard>
      <h2 class="mb-2 text-lg font-bold">公開清單連結</h2>
      <UAlert v-if="error" color="error" :title="error" class="mb-3" />
      <UAlert v-if="notice && !error" color="success" :title="notice" class="mb-3" />
      <p class="break-all rounded-md bg-gray-50 p-2 font-mono text-xs text-gray-600">{{ shareUrl }}</p>
      <div class="mt-3 flex flex-wrap gap-2">
        <UButton variant="outline" @click="copyShareUrl">複製連結</UButton>
        <UButton variant="ghost" @click="regenerateSlug">重新產生</UButton>
        <UButton variant="ghost" :to="`/public/${session.user?.public_slug}`">預覽公開清單</UButton>
      </div>
    </UCard>
  </div>
</template>
```

- [ ] **Step 3: 建立公開分享頁**

Write `frontend/app/pages/public/[slug].vue`：

```vue
<script setup lang="ts">
import { normalizeListItem } from '../../utils/normalize'
import type { ListItem } from '../../utils/normalize'

const route = useRoute()
const api = useApi()

const publicUser = ref<any>(null)
const items = ref<ListItem[]>([])
const error = ref('')

async function load() {
  try {
    const result = await api.publicList(route.params.slug as string)
    publicUser.value = result.user
    items.value = (result.items || []).map(normalizeListItem)
  } catch (err: any) {
    error.value = err.message || '載入失敗'
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-4">
    <header class="flex items-center justify-between">
      <h1 class="text-2xl font-bold">{{ publicUser?.display_name || '使用者' }} 的公開清單</h1>
      <UBadge color="neutral">{{ items.length }} 筆</UBadge>
    </header>

    <UAlert v-if="error" color="error" :title="error" />

    <div v-if="items.length === 0 && !error" class="rounded-md border border-dashed border-gray-300 p-6 text-center text-gray-500">
      這份公開清單目前沒有作品。
    </div>

    <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6">
      <AnimeGridCard
        v-for="item in items"
        :key="item.id"
        :anime="item.anime"
        :in-list="false"
        :watched="item.watched"
        @add="() => {}"
      />
    </div>
  </div>
</template>
```

- [ ] **Step 4: 啟動確認三頁都能渲染**

```bash
cd frontend
npm run dev
```

訪問 `/login`、`/settings`（未登入應導向 `/login`）、`/public/anything`（會顯示 API 錯誤訊息，這是預期行為因為該 slug 不存在）。

- [ ] **Step 5: Commit**

```bash
cd ~/anime
git add frontend/app/pages/login.vue frontend/app/pages/settings.vue frontend/app/pages/public
git commit -m "Add login, settings, and public list pages"
```

---

### Task 10: 移除 lucide-vue-next 相依，全面改用 Nuxt UI 內建 icon

**Files:**
- Modify: `frontend/package.json`
- Modify: 所有用到 `UIcon` 的元件（已在前面任務完成，這裡只需確認沒有殘留 `lucide-vue-next` import）

- [ ] **Step 1: 搜尋是否還有殘留的 lucide-vue-next import**

```bash
cd frontend
grep -rl "lucide-vue-next" app/ || echo "no matches"
```

預期：`no matches`（因為前面所有新元件都用 `UIcon name="i-lucide-*"` 而非 import）。若有殘留，逐一改為 `UIcon`。

- [ ] **Step 2: 從 package.json 移除依賴**

Edit `frontend/package.json`，移除 `"lucide-vue-next": "^1.0.0",` 這一行。

- [ ] **Step 3: 確認 @iconify-json/lucide 已安裝（@nuxt/ui 通常自動處理，這裡顯式安裝避免 icon 顯示為空白）**

```bash
npm install -D @iconify-json/lucide
npm install
```

- [ ] **Step 4: 啟動確認所有頁面 icon 正常顯示**

```bash
npm run dev
```

逐一檢查 `/`、`/catalog`、`/list`（登入後）、`/login`、`/settings` 的 icon 是否正常顯示（非空白方塊）。

- [ ] **Step 5: Commit**

```bash
cd ~/anime
git add frontend/package.json frontend/package-lock.json
git commit -m "Remove lucide-vue-next, use Nuxt UI built-in icons"
```

---

### Task 11: 更新環境變數與 Docker 設定

**Files:**
- Modify: `.env.example`
- Modify: `docker-compose.yml`
- Modify: `frontend/index.html` → 刪除（Nuxt 不需要手寫 index.html，由 `app.vue` + `nuxt.config.ts` 的 `app.head` 取代）
- Modify: `frontend/nuxt.config.ts`

- [ ] **Step 1: 在 nuxt.config.ts 設定網頁標題與 meta（取代 index.html 的內容）**

Edit `frontend/nuxt.config.ts`，加入 `app` 設定：

```typescript
export default defineNuxtConfig({
  compatibilityDate: '2026-06-29',
  devtools: { enabled: true },
  ssr: false,
  modules: ['@nuxt/ui'],
  app: {
    head: {
      title: '動漫庫',
      htmlAttrs: { lang: 'zh-Hant' },
      meta: [{ name: 'viewport', content: 'width=device-width, initial-scale=1.0' }]
    }
  },
  runtimeConfig: {
    public: {
      apiBaseUrl: process.env.NUXT_PUBLIC_API_BASE_URL || 'http://localhost:8080',
      googleClientId: process.env.NUXT_PUBLIC_GOOGLE_CLIENT_ID || '',
      enableDevLogin: process.env.NUXT_PUBLIC_ENABLE_DEV_LOGIN === 'true'
    }
  }
})
```

- [ ] **Step 2: 更新 .env.example 的前端變數命名**

Read `~/anime/.env.example`，用 Edit 把：

改為：

```
NUXT_PUBLIC_API_BASE_URL=http://localhost:8080
NUXT_PUBLIC_GOOGLE_CLIENT_ID=your-google-oauth-client-id.apps.googleusercontent.com
NUXT_PUBLIC_ALLOWED_ORIGINS=http://localhost:3000,https://your-github-user.github.io
NUXT_PUBLIC_GOOGLE_SECRET=your-google-oauth-client-secret
```

- [ ] **Step 3: 更新 docker-compose.yml 的 frontend service**

Read 目前的 `docker-compose.yml`，用 Edit 把 frontend service 區塊改為：

```yaml
  frontend:
    image: node:22-alpine
    working_dir: /app
    command: sh -c "npm install && npm run dev"
    environment:
      NUXT_PUBLIC_API_BASE_URL: http://localhost:8080
      NUXT_PUBLIC_GOOGLE_CLIENT_ID: ${GOOGLE_CLIENT_ID:-}
      NUXT_PUBLIC_ENABLE_DEV_LOGIN: "true"
      CHOKIDAR_USEPOLLING: "true"
      WATCHPACK_POLLING: "true"
    ports:
      - "3000:3000"
    volumes:
      - ./frontend:/app
      - frontend-node-modules:/app/node_modules
    depends_on:
      - backend
```

注意：image 從 `node:20-alpine` 改成 `node:22-alpine`（Nuxt 4 工具鏈要求 Node 22+），port 從 `5173:5173` 變成 `3000:3000`（Nuxt 預設 port），環境變數前綴從 `VITE_` 改成 `NUXT_PUBLIC_`，其他部分不變。

- [ ] **Step 4: 刪除不再需要的 index.html 與 vite.config.js**

```bash
cd ~/anime
rm frontend/index.html frontend/vite.config.js
```

- [ ] **Step 5: 啟動 docker compose 整套服務確認**

```bash
docker compose up --build
```

預期：mysql、backend、frontend、phpmyadmin 都成功啟動。瀏覽器開 `http://localhost:3000` 看到網站正常運作，title 顯示「動漫庫」。Ctrl+C 停止。

- [ ] **Step 6: Commit**

```bash
git add .env.example docker-compose.yml frontend/nuxt.config.ts
git add -u frontend/index.html frontend/vite.config.js
git commit -m "Update env vars and docker-compose for Nuxt dev server"
```

---

### Task 12: 移除舊版 Vue SPA 檔案

**Files:**
- Delete: `frontend/src/`（整個目錄）

這一步要等 Task 6-11 都驗證過新版頁面行為正確後才執行，因為 `frontend/src/` 在過渡期間是備用參考，沒有任何程式碼引用它（`package.json` 的 build 指令在 Task 2 已經指向 Nuxt）。

- [ ] **Step 1: 確認沒有任何 Nuxt 檔案引用 src/ 底下的東西**

```bash
cd frontend
grep -rl "from '\.\./src\|from '\./src\|/src/" app/ nuxt.config.ts 2>/dev/null || echo "no references"
```

預期：`no references`。

- [ ] **Step 2: 刪除舊版 Vue SPA 目錄**

```bash
cd ~/anime
rm -rf frontend/src
```

- [ ] **Step 3: 確認 Nuxt 仍可正常啟動與建置**

```bash
cd frontend
npm run dev
```

開瀏覽器確認首頁、catalog、list、login、settings 都正常。Ctrl+C 停止後再跑：

```bash
npm run build
```

預期：build 成功完成，產生 `.output/` 目錄，無 error。

- [ ] **Step 4: 跑全部單元測試確認沒有遺漏的舊測試引用**

```bash
npm run test
```

預期：全部 PASS（舊的 `ui-contract.test.mjs` 已隨 `frontend/src/` 一併刪除，新測試是 Task 3-6 寫的 vitest 測試）。

- [ ] **Step 5: Commit**

```bash
cd ~/anime
git add -u frontend/src
git commit -m "Remove legacy Vue SPA files after Nuxt migration"
```

---

### Task 13: 整套手動驗收（RWD 與功能迴歸檢查）

**Files:** 無程式碼變更，純驗證

- [ ] **Step 1: 啟動完整環境**

```bash
cd ~/anime
docker compose up --build
```

- [ ] **Step 2: 桌機寬度（1440px）逐頁檢查**

開瀏覽器設定視窗寬度約 1440px，依序檢查：
- `/seasonal`：星期 Tab 列獨立一行可點擊切換，下方另起一行的篩選按鈕點擊後從右側滑出面板，面板內年份/季度/分類/狀態/同步按鈕都可操作。
- `/catalog`：搜尋框可搜尋，卡片網格風格與 seasonal 頁一致，右側手動建立表單可送出。
- `/list`（先用開發登入按鈕登入）：條列卡片顯示評分/已看開關/備註欄/移除確認流程，全部/已看/未看分頁籤可切換並反映在 URL query。
- `/settings`：頭像、公開連結顯示與複製、重新產生 slug 都正常。

- [ ] **Step 3: 手機寬度（375px）逐頁檢查**

用瀏覽器開發者工具切到 375px 寬度，重複 Step 2 的檢查重點，額外確認：
- 底部固定 5 項導覽列顯示正常，桌機寬度下應隱藏（回到 1440px 確認）。
- 星期 Tab 列可橫向滑動，不造成整頁橫向捲動。
- 卡片網格在窄螢幕下降為 3 欄，無破版。
- 我的清單條列卡片改為直向堆疊，控制項不溢出螢幕。

- [ ] **Step 4: 功能迴歸檢查**

- 開發登入（`devLogin`）可成功登入並導向 `/list`。
- 未登入直接訪問 `/list`、`/settings` 會被導回 `/login`。
- 在 `/seasonal` 點擊「同步新番資料」會呼叫後端 API 並更新畫面（檢查瀏覽器 Network tab 確認打到 `/anime/sync-seasonal`）。
- 在 `/catalog` 或 `/seasonal` 點擊作品卡片可成功加入清單，已加入的作品在 seasonal 頁顯示對應角標。
- 登出後 localStorage 的 `animeTrackerSession` 被清除。

- [ ] **Step 5: 記錄驗收結果**

若全部檢查項目通過，這個任務視為完成，可進入收尾。若發現問題，回到對應的 Task 修正，不需要重新執行整個計畫。

---

## 收尾

完成 Task 13 後，使用 superpowers:finishing-a-development-branch 決定後續整合方式（直接在 main 上保留這些 commit，或視團隊習慣決定是否要再做別的整理）。
