<template>
  <UApp :toaster="toasterOptions">
    <NuxtRouteAnnouncer />
    <a
      href="#main-content"
      class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-primary-600 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-lg"
    >
      跳到主要內容
    </a>
    <div class="min-h-dvh bg-gray-50">
      <AppHeader />
      <main id="main-content" class="mx-auto w-full max-w-6xl px-4 pb-28 pt-4 md:pb-12 md:pt-6">
        <NuxtPage />
      </main>
      <AppMobileNav />
    </div>
  </UApp>
</template>

<script setup lang="ts">
const isMobileViewport = ref(false)
const toasterOptions = computed(() => ({
  position: isMobileViewport.value ? 'top-right' as const : 'bottom-right' as const,
  expand: !isMobileViewport.value
}))

let mobileViewport: MediaQueryList | undefined

function updateToasterLayout() {
  isMobileViewport.value = mobileViewport?.matches ?? false
}

onMounted(() => {
  mobileViewport = window.matchMedia('(max-width: 639px)')
  updateToasterLayout()
  mobileViewport.addEventListener('change', updateToasterLayout)
})

onBeforeUnmount(() => {
  mobileViewport?.removeEventListener('change', updateToasterLayout)
})
</script>
