<script setup lang="ts">
import type { AuthResponse } from '../types/api'
import { apiErrorMessage } from '../utils/apiError'

useSeoMeta({ robots: 'noindex, nofollow' })

const api = useApi()
const { setSession } = useSession()
const config = useRuntimeConfig()
const toast = useToast()
const googleStatus = ref<'loading' | 'ready' | 'missing-config' | 'error'>('loading')
let googleLoadTimer: ReturnType<typeof setTimeout> | undefined

declare global {
  interface Window {
    google?: {
      accounts: {
        id: {
          initialize(options: {
            client_id: string
            callback: (response: GoogleCredentialResponse) => void
          }): void
          renderButton(target: HTMLElement, options: {
            theme: 'outline'
            size: 'large'
            shape: 'pill'
            width: number
          }): void
        }
      }
    }
  }
}

interface GoogleCredentialResponse {
  credential: string
}

async function afterLogin(result: AuthResponse) {
  setSession(result.token, result.refreshToken, result.user)
  await navigateTo('/list')
}

async function handleCredentialResponse(response: GoogleCredentialResponse) {
  try {
    const result = await api.login(response.credential)
    await afterLogin(result)
  } catch (err: unknown) {
    toast.add({ title: apiErrorMessage(err, '登入失敗'), color: 'error' })
  }
}

async function devLogin() {
  try {
    const result = await api.login('dev:dev@example.com')
    await afterLogin(result)
  } catch (err: unknown) {
    toast.add({ title: apiErrorMessage(err, '登入失敗'), color: 'error' })
  }
}

function renderGoogleButton() {
  if (!config.public.googleClientId) {
    googleStatus.value = 'missing-config'
    return
  }
  if (!window.google) {
    googleStatus.value = 'error'
    return
  }

  try {
    window.google.accounts.id.initialize({
      client_id: config.public.googleClientId,
      callback: handleCredentialResponse
    })
    const target = document.getElementById('google-signin')
    if (!target) return
    target.replaceChildren()
    window.google.accounts.id.renderButton(target, {
      theme: 'outline',
      size: 'large',
      shape: 'pill',
      width: Math.min(320, target.clientWidth || 320)
    })
    googleStatus.value = target.childElementCount > 0 ? 'ready' : 'error'
    clearTimeout(googleLoadTimer)
  } catch {
    googleStatus.value = 'error'
  }
}

function loadGoogleButton() {
  if (!config.public.googleClientId) {
    googleStatus.value = 'missing-config'
    return
  }

  googleStatus.value = 'loading'
  if (window.google) {
    renderGoogleButton()
    return
  }

  let script = document.getElementById('google-gsi-script') as HTMLScriptElement | null
  let shouldAppendScript = false
  if (!script) {
    script = document.createElement('script')
    script.id = 'google-gsi-script'
    script.src = 'https://accounts.google.com/gsi/client'
    script.async = true
    script.defer = true
    shouldAppendScript = true
  }

  script.addEventListener('load', renderGoogleButton, { once: true })
  script.addEventListener('error', () => {
    clearTimeout(googleLoadTimer)
    googleStatus.value = 'error'
  }, { once: true })
  if (shouldAppendScript) document.head.appendChild(script)

  clearTimeout(googleLoadTimer)
  googleLoadTimer = setTimeout(() => {
    if (googleStatus.value === 'loading') googleStatus.value = 'error'
  }, 8000)
}

function retryGoogleButton() {
  document.getElementById('google-gsi-script')?.remove()
  loadGoogleButton()
}

onMounted(loadGoogleButton)

onUnmounted(() => {
  clearTimeout(googleLoadTimer)
})
</script>

<template>
  <div class="relative -mx-4 -mt-6 flex min-h-[calc(100dvh-4rem)] items-center justify-center overflow-hidden px-4 py-12">
    <!-- Ambient background: soft glow blobs -->
    <div class="pointer-events-none absolute inset-0 -z-10 bg-gray-50" />
    <div class="pointer-events-none absolute -top-32 left-1/2 -z-10 size-128 -translate-x-1/2 rounded-full bg-primary-100/70 blur-3xl" />
    <div class="pointer-events-none absolute -top-24 -right-24 -z-10 size-96 rounded-full bg-primary-200/40 blur-3xl" />
    <div class="pointer-events-none absolute -bottom-32 -left-24 -z-10 size-96 rounded-full bg-primary-100/60 blur-3xl" />

    <div class="w-full max-w-sm">
      <!-- Brand mark -->
      <div class="mb-8 flex flex-col items-center text-center">
        <img src="/favicon-180.png" alt="Anime Library" class="size-14 rounded-2xl object-cover shadow-lg shadow-primary-600/25">
        <h1 class="mt-5 text-[26px] font-extrabold leading-tight tracking-tight text-gray-950">
          歡迎回來，繼續你的追番之旅
        </h1>
        <p class="mt-2 text-sm text-gray-500">登入動漫庫，同步你的收藏與進度</p>
      </div>

      <!-- Sign-in card -->
      <div class="rounded-2xl border border-gray-200/80 bg-white/90 p-7 shadow-xl shadow-gray-900/5 backdrop-blur-sm">
        <div class="relative min-h-[46px]">
          <div id="google-signin" class="flex min-h-[46px] justify-center" />

          <div v-if="googleStatus === 'loading'" class="absolute inset-0 flex items-center justify-center gap-2 text-sm text-gray-500">
            <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
            正在載入 Google 登入…
          </div>

          <div v-else-if="googleStatus === 'missing-config'" class="absolute inset-0 flex items-center justify-center text-center text-sm text-red-600">
            Google 登入尚未設定，請檢查 Client ID。
          </div>

          <div v-else-if="googleStatus === 'error'" class="absolute inset-0 flex items-center justify-center">
            <UButton variant="outline" color="neutral" icon="i-lucide-refresh-cw" @click="retryGoogleButton">
              重新載入 Google 登入
            </UButton>
          </div>
        </div>

        <div v-if="config.public.enableDevLogin" class="mt-4">
          <div class="mb-4 flex items-center gap-3 text-[11px] font-semibold uppercase tracking-wider text-gray-400">
            <span class="h-px flex-1 bg-gray-200" />
            開發用
            <span class="h-px flex-1 bg-gray-200" />
          </div>
          <UButton block variant="outline" color="neutral" @click="devLogin">
            開發模式登入
          </UButton>
        </div>

        <p class="mt-6 text-center text-xs leading-relaxed text-gray-400">
          我們只讀取你的 Google 帳號基本資料換發登入憑證，<br class="hidden sm:inline">不會取得或保存你的 Google 密碼。
        </p>
      </div>
    </div>
  </div>
</template>
