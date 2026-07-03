import { type Ref, computed, ref, watch, nextTick } from 'vue'

/**
 * Reveals items in a list progressively using a scroll-aware sentinel.
 *
 * A hidden sentinel element (exposed via `sentinelRef`) is placed after the
 * last visible item. When the sentinel enters the extended viewport
 * (`rootMargin: 1500px`), the next batch is revealed via `requestAnimationFrame`.
 * This ensures items appear before the user scrolls to them — even during
 * very fast scrolling — without asking the browser to render/fetch images
 * for the entire list in one frame.
 *
 * The very first render always shows the full `source` length regardless of
 * when `source` resolves. This matters for SSR crawlability and for matching
 * the server-rendered DOM during hydration. Progressive batching only kicks
 * in for `source` changes that happen *after mount* (e.g. switching seasons).
 */
export function useProgressiveReveal<T>(source: Ref<T[]>, batchSize = 10) {
  const batching = ref(false)
  const clientRevealed = ref(0)
  let mounted = false
  let cancelled = false

  const sentinelRef = ref<HTMLElement | null>(null)

  const visibleCount = computed(() => {
    if (!import.meta.client || !batching.value) return source.value.length
    return Math.min(clientRevealed.value, source.value.length)
  })

  if (import.meta.client) {
    let observer: IntersectionObserver | null = null
    let isNearViewport = false

    function revealNextBatch() {
      if (cancelled) return
      if (clientRevealed.value >= source.value.length) {
        batching.value = false
        return
      }
      clientRevealed.value = Math.min(clientRevealed.value + batchSize, source.value.length)
      if (clientRevealed.value >= source.value.length) {
        batching.value = false
      } else if (isNearViewport) {
        // Sentinel is still near the viewport — keep revealing next frame
        requestAnimationFrame(revealNextBatch)
      }
    }

    watch(source, (items) => {
      if (!mounted) return
      batching.value = true
      clientRevealed.value = Math.min(batchSize, items.length)
      // Kick off revealing if sentinel is already near the viewport
      nextTick(() => {
        if (isNearViewport) requestAnimationFrame(revealNextBatch)
      })
    })

    onMounted(() => {
      mounted = true

      observer = new IntersectionObserver(
        (entries) => {
          isNearViewport = entries[0]?.isIntersecting ?? false
          if (isNearViewport && batching.value) {
            revealNextBatch()
          }
        },
        // Reveal cards well before they reach the viewport. This must be at
        // least as large as useLazyLoad's image rootMargin (1500px): a card has
        // to be in the DOM before its image can start loading, so a smaller
        // margin here would cap how early images can begin downloading and
        // leave too little time to fetch+decode during fast scrolling.
        { rootMargin: '1500px' },
      )

      watch(sentinelRef, (el, oldEl) => {
        if (oldEl) observer!.unobserve(oldEl)
        if (el) observer!.observe(el)
      }, { immediate: true })
    })

    onBeforeUnmount(() => {
      cancelled = true
      observer?.disconnect()
    })
  }

  return { visibleCount, sentinelRef }
}
