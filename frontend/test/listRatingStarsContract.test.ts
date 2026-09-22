import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const readSource = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')
const ratingStarsSource = readSource('app/components/ListRatingStars.vue')
const listItemRowSource = readSource('app/components/ListItemRow.vue')

describe('list item rating stars', () => {
  it('renders a directly clickable 1–10 star scale', () => {
    expect(ratingStarsSource).toContain('Array.from({ length: 10 }')
    expect(ratingStarsSource).toContain('name="i-lucide-star"')
    expect(ratingStarsSource).toContain('@click="selectRating(score)"')
    expect(ratingStarsSource).toContain(':aria-pressed="rating === score"')
  })

  it('highlights every star through the selected or previewed score', () => {
    expect(ratingStarsSource).toContain('previewRating.value ?? props.rating ?? 0')
    expect(ratingStarsSource).toContain(':data-rating-highlighted="score <= highlightedRating"')
    expect(ratingStarsSource).toContain('score <= highlightedRating')
    expect(ratingStarsSource).toContain('@pointerenter="previewScore($event, score)"')
  })

  it('clears the rating when the selected star is clicked again', () => {
    expect(ratingStarsSource).toContain("props.rating === score ? null : score")
  })

  it('uses the shared star control in both mobile and desktop layouts', () => {
    expect(listItemRowSource.match(/<ListRatingStars/g)).toHaveLength(2)
    expect(listItemRowSource).not.toContain('<select')
    expect(listItemRowSource).not.toContain('ratingOptions')
  })

  it('does not prefetch every detail page or cover in a list page', () => {
    expect(listItemRowSource.match(/no-prefetch/g)).toHaveLength(2)
    expect(listItemRowSource).toContain('useLazyLoad(rowRef)')
    expect(listItemRowSource).toContain(":src=\"shouldLoadCover ? item.anime.imageUrl : undefined\"")
  })
})
