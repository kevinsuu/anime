import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { IMAGE_PRELOAD_DISTANCE_PX } from '../app/composables/useLazyLoad'

const virtualGridSource = readFileSync(
  resolve(process.cwd(), 'app/components/AnimeVirtualGrid.vue'),
  'utf8'
)
const gridCardSource = readFileSync(
  resolve(process.cwd(), 'app/components/AnimeGridCard.vue'),
  'utf8'
)

describe('anime image loading contract', () => {
  it('mounts virtualized cards as far ahead as images are preloaded', () => {
    expect(IMAGE_PRELOAD_DISTANCE_PX).toBe(1500)
    expect(virtualGridSource).toContain(':buffer="IMAGE_PRELOAD_DISTANCE_PX"')
  })

  it('uses a quiet neutral fallback when a cover is unavailable', () => {
    expect(gridCardSource).toContain('data-image-fallback')
    expect(gridCardSource).toContain('v-if="hasUsableImage"')
    expect(gridCardSource).toContain('@error="imageError = true"')
    expect(gridCardSource).toContain('bg-gray-100 text-3xl font-bold text-gray-400 ring-1 ring-inset ring-gray-200')
    expect(gridCardSource).toContain("hasUsableImage ? 'text-white drop-shadow' : 'text-gray-800'")
    expect(gridCardSource).not.toContain('bg-primary-700 text-3xl font-bold text-white')
  })
})
