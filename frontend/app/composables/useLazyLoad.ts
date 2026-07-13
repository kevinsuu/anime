import { type Ref, ref } from 'vue'

/**
 * Shared IntersectionObserver for all lazy-loaded images.
 * Uses a generous rootMargin (1500px) so images start loading well before
 * the user scrolls to them — much more aggressive than the browser's native
 * `loading="lazy"` (~1250px in Chrome), which can miss images during fast
 * scrolling.
 */
const callbacks = new WeakMap<Element, () => void>()
let sharedObserver: IntersectionObserver | null = null

// Must match the observer's rootMargin below. IntersectionObserver only reports
// asynchronously (next frame at the earliest), so a card mounted *already* near
// the viewport during a fast scroll wouldn't get its src set until the observer
// gets around to firing — leaving a blank card. We use this margin to
// synchronously check on mount whether the card is already in range.
export const IMAGE_PRELOAD_DISTANCE_PX = 1500

function getObserver(): IntersectionObserver {
  if (!sharedObserver) {
    sharedObserver = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            callbacks.get(entry.target)?.()
            callbacks.delete(entry.target)
            sharedObserver!.unobserve(entry.target)
          }
        }
      },
      { rootMargin: `${IMAGE_PRELOAD_DISTANCE_PX}px 0px` },
    )
  }
  return sharedObserver
}

/**
 * Synchronously test whether an element is already within the observer's
 * vertical margin of the viewport. Lets us set `shouldLoad` on mount without
 * waiting for the observer's first (async) callback.
 */
function isNearViewport(el: HTMLElement): boolean {
  const rect = el.getBoundingClientRect()
  const viewportH = window.innerHeight || document.documentElement.clientHeight
  return rect.bottom >= -IMAGE_PRELOAD_DISTANCE_PX && rect.top <= viewportH + IMAGE_PRELOAD_DISTANCE_PX
}

/**
 * Returns a reactive boolean that flips to `true` once the element is within
 * 1500px of the viewport. Use this to defer setting image `src` until the
 * card is near-visible, replacing native `loading="lazy"`.
 */
export function useLazyLoad(el: Ref<HTMLElement | null>, eager = false): Ref<boolean> {
  const shouldLoad = ref(eager)

  if (!import.meta.client || eager) return shouldLoad

  onMounted(() => {
    const target = el.value
    if (!target) {
      shouldLoad.value = true
      return
    }
    // If the card is already near the viewport at mount time (common when a new
    // batch is revealed during fast scrolling), load immediately rather than
    // waiting for the observer's first async callback.
    if (isNearViewport(target)) {
      shouldLoad.value = true
      return
    }
    const obs = getObserver()
    callbacks.set(target, () => { shouldLoad.value = true })
    obs.observe(target)
  })

  onBeforeUnmount(() => {
    const target = el.value
    if (target) {
      callbacks.delete(target)
      sharedObserver?.unobserve(target)
    }
  })

  return shouldLoad
}
