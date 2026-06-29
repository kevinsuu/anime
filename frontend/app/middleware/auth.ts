export default defineNuxtRouteMiddleware(() => {
  const { isAuthed } = useSession()
  if (!isAuthed.value) {
    return navigateTo('/login')
  }
})
