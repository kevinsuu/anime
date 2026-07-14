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

function readStoredSession(): { token?: string; refreshToken?: string; user?: SessionUser } {
  if (typeof window === 'undefined') return {}
  try {
    const value = JSON.parse(localStorage.getItem(SESSION_KEY) || '{}')
    return value && typeof value === 'object' ? value : {}
  } catch {
    localStorage.removeItem(SESSION_KEY)
    return {}
  }
}

const session = reactive({
  token: '',
  refreshToken: '',
  user: null as SessionUser | null
})

let hydrated = false

const isAuthed = computed(() => Boolean(session.token && session.user))

export function useSession() {
  // Lazily hydrate from localStorage on first client-side use, since this
  // module can be imported before `window` exists (e.g. Nuxt's server-side
  // module resolution even with ssr:false).
  if (!hydrated && typeof window !== 'undefined') {
    hydrated = true
    const stored = readStoredSession()
    session.token = stored.token || ''
    session.refreshToken = stored.refreshToken || ''
    session.user = stored.user || null
  }

  function save() {
    localStorage.setItem(SESSION_KEY, JSON.stringify({
      token: session.token,
      refreshToken: session.refreshToken,
      user: session.user
    }))
  }

  function setSession(token: string, refreshToken: string, user: SessionUser) {
    session.token = token
    session.refreshToken = refreshToken
    session.user = user
    save()
  }

  function setUser(user: SessionUser) {
    session.user = user
    save()
  }

  function updateTokens(token: string, refreshToken: string) {
    session.token = token
    session.refreshToken = refreshToken
    save()
  }

  function clearSession() {
    session.token = ''
    session.refreshToken = ''
    session.user = null
    localStorage.removeItem(SESSION_KEY)
  }

  return {
    session,
    isAuthed,
    setSession,
    setUser,
    updateTokens,
    clearSession
  }
}
