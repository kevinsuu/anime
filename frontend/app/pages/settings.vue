<script setup lang="ts">
import type { FeedbackSubmission } from '../types/api'
import { apiErrorMessage } from '../utils/apiError'

definePageMeta({ middleware: 'auth' })

useSeoMeta({ robots: 'noindex, nofollow' })

const api = useApi()
const { session, setUser, clearSession } = useSession()
const toast = useToast()

const copied = ref(false)
const regenerating = ref(false)
const feedback = reactive({
  category: 'bug' as FeedbackSubmission['category'],
  subject: '',
  message: ''
})
const sendingFeedback = ref(false)

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
  if (regenerating.value) return
  regenerating.value = true
  try {
    const result = await api.regenerateSlug()
    setUser(result.user)
    toast.add({ title: '分享連結已更新', color: 'success' })
  } catch (err: unknown) {
    toast.add({ title: apiErrorMessage(err, '更新失敗'), color: 'error' })
  } finally {
    regenerating.value = false
  }
}

async function sendFeedback() {
  if (sendingFeedback.value) return
  sendingFeedback.value = true
  try {
    await api.sendFeedback(feedback)
    feedback.category = 'bug'
    feedback.subject = ''
    feedback.message = ''
    toast.add({ title: '問題回報已寄出', color: 'success' })
  } catch (err: unknown) {
    toast.add({ title: apiErrorMessage(err, '寄送失敗'), color: 'error' })
  } finally {
    sendingFeedback.value = false
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
  <div class="space-y-4 sm:space-y-6">
    <header class="space-y-1">
      <p class="text-xs font-extrabold uppercase tracking-widest text-primary-600">帳號</p>
      <h1 class="text-2xl font-extrabold tracking-tight text-gray-950 sm:text-3xl">設定</h1>
    </header>

    <section aria-labelledby="product-philosophy-title" class="grid gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:grid-cols-[auto_minmax(0,1fr)] sm:items-center sm:gap-6 sm:px-6">
      <div class="min-w-0">
        <p class="text-[11px] font-extrabold uppercase tracking-[0.2em] text-primary-700">Anime Library</p>
        <h2 id="product-philosophy-title" class="mt-1 text-xl font-extrabold tracking-tight text-gray-950">動漫庫</h2>
      </div>
      <div class="border-t border-gray-100 pt-3 sm:border-l sm:border-t-0 sm:py-1 sm:pl-6">
        <p class="text-xs font-bold tracking-wide text-gray-400">產品理念</p>
        <p class="mt-1 text-sm font-medium leading-6 text-gray-600">
          查找每季新番播出時間，瀏覽動畫、角色與聲優資料，收藏下一部想追的作品。
        </p>
      </div>
    </section>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)]">
      <!-- Profile card -->
      <div class="flex flex-col items-center gap-4 rounded-xl border border-gray-200 bg-white p-5 text-center shadow-sm sm:p-8">
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
        <div class="min-w-0 max-w-full">
          <h2 class="break-words text-lg font-bold text-gray-950">{{ session.user?.display_name || '未命名使用者' }}</h2>
          <p class="mt-0.5 break-all text-sm text-gray-500">{{ session.user?.email }}</p>
        </div>
        <button
          type="button"
          class="mt-1 min-h-11 w-full rounded-lg border border-red-200 px-5 py-2 text-sm font-semibold text-red-500 transition hover:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-400 sm:w-auto"
          @click="logout"
        >
          登出
        </button>
      </div>

      <!-- Share link card -->
      <div class="min-w-0 space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:p-6">
        <div>
          <h2 class="text-base font-bold text-gray-950">公開清單連結</h2>
          <p class="mt-0.5 text-xs text-gray-500">把你的追番清單分享給朋友</p>
        </div>

        <div class="flex min-w-0 items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5">
          <span class="min-w-0 flex-1 break-all font-mono text-[11px] leading-5 text-gray-700 sm:text-xs">{{ shareUrl }}</span>
          <button
            type="button"
            class="min-h-9 shrink-0 rounded-md px-2.5 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
            :class="copied ? 'bg-green-100 text-green-700' : 'bg-white border border-gray-200 text-gray-700 hover:bg-gray-100'"
            @click="copyShareUrl"
          >
            {{ copied ? '已複製' : '複製' }}
          </button>
        </div>

        <div class="grid gap-2 pt-1 sm:flex sm:flex-wrap">
          <button
            type="button"
            :disabled="regenerating"
            class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
            @click="regenerateSlug"
          >
            <UIcon v-if="regenerating" name="i-lucide-loader-circle" class="size-4 animate-spin" />
            {{ regenerating ? '更新中…' : '重新產生連結' }}
          </button>
          <NuxtLink
            :to="`/public/${session.user?.public_slug}`"
            no-prefetch
            class="inline-flex min-h-11 w-full items-center justify-center gap-1.5 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 sm:w-auto"
          >
            <UIcon name="i-lucide-external-link" class="size-3.5" />
            預覽公開清單
          </NuxtLink>
        </div>
      </div>
    </div>

    <section aria-labelledby="feedback-title" class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
      <div class="flex items-start gap-3 border-b border-gray-100 px-4 py-4 sm:px-6">
        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600">
          <UIcon name="i-lucide-message-square-warning" class="size-5" />
        </div>
        <div class="min-w-0">
          <h2 id="feedback-title" class="text-base font-bold text-gray-950">問題回報</h2>
          <p class="mt-0.5 text-sm leading-5 text-gray-500">告訴我們遇到的問題或你希望新增的功能。</p>
        </div>
      </div>

      <form class="space-y-4 p-4 sm:p-6" @submit.prevent="sendFeedback">
        <label class="block space-y-1.5">
          <span class="text-sm font-semibold text-gray-700">回報類別</span>
          <select
            v-model="feedback.category"
            required
            class="min-h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition focus:border-primary-400 focus:ring-2 focus:ring-primary-100 sm:max-w-xs"
          >
            <option value="bug">問題回報</option>
            <option value="data">資料錯誤</option>
            <option value="suggestion">功能建議</option>
            <option value="other">其他</option>
          </select>
        </label>

        <label class="block space-y-1.5">
          <span class="text-sm font-semibold text-gray-700">主旨</span>
          <input
            v-model="feedback.subject"
            type="text"
            required
            maxlength="120"
            autocomplete="off"
            placeholder="簡短描述你遇到的狀況"
            class="min-h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-primary-400 focus:ring-2 focus:ring-primary-100"
          >
        </label>

        <label class="block space-y-1.5">
          <span class="text-sm font-semibold text-gray-700">詳細內容</span>
          <textarea
            v-model="feedback.message"
            required
            maxlength="5000"
            rows="5"
            placeholder="請描述操作步驟、預期結果，或提供相關作品名稱。"
            class="w-full resize-y rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm leading-6 text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-primary-400 focus:ring-2 focus:ring-primary-100"
          />
          <span class="block text-xs text-gray-500">我們會使用 {{ session.user?.email }} 作為回覆信箱。</span>
        </label>

        <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-xs leading-5 text-gray-500">回報內容會寄送給動漫庫開發者。</p>
          <button
            type="submit"
            :disabled="sendingFeedback"
            class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-lg bg-primary-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
          >
            <UIcon v-if="sendingFeedback" name="i-lucide-loader-circle" class="size-4 animate-spin" />
            <UIcon v-else name="i-lucide-send" class="size-4" />
            {{ sendingFeedback ? '寄送中…' : '送出回報' }}
          </button>
        </div>
      </form>
    </section>
  </div>
</template>
