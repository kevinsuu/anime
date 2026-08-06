import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const gridCardSource = readFileSync(
  resolve(process.cwd(), 'app/components/AnimeGridCard.vue'),
  'utf8'
)
const seasonalSource = readFileSync(resolve(process.cwd(), 'app/pages/seasonal.vue'), 'utf8')
const catalogSource = readFileSync(resolve(process.cwd(), 'app/pages/catalog.vue'), 'utf8')

describe('anime grid card status border', () => {
  it('uses watched before favorite so a watched favorite card stays green', () => {
    expect(gridCardSource).toContain("props.watched ? 'watched' : props.inList ? 'favorite' : null")
    expect(gridCardSource).toContain(':data-card-status="cardStatus"')
  })

  it('hides intermediate borders until the status mutation finishes', () => {
    expect(gridCardSource).toContain('if (props.statusPending) return null')
    expect(gridCardSource).toContain(':data-card-status-pending="statusPending || undefined"')
    expect(seasonalSource).toContain(':status-pending="isStatusPending(anime.id)"')
    expect(catalogSource).toContain(':status-pending="isStatusPending(anime.id)"')
  })

  it('animates distinct watched and favorite-only borders', () => {
    expect(gridCardSource).toContain('.anime-card-shell--watched::before')
    expect(gridCardSource).toContain('--status-border-a: #16a34a')
    expect(gridCardSource).toContain('.anime-card-shell--favorite::before')
    expect(gridCardSource).toContain('--status-border-a: #db2777')
    expect(gridCardSource).toContain('background: repeating-conic-gradient(')
    expect(gridCardSource).toContain('var(--status-border-a) 0.5turn')
    expect(gridCardSource).toContain('z-index: 15')
    expect(gridCardSource).toContain('padding: 3px')
    expect(gridCardSource).toContain('--status-border-angle: 360deg')
    expect(gridCardSource).toContain('animation: status-border-flow 4s linear infinite')
    expect(gridCardSource).toContain('@media (prefers-reduced-motion: reduce)')
  })
})
