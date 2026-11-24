import { markSessionReady } from '../composables/useSession'

// Login-dependent UI waits for this so the first client render matches the
// server-rendered (signed-out) HTML; see useSession.ts.
export default defineNuxtPlugin((nuxtApp) => {
  nuxtApp.hook('app:mounted', () => {
    markSessionReady()
  })
})
