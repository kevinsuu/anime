const MANUAL_CREATE_ALLOWED_EMAILS = ['REDACTED_EMAIL']

export function useCatalogAccess() {
  const { session, isAuthed } = useSession()

  const canManuallyCreateAnime = computed(() =>
    isAuthed.value && MANUAL_CREATE_ALLOWED_EMAILS.includes(session.user?.email || '')
  )

  return { canManuallyCreateAnime }
}
