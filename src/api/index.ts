import { getCsrfToken } from './client'
import type { ApiResponse } from '@shared/types'

async function rawFetch<T = unknown>(path: string, options: { method?: string; body?: unknown; params?: Record<string, string | number | undefined> } = {}): Promise<ApiResponse<T>> {
  const { method = 'GET', body, params } = options
  const base = (import.meta.env.BASE_URL || '/').replace(/\/$/, '')
  let url = base + `/api${path.startsWith('/') ? path : '/' + path}`

  if (params) {
    const search = new URLSearchParams()
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined) search.append(k, String(v))
    })
    const qs = search.toString()
    if (qs) url += '?' + qs
  }

  const headers: Record<string, string> = {}
  if (body !== undefined && !(body instanceof FormData)) {
    headers['Content-Type'] = 'application/json'
  }
  const token = getCsrfToken()
  if (token && method !== 'GET') {
    headers['X-CSRF-Token'] = token
  }

  const res = await fetch(url, {
    method,
    headers,
    body: body === undefined ? undefined : body instanceof FormData ? body : JSON.stringify(body),
    credentials: 'same-origin',
  })

  if (res.status === 401) {
    window.dispatchEvent(new CustomEvent('auth:expired'))
    return { ok: false, error: { code: 'unauthorized', message: '登录已过期' } }
  }

  if (res.status === 403) {
    const data = await res.json().catch(() => ({}))
    return { ok: false, error: { code: data?.error?.code || 'forbidden', message: data?.error?.message || '权限不足' } }
  }

  if (res.status === 404) {
    const data = await res.json().catch(() => ({}))
    if (data?.error?.code === 'not_found') {
      window.dispatchEvent(new CustomEvent('access:denied'))
    }
    return { ok: false, error: { code: data?.error?.code || 'not_found', message: data?.error?.message || 'Not Found' } }
  }

  if (res.status === 429) {
    return { ok: false, error: { code: 'rate_limited', message: '请求过于频繁，请稍后再试' } }
  }

  if (res.status >= 400 && res.status < 500) {
    const data = await res.json().catch(() => ({}))
    return { ok: false, error: { code: data?.error?.code || 'bad_request', message: data?.error?.message || '请求错误' } }
  }

  if (res.status >= 500) {
    return { ok: false, error: { code: 'server_error', message: '服务器错误' } }
  }

  const data = (await res.json()) as ApiResponse<T>
  return data
}

export async function apiGet<T = unknown>(path: string, params?: Record<string, string | number | undefined>) {
  return rawFetch<T>(path, { method: 'GET', params })
}

export async function apiPost<T = unknown>(path: string, body?: unknown) {
  return rawFetch<T>(path, { method: 'POST', body })
}