<template>
  <UApp>
    <NuxtRouteAnnouncer />
    <div class="min-h-dvh bg-gray-50">
      <AppHeader />
      <main class="mx-auto w-full max-w-6xl px-4 pb-24 pt-6 md:pb-12">
        <NuxtPage />
      </main>
      <AppMobileNav />
    </div>
  </UApp>
</template>
