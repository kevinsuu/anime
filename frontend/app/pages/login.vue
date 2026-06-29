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
