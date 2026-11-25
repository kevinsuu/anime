import { describe, expect, it } from 'vitest'
import { isIndexableAnime } from '../app/utils/indexability'

const synopsis = (length: number) => '介'.repeat(length)
const noFacts = { episodeCount: null, streamCount: 0, castCount: 0 }

describe('isIndexableAnime', () => {
  it('indexes a page whose synopsis stands alone, even without other facts', () => {
    expect(isIndexableAnime({ description: synopsis(160), ...noFacts })).toBe(true)
  })

  it('ignores whitespace when measuring the synopsis', () => {
    expect(isIndexableAnime({ description: `${synopsis(159)}   \n `, ...noFacts })).toBe(false)
  })

  it('indexes a shorter synopsis backed by at least two first-party facts', () => {
    expect(isIndexableAnime({
      description: synopsis(60), episodeCount: 12, streamCount: 1, castCount: 0
    })).toBe(true)
    expect(isIndexableAnime({
      description: synopsis(60), episodeCount: null, streamCount: 2, castCount: 3
    })).toBe(true)
  })

  it('keeps a shorter synopsis noindex with only one supporting fact', () => {
    expect(isIndexableAnime({
      description: synopsis(100), episodeCount: 12, streamCount: 0, castCount: 2
    })).toBe(false)
  })

  it('keeps a page noindex when the synopsis is too short, whatever the facts', () => {
    expect(isIndexableAnime({
      description: synopsis(59), episodeCount: 12, streamCount: 3, castCount: 10
    })).toBe(false)
    expect(isIndexableAnime({
      description: null, episodeCount: 12, streamCount: 3, castCount: 10
    })).toBe(false)
  })
})
