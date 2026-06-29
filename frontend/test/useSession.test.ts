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
