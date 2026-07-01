<template>
  <UApp>
    <NuxtRouteAnnouncer />
    <a
      href="#main-content"
      class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-primary-600 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-lg"
    >
      跳到主要內容
    </a>
    <div class="min-h-dvh bg-gray-50">
      <AppHeader />
      <main id="main-content" class="mx-auto w-full max-w-6xl px-4 pb-24 pt-6 md:pb-12">
        <NuxtPage />
      </main>
      <AppMobileNav />
    </div>
    <UToaster />
  </UApp>
</template>
