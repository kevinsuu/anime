import { createServer } from 'node:http'

const host = '127.0.0.1'
const port = Number(process.env.MOCK_API_PORT || 41731)

const longThemeArtist = '測試歌手聯盟（聲優甲角色甲、聲優乙角色乙、聲優丙角色丙、聲優丁角色丁、聲優戊角色戊、聲優己角色己、聲優庚角色庚、聲優辛角色辛、聲優壬角色壬、聲優癸角色癸）'.repeat(4)

const summaries = Array.from({ length: 40 }, (_, index) => ({
  id: 2320 + index,
  name: `測試動畫 ${String(index + 1).padStart(2, '0')}`,
  image_url: '',
  source: 'e2e',
  season_year: 2026,
  season_code: 'summer',
  air_date: `2026-07-${String((index % 28) + 1).padStart(2, '0')}`,
  air_date_text: `7月${(index % 28) + 1}日起／每週${['一', '二', '三', '四', '五', '六', '日'][index % 7]}／20時30分`,
  episode_count: 12,
  status: 'airing',
  tags: index % 2 === 0 ? ['新作', '奇幻', '冒險'] : ['漫畫改編', '喜劇', '浪漫'],
  stream_count: 2,
  actors: ['測試聲優甲', '測試聲優乙']
}))

const detail = {
  ...summaries[0],
  name: '無自覺測試聖女今天也無意識地釋放力量',
  description: '這是 Playwright 本機 mock API 提供的穩定作品介紹，用來驗證手機詳情頁與桌面版面。',
  aliases: ['Deterministic Responsive Anime Test', '自動化測試用別名'],
  titles: [{ locale: 'ja', title: 'レスポンシブテスト作品' }],
  streams: [
    { region: '台灣', platform: 'Crunchyroll', url: null },
    { region: '香港', platform: 'Crunchyroll', url: null }
  ],
  themes: [
    { type: 'OP', title: 'Responsive Promise', artist: longThemeArtist },
    { type: 'ED', title: 'No Horizontal Overflow', artist: '測試歌手乙' }
  ],
  trailers: [
    { url: 'https://www.youtube.com/watch?v=e2eTrailer01', thumbnail: null },
    { url: 'https://www.youtube.com/watch?v=e2eTrailer02', thumbnail: null }
  ],
  cast: [
    { character: '測試角色甲', actor: '測試聲優甲' },
    { character: '測試角色乙', actor: '測試聲優乙' }
  ],
  staff: [
    { role: '導演', name: '測試導演' },
    { role: '動畫製作', name: 'Deterministic Studio' }
  ],
  links: [
    { category: '一般', label: '官方網站', url: 'https://example.test/anime/2320' },
    { category: '一般', label: '維基百科(中文)', url: 'https://example.test/wiki/2320' }
  ]
}

const catalogTags = [
  { tag: '奇幻', count: 20 },
  { tag: '冒險', count: 20 },
  { tag: '喜劇', count: 20 },
  { tag: '浪漫', count: 20 }
]

function sendJson(response, status, body) {
  response.writeHead(status, {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Headers': 'Authorization, Content-Type',
    'Access-Control-Allow-Methods': 'GET, POST, PATCH, DELETE, OPTIONS',
    'Cache-Control': 'no-store',
    'Content-Type': 'application/json; charset=utf-8'
  })
  response.end(JSON.stringify(body))
}

function apiPath(pathname) {
  for (const prefix of ['/internal', '/public']) {
    if (pathname === prefix) return '/'
    if (pathname.startsWith(`${prefix}/`)) return pathname.slice(prefix.length)
  }
  return null
}

const server = createServer((request, response) => {
  const url = new URL(request.url || '/', `http://${request.headers.host || `${host}:${port}`}`)

  if (request.method === 'OPTIONS') {
    sendJson(response, 204, {})
    return
  }

  if (url.pathname === '/health') {
    sendJson(response, 200, { ok: true })
    return
  }

  const pathname = apiPath(url.pathname)
  if (!pathname) {
    sendJson(response, 404, { message: 'API prefix must be /internal or /public' })
    return
  }

  if (request.method === 'GET' && pathname === '/anime/summaries') {
    const page = Math.max(1, Number(url.searchParams.get('page') || 1))
    const perPage = Math.max(1, Number(url.searchParams.get('per_page') || 40))
    sendJson(response, 200, {
      items: summaries.slice((page - 1) * perPage, page * perPage),
      meta: {
        page,
        per_page: perPage,
        total: summaries.length,
        last_page: Math.max(1, Math.ceil(summaries.length / perPage)),
        has_more: page * perPage < summaries.length
      }
    })
    return
  }

  if (request.method === 'GET' && pathname === '/anime/tags') {
    sendJson(response, 200, { tags: catalogTags })
    return
  }

  if (request.method === 'GET' && pathname === '/anime/2320') {
    sendJson(response, 200, { item: detail })
    return
  }

  sendJson(response, 404, { message: `No deterministic mock for ${request.method} ${pathname}` })
})

server.listen(port, host, () => {
  process.stdout.write(`Mock API listening on http://${host}:${port}\n`)
})

function closeServer() {
  server.close(() => process.exit(0))
}

process.on('SIGINT', closeServer)
process.on('SIGTERM', closeServer)
