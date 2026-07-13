import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { IMAGE_PRELOAD_DISTANCE_PX } from '../app/composables/useLazyLoad'

const virtualGridSource = readFileSync(
  resolve(process.cwd(), 'app/components/AnimeVirtualGrid.vue'),
  'utf8'
)

describe('anime image loading contract', () => {
  it('mounts virtualized cards as far ahead as images are preloaded', () => {
    expect(IMAGE_PRELOAD_DISTANCE_PX).toBe(1500)
    expect(virtualGridSource).toContain(':buffer="IMAGE_PRELOAD_DISTANCE_PX"')
  })
})
