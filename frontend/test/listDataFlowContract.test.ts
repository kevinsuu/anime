import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const listPageSource = readFileSync(
  resolve(process.cwd(), 'app/pages/list/index.vue'),
  'utf8'
)

describe('list page data-flow contract', () => {
  it('loads only the requested page and delegates filters to the list API', () => {
    expect(listPageSource).toContain('page: page.value')
    expect(listPageSource).toContain('tags: selectedTags.value')
    expect(listPageSource).toContain('filters.status = activeFilter.value')
    expect(listPageSource).not.toContain('applyTagFilters(list.value')
    expect(listPageSource).not.toContain('applyListFilters(')
  })

  it('loads global collection status counts from the dedicated endpoint', () => {
    expect(listPageSource).toContain('api.myListCounts()')
    expect(listPageSource).toContain("{ value: 'all', label: '全部收藏' }")
    expect(listPageSource).toContain("{ value: 'unwatched', label: '收藏未看' }")
  })

  it('keeps a single canonical list collection', () => {
    expect(listPageSource).toContain('const list = ref<ListItem[]>([])')
    expect(listPageSource).not.toContain('fullList')
  })
})
