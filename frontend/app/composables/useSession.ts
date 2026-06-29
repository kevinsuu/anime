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
