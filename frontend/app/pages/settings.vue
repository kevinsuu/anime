<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const api = useApi()
const { session, setUser, clearSession } = useSession()
const toast = useToast()

const copied = ref(false)

const shareUrl = computed(() => {
  if (typeof window === 'undefined' || !session.user) return ''
  return `${window.location.origin}/public/${session.user.public_slug}`
})

async function copyShareUrl() {
  if (!shareUrl.value) return
  await navigator.clipboard.writeText(shareUrl.value)
  copied.value = true
  toast.add({ title: '分享連結已複製', color: 'success' })
  setTimeout(() => { copied.value = false }, 2000)
}

async function regenerateSlug() {
  try {
    const result = await api.regenerateSlug()
    setUser(result.user)
    toast.add({ title: '分享連結已更新', color: 'success' })
  } catch (err: any) {
    toast.add({ title: err.message || '更新失敗', color: 'error' })
  }
}

async function logout() {
  try {
    await api.logout()
  } catch {
    // best-effort: still clear local session even if the server call fails
  }
  clearSession()
  await navigateTo('/')
}
</script>

<template>
  <div class="space-y-6">
    <header class="space-y-1">
      <p class="text-xs font-extrabold uppercase tracking-widest text-primary-600">帳號</p>
      <h1 class="text-3xl font-extrabold tracking-tight text-gray-950">設定</h1>
    </header>

    <div class="grid gap-4 md:grid-cols-2">
      <!-- Profile card -->
      <div class="flex flex-col items-center gap-4 rounded-xl border border-gray-200 bg-white p-8 shadow-sm text-center">
        <div class="relative">
          <img
            v-if="session.user?.avatar_url"
            :src="session.user.avatar_url"
            alt=""
            referrerpolicy="no-referrer"
            class="h-24 w-24 rounded-full object-cover ring-4 ring-gray-100"
          >
          <div
            v-else
            class="flex h-24 w-24 items-center justify-center rounded-full bg-primary-50 ring-4 ring-gray-100"
          >
            <UIcon name="i-lucide-user" class="size-10 text-primary-400" />
          </div>
        </div>
        <div>
          <h2 class="text-lg font-bold text-gray-950">{{ session.user?.display_name || '未命名使用者' }}</h2>
          <p class="mt-0.5 text-sm text-gray-500">{{ session.user?.email }}</p>
        </div>
        <button
          class="mt-2 rounded-lg border border-red-200 px-5 py-2 text-sm font-semibold text-red-500 transition hover:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-400"
          @click="logout"
        >
          登出
        </button>
      </div>

      <!-- Share link card -->
      <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
        <div>
          <h2 class="text-base font-bold text-gray-950">公開清單連結</h2>
          <p class="mt-0.5 text-xs text-gray-500">把你的追番清單分享給朋友</p>
        </div>

        <div class="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5">
          <span class="min-w-0 flex-1 truncate font-mono text-xs text-gray-700">{{ shareUrl }}</span>
          <button
            class="shrink-0 rounded-md px-2.5 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
            :class="copied ? 'bg-green-100 text-green-700' : 'bg-white border border-gray-200 text-gray-700 hover:bg-gray-100'"
            @click="copyShareUrl"
          >
            {{ copied ? '已複製' : '複製' }}
          </button>
        </div>

        <div class="flex flex-wrap gap-2 pt-1">
          <button
            class="rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
            @click="regenerateSlug"
          >
            重新產生連結
          </button>
          <NuxtLink
            :to="`/public/${session.user?.public_slug}`"
            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
          >
            <UIcon name="i-lucide-external-link" class="size-3.5" />
            預覽公開清單
          </NuxtLink>
        </div>
      </div>
    </div>
  </div>
</template>
