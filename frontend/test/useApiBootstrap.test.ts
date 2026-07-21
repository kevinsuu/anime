import { afterEach, describe, expect, it, vi } from 'vitest'
import { useApi } from '../app/composables/useApi'
import { ApiError } from '../app/utils/apiError'

describe('useApi meBootstrap', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('requests the lightweight card bootstrap contract with comma-separated anime ids', async () => {
    const payload = {
      user: { id: 1 },
      statuses: [],
      collections: []
    }
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => payload
    })

    vi.stubGlobal('fetch', fetchMock)
    vi.stubGlobal('useRuntimeConfig', () => ({
      apiBaseUrlInternal: 'http://backend:8080',
      public: { apiBaseUrl: 'https://api.example.test' }
    }))
    vi.stubGlobal('useSession', () => ({
      session: { token: 'token', refreshToken: '' },
      updateTokens: vi.fn(),
      clearSession: vi.fn()
    }))

    const result = await useApi().meBootstrap([2, 7, 9])

    expect(result).toEqual(payload)
    expect(fetchMock).toHaveBeenCalledOnce()
    expect(fetchMock).toHaveBeenCalledWith(
      'https://api.example.test/me/bootstrap?anime_ids=2,7,9',
      expect.objectContaining({
        headers: expect.objectContaining({ Authorization: 'Bearer token' })
      })
    )
  })

  it('throws a typed API error with status and response body', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: false,
      status: 409,
      json: async () => ({ code: 'duplicate', message: '名稱已存在' })
    })

    vi.stubGlobal('fetch', fetchMock)
    vi.stubGlobal('useRuntimeConfig', () => ({
      apiBaseUrlInternal: 'http://backend:8080',
      public: { apiBaseUrl: 'https://api.example.test' }
    }))
    vi.stubGlobal('useSession', () => ({
      session: { token: '', refreshToken: '' },
      updateTokens: vi.fn(),
      clearSession: vi.fn()
    }))

    const request = useApi().createCollection('重複名稱')

    await expect(request).rejects.toMatchObject({
      name: 'ApiError',
      message: '名稱已存在',
      status: 409,
      body: { code: 'duplicate', message: '名稱已存在' }
    } satisfies Partial<ApiError>)
  })
})
