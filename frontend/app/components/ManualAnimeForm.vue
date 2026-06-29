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
  <UCard>
    <template #header>
      <h2 class="text-lg font-bold">手動建立</h2>
    </template>

    <form class="space-y-3" @submit.prevent="handleSubmit">
      <UFormField label="名稱" required>
        <UInput v-model="form.name" :disabled="disabled || loading" maxlength="160" placeholder="作品名稱" />
      </UFormField>

      <UFormField label="敘述">
        <UTextarea v-model="form.description" :disabled="disabled || loading" :rows="5" placeholder="補上簡短介紹" />
      </UFormField>

      <UFormField label="圖片 URL">
        <UInput v-model="form.imageUrl" :disabled="disabled || loading" type="url" placeholder="https://..." />
      </UFormField>

      <UButton type="submit" block :disabled="disabled || loading" :loading="loading">
        {{ loading ? '建立中' : '建立動漫資料' }}
      </UButton>
      <p v-if="disabled" class="text-xs text-gray-500">需要登入才能建立資料。</p>
    </form>
  </UCard>
</template>
