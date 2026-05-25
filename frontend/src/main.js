import { createApp, computed, nextTick, reactive } from 'vue'
import './styles.css'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8080'
const GOOGLE_CLIENT_ID = import.meta.env.VITE_GOOGLE_CLIENT_ID || ''
const ENABLE_DEV_LOGIN = import.meta.env.VITE_ENABLE_DEV_LOGIN === 'true'

function normalizeAnime(item) {
  return {
    id: item.id,
    name: item.name,
    description: item.description || '',
    imageUrl: item.imageUrl || item.image_url || ''
  }
}

function createApi(state) {
  async function request(path, options = {}) {
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) }
    if (state.token) headers.Authorization = `Bearer ${state.token}`
    const response = await fetch(`${API_BASE_URL}${path}`, { ...options, headers })
    const body = await response.json().catch(() => ({}))
    if (!response.ok) {
      const error = new Error(body.message || '請求失敗')
      error.body = body
      error.status = response.status
      throw error
    }
    return body
  }

  return {
    login: idToken => request('/auth/google', { method: 'POST', body: JSON.stringify({ idToken }) }),
    me: () => request('/me'),
    searchAnime: q => request(`/anime?q=${encodeURIComponent(q)}`),
    createAnime: payload => request('/anime', { method: 'POST', body: JSON.stringify(payload) }),
    myList: () => request('/my/anime-list'),
    addToList: animeId => request('/my/anime-list', { method: 'POST', body: JSON.stringify({ animeId }) }),
    updateListItem: (id, payload) => request(`/my/anime-list/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),
    deleteListItem: id => request(`/my/anime-list/${id}`, { method: 'DELETE' }),
    publicList: slug => request(`/public/lists/${encodeURIComponent(slug)}`),
    regenerateSlug: () => request('/me/share-slug/regenerate', { method: 'POST' })
  }
}

const app = {
  setup() {
    const stored = JSON.parse(localStorage.getItem('animeTrackerSession') || '{}')
    const state = reactive({
      token: stored.token || '',
      user: stored.user || null,
      route: location.hash.replace('#', '') || '/',
      loading: false,
      error: '',
      query: '',
      catalog: [],
      list: [],
      publicUser: null,
      publicItems: [],
      form: { name: '', description: '', imageUrl: '' }
    })
    const api = createApi(state)

    const isAuthed = computed(() => Boolean(state.token && state.user))
    const shareUrl = computed(() => state.user ? `${location.origin}${location.pathname}#/public/${state.user.public_slug}` : '')
    const filteredList = computed(() => {
      if (state.route === '/watched') return state.list.filter(item => item.watched)
      if (state.route === '/unwatched') return state.list.filter(item => !item.watched)
      return state.list
    })

    function persist(session = { token: state.token, user: state.user }) {
      localStorage.setItem('animeTrackerSession', JSON.stringify(session))
    }

    function navigate(route) {
      location.hash = route
      nextTick(renderGoogleButton)
    }

    async function run(task) {
      state.loading = true
      state.error = ''
      try {
        await task()
      } catch (error) {
        state.error = error.message || '操作失敗'
      } finally {
        state.loading = false
      }
    }

    async function afterLogin(result) {
      state.token = result.token
      state.user = result.user
      persist()
      await loadMyList()
      navigate('/list')
    }

    async function loginWithGoogleResponse(response) {
      await run(async () => afterLogin(await api.login(response.credential)))
    }

    async function devLogin() {
      await run(async () => afterLogin(await api.login('dev:dev@example.com')))
    }

    async function loadMe() {
      if (!state.token) return
      await run(async () => {
        const result = await api.me()
        state.user = result.user
        persist()
      })
    }

    async function search() {
      await run(async () => {
        const result = await api.searchAnime(state.query)
        state.catalog = result.items.map(normalizeAnime)
      })
    }

    async function createAnime() {
      await run(async () => {
        const result = await api.createAnime(state.form)
        state.catalog = [normalizeAnime(result.item), ...state.catalog]
        state.form = { name: '', description: '', imageUrl: '' }
      })
    }

    async function loadMyList() {
      if (!state.token) return
      await run(async () => {
        const result = await api.myList()
        state.list = result.items
      })
    }

    async function addAnime(animeId) {
      await run(async () => {
        await api.addToList(animeId)
        await loadMyList()
      })
    }

    async function updateItem(item, patch) {
      await run(async () => {
        const result = await api.updateListItem(item.id, patch)
        const index = state.list.findIndex(existing => existing.id === item.id)
        if (index >= 0) state.list[index] = result.item
      })
    }

    async function removeItem(item) {
      await run(async () => {
        await api.deleteListItem(item.id)
        state.list = state.list.filter(existing => existing.id !== item.id)
      })
    }

    async function loadPublic(slug) {
      await run(async () => {
        const result = await api.publicList(slug)
        state.publicUser = result.user
        state.publicItems = result.items
      })
    }

    async function regenerateSlug() {
      await run(async () => {
        const result = await api.regenerateSlug()
        state.user = result.user
        persist()
      })
    }

    function logout() {
      state.token = ''
      state.user = null
      state.list = []
      localStorage.removeItem('animeTrackerSession')
      navigate('/')
    }

    function renderGoogleButton() {
      if (window.google && GOOGLE_CLIENT_ID) {
        window.google.accounts.id.initialize({ client_id: GOOGLE_CLIENT_ID, callback: loginWithGoogleResponse })
        const target = document.getElementById('google-signin')
        if (target && target.childElementCount === 0) {
          window.google.accounts.id.renderButton(target, { theme: 'outline', size: 'large', width: 260 })
        }
      }
    }

    window.addEventListener('hashchange', async () => {
      state.route = location.hash.replace('#', '') || '/'
      if (state.route === '/catalog') await search()
      if (state.route === '/list' || state.route === '/watched' || state.route === '/unwatched') await loadMyList()
      if (state.route.startsWith('/public/')) await loadPublic(state.route.replace('/public/', ''))
      nextTick(renderGoogleButton)
    })

    setTimeout(renderGoogleButton, 600)

    if (state.token) {
      loadMe()
      loadMyList()
    } else {
      search()
    }

    return {
      API_BASE_URL,
      ENABLE_DEV_LOGIN,
      state,
      isAuthed,
      shareUrl,
      filteredList,
      navigate,
      devLogin,
      search,
      createAnime,
      addAnime,
      updateItem,
      removeItem,
      regenerateSlug,
      logout
    }
  },
  template: `
    <main class="shell">
      <aside class="rail">
        <button class="brand" @click="navigate('/')">
          <span class="brand-mark">追</span>
          <span>追番格納庫</span>
        </button>
        <nav>
          <button :class="{active: state.route === '/'}" @click="navigate('/')">首頁</button>
          <button :class="{active: state.route === '/catalog'}" @click="navigate('/catalog')">搜尋</button>
          <button :class="{active: state.route === '/list'}" @click="navigate('/list')" :disabled="!isAuthed">清單</button>
          <button :class="{active: state.route === '/settings'}" @click="navigate('/settings')" :disabled="!isAuthed">設定</button>
        </nav>
      </aside>

      <section class="workspace">
        <header class="topbar">
          <div>
            <p class="eyebrow">Anime Tracker MVP</p>
            <h1>{{ state.route.startsWith('/public/') ? '公開追番清單' : '你的追番控制台' }}</h1>
          </div>
          <button v-if="isAuthed" class="ghost" @click="logout">登出</button>
        </header>

        <p v-if="state.error" class="alert">{{ state.error }}</p>
        <p v-if="state.loading" class="loading">載入中...</p>

        <section v-if="state.route === '/'" class="hero">
          <div>
            <p class="eyebrow">清單、評價、分享</p>
            <h2>把看過、想看、值得推薦的作品收進同一個地方。</h2>
            <p>第一版專注在個人追番清單與公開分享。AI 匯入與排行榜會在後續階段接上。</p>
            <div class="actions">
              <button class="primary" @click="navigate(isAuthed ? '/list' : '/login')">{{ isAuthed ? '查看我的清單' : '使用 Google 登入' }}</button>
              <button class="secondary" @click="navigate('/catalog')">瀏覽動漫資料</button>
            </div>
          </div>
          <div class="poster-stack">
            <article v-for="item in state.catalog.slice(0, 3)" :key="item.id" class="poster">
              <img :src="item.imageUrl" :alt="item.name" />
              <strong>{{ item.name }}</strong>
            </article>
          </div>
        </section>

        <section v-if="state.route === '/login'" class="panel narrow">
          <h2>登入</h2>
          <p>使用 Google OAuth 登入。前端只保存後端簽發的短效 JWT。</p>
          <div id="google-signin" class="google-slot"></div>
          <button v-if="ENABLE_DEV_LOGIN" class="secondary" @click="devLogin">開發模式登入</button>
        </section>

        <section v-if="state.route === '/catalog'" class="grid-two">
          <div class="panel">
            <h2>搜尋動漫</h2>
            <div class="searchbar">
              <input v-model="state.query" placeholder="輸入動漫名稱或別名" @keyup.enter="search" />
              <button class="primary" @click="search">搜尋</button>
            </div>
            <div class="cards">
              <article v-for="anime in state.catalog" :key="anime.id" class="anime-card">
                <img :src="anime.imageUrl || 'https://images.unsplash.com/photo-1519681393784-d120267933ba?q=80&w=800&auto=format&fit=crop'" :alt="anime.name" />
                <div>
                  <h3>{{ anime.name }}</h3>
                  <p>{{ anime.description }}</p>
                  <button class="secondary" :disabled="!isAuthed" @click="addAnime(anime.id)">加入清單</button>
                </div>
              </article>
            </div>
          </div>
          <form class="panel" @submit.prevent="createAnime">
            <h2>手動建立</h2>
            <label>名稱<input v-model="state.form.name" required maxlength="160" /></label>
            <label>敘述<textarea v-model="state.form.description" rows="5"></textarea></label>
            <label>圖片 URL<input v-model="state.form.imageUrl" type="url" /></label>
            <button class="primary" :disabled="!isAuthed">建立動漫資料</button>
          </form>
        </section>

        <section v-if="['/list','/watched','/unwatched'].includes(state.route)" class="panel">
          <div class="section-head">
            <div>
              <h2>我的清單</h2>
              <p v-if="shareUrl">分享連結：<code>{{ shareUrl }}</code></p>
            </div>
            <div class="tabs">
              <button :class="{active: state.route === '/list'}" @click="navigate('/list')">全部</button>
              <button :class="{active: state.route === '/watched'}" @click="navigate('/watched')">已看</button>
              <button :class="{active: state.route === '/unwatched'}" @click="navigate('/unwatched')">未看</button>
            </div>
          </div>
          <div class="list">
            <article v-for="item in filteredList" :key="item.id" class="list-item">
              <img :src="item.anime.imageUrl" :alt="item.anime.name" />
              <div>
                <h3>{{ item.anime.name }}</h3>
                <p>{{ item.anime.description }}</p>
                <textarea :value="item.note" placeholder="私人備註" @change="updateItem(item, { note: $event.target.value })"></textarea>
              </div>
              <div class="controls">
                <label class="check"><input type="checkbox" :checked="item.watched" @change="updateItem(item, { watched: $event.target.checked })" /> 已看</label>
                <select :value="item.rating || ''" @change="updateItem(item, { rating: $event.target.value ? Number($event.target.value) : null })">
                  <option value="">未評分</option>
                  <option v-for="score in 10" :key="score" :value="score">{{ score }} 分</option>
                </select>
                <button class="danger" @click="removeItem(item)">移除</button>
              </div>
            </article>
          </div>
        </section>

        <section v-if="state.route.startsWith('/public/')" class="panel">
          <h2>{{ state.publicUser?.display_name || '使用者' }} 的公開清單</h2>
          <div class="cards">
            <article v-for="item in state.publicItems" :key="item.id" class="anime-card">
              <img :src="item.anime.imageUrl" :alt="item.anime.name" />
              <div>
                <h3>{{ item.anime.name }}</h3>
                <p>{{ item.anime.description }}</p>
                <span class="pill">{{ item.watched ? '已看' : '未看' }} · {{ item.rating ? item.rating + ' 分' : '未評分' }}</span>
              </div>
            </article>
          </div>
        </section>

        <section v-if="state.route === '/settings'" class="panel narrow">
          <h2>設定</h2>
          <img v-if="state.user?.avatar_url" class="avatar" :src="state.user.avatar_url" alt="" />
          <p>{{ state.user?.display_name }}</p>
          <p>{{ state.user?.email }}</p>
          <code>{{ shareUrl }}</code>
          <button class="secondary" @click="regenerateSlug">重新產生分享連結</button>
        </section>
      </section>

      <nav class="mobile-nav">
        <button @click="navigate('/')">首頁</button>
        <button @click="navigate('/catalog')">搜尋</button>
        <button @click="navigate('/list')" :disabled="!isAuthed">清單</button>
        <button @click="navigate('/settings')" :disabled="!isAuthed">設定</button>
      </nav>
    </main>
  `
}

createApp(app).mount('#app')
