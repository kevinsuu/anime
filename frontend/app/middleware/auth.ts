export default defineNuxtRouteMiddleware((to) => {
  const { isAuthed } = useSession()
  if (!isAuthed.value) {
    // Remember where the visitor was heading so login can bring them back.
    return navigateTo({ path: '/login', query: { redirect: to.fullPath } })
  }
})
