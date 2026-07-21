import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const listPageSource = readFileSync(
  resolve(process.cwd(), 'app/pages/list/index.vue'),
  'utf8'
)

describe('list page data-flow contract', () => {
  it('loads the complete list once and filters selected tags locally', () => {
    expect(listPageSource.match(/api\.myList\(/g)).toHaveLength(1)
    expect(listPageSource).toContain('applyTagFilters(list.value, selectedTags.value)')
    expect(listPageSource).not.toContain('api.myList({ tags })')
    expect(listPageSource).not.toContain('watch(selectedTags')
  })

  it('keeps a single canonical list collection', () => {
    expect(listPageSource).toContain('const list = ref<ListItem[]>([])')
    expect(listPageSource).not.toContain('fullList')
  })
})
