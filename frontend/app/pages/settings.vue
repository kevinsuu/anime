<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const api = useApi()
const { session, setUser } = useSession()

const notice = ref('')
const error = ref('')

const shareUrl = computed(() => {
  if (typeof window === 'undefined' || !session.user) return ''
  return `${window.location.origin}/public/${session.user.public_slug}`
})

async function copyShareUrl() {
  if (!shareUrl.value) return
  await navigator.clipboard.writeText(shareUrl.value)
  notice.value = '分享連結已複製'
}

async function regenerateSlug() {
  try {
    const result = await api.regenerateSlug()
    setUser(result.user)
    notice.value = '分享連結已更新'
  } catch (err: any) {
    error.value = err.message || '更新失敗'
  }
}
</script>

<template>
  <div class="grid gap-4 md:grid-cols-2">
    <UCard class="text-center">
      <img
        v-if="session.user?.avatar_url"
        :src="session.user.avatar_url"
        alt=""
        class="mx-auto mb-3 h-20 w-20 rounded-full object-cover"
      >
      <div v-else class="mx-auto mb-3 grid h-20 w-20 place-items-center rounded-full bg-primary-50 text-primary-600">
        <UIcon name="i-lucide-user-circle" class="size-10" />
      </div>
      <h2 class="text-lg font-bold">{{ session.user?.display_name || '未命名使用者' }}</h2>
      <p class="text-sm text-gray-500">{{ session.user?.email }}</p>
    </UCard>

    <UCard>
      <h2 class="mb-2 text-lg font-bold">公開清單連結</h2>
      <UAlert v-if="error" color="error" :title="error" class="mb-3" />
      <UAlert v-if="notice && !error" color="success" :title="notice" class="mb-3" />
      <p class="break-all rounded-md bg-gray-50 p-2 font-mono text-xs text-gray-600">{{ shareUrl }}</p>
      <div class="mt-3 flex flex-wrap gap-2">
        <UButton variant="outline" @click="copyShareUrl">複製連結</UButton>
        <UButton variant="ghost" @click="regenerateSlug">重新產生</UButton>
        <UButton variant="ghost" :to="`/public/${session.user?.public_slug}`">預覽公開清單</UButton>
      </div>
    </UCard>
  </div>
</template>
