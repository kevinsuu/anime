import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { HIGH_PRIORITY_IMAGE_COUNT, IMAGE_PRELOAD_DISTANCE_PX, VIRTUAL_RENDER_BUFFER_PX } from '../app/composables/useLazyLoad'

const virtualGridSource = readFileSync(
  resolve(process.cwd(), 'app/components/AnimeVirtualGrid.vue'),
  'utf8'
)
const gridCardSource = readFileSync(
  resolve(process.cwd(), 'app/components/AnimeGridCard.vue'),
  'utf8'
)

describe('anime image loading contract', () => {
  it('keeps the virtual render buffer separate from image preload distance', () => {
    expect(IMAGE_PRELOAD_DISTANCE_PX).toBe(300)
    expect(VIRTUAL_RENDER_BUFFER_PX).toBe(700)
    expect(virtualGridSource).toContain(':buffer="VIRTUAL_RENDER_BUFFER_PX"')
  })

  it('prioritizes only the LCP candidate and decodes the rest asynchronously', () => {
    expect(HIGH_PRIORITY_IMAGE_COUNT).toBe(1)
    expect(gridCardSource).toContain(":loading=\"eagerLoad ? 'eager' : 'lazy'\"")
    expect(gridCardSource).toContain(":fetchpriority=\"eagerLoad ? 'high' : 'low'\"")
    expect(gridCardSource).toContain('decoding="async"')
  })

  it('limits the SSR fallback to the first twelve cards', () => {
    expect(virtualGridSource).toContain('SSR_FALLBACK_CARD_COUNT = 12')
    expect(virtualGridSource).toContain('props.items.slice(0, SSR_FALLBACK_CARD_COUNT)')
  })

  it('uses a quiet neutral fallback when a cover is unavailable', () => {
    expect(gridCardSource).toContain('data-image-fallback')
    expect(gridCardSource).toContain('v-if="hasUsableImage"')
    expect(gridCardSource).toContain('@error="imageError = true"')
    expect(gridCardSource).toContain('bg-gray-100 text-3xl font-bold text-gray-400 ring-1 ring-inset ring-gray-200')
    expect(gridCardSource).toContain("hasUsableImage ? 'text-white drop-shadow' : 'text-gray-800'")
    expect(gridCardSource).not.toContain('bg-primary-700 text-3xl font-bold text-white')
  })

  it('reveals desktop card actions for keyboard focus as well as pointer hover', () => {
    expect(gridCardSource).toContain('group-focus-within/card:pointer-events-auto')
    expect(gridCardSource).toContain('group-focus-within/card:translate-y-0')
    expect(gridCardSource).toContain('group-focus-within/card:opacity-100')
  })
})
