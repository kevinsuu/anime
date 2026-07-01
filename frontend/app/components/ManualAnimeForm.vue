<script setup lang="ts">
const props = defineProps<{
  disabled: boolean
  loading: boolean
}>()

const emit = defineEmits<{ submit: [payload: { name: string; description: string; imageUrl: string }] }>()

const form = reactive({ name: '', description: '', imageUrl: '' })

function handleSubmit() {
  emit('submit', { ...form })
  form.name = ''
  form.description = ''
  form.imageUrl = ''
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="handleSubmit">
    <div class="space-y-1">
      <label for="manual-anime-name" class="block text-xs font-semibold text-gray-700">
        名稱 <span class="text-red-500">*</span>
      </label>
      <input
        id="manual-anime-name"
        v-model="form.name"
        :disabled="disabled || loading"
        maxlength="160"
        placeholder="作品名稱"
        required
        class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 outline-none transition focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-100 disabled:opacity-50"
      />
    </div>

    <div class="space-y-1">
      <label for="manual-anime-description" class="block text-xs font-semibold text-gray-700">敘述</label>
      <textarea
        id="manual-anime-description"
        v-model="form.description"
        :disabled="disabled || loading"
        rows="4"
        placeholder="補上簡短介紹"
        class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 outline-none transition focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-100 disabled:opacity-50 resize-none"
      />
    </div>

    <div class="space-y-1">
      <label for="manual-anime-image-url" class="block text-xs font-semibold text-gray-700">圖片 URL</label>
      <input
        id="manual-anime-image-url"
        v-model="form.imageUrl"
        :disabled="disabled || loading"
        type="url"
        placeholder="https://..."
        class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 outline-none transition focus:border-primary-400 focus:bg-white focus:ring-2 focus:ring-primary-100 disabled:opacity-50"
      />
    </div>

    <button
      type="submit"
      :disabled="disabled || loading || !form.name.trim()"
      class="w-full rounded-lg bg-primary-600 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
    >
      {{ loading ? '建立中…' : '建立動漫資料' }}
    </button>

    <p v-if="disabled" class="text-center text-xs text-gray-400">需要登入才能建立資料</p>
  </form>
</template>
