import type { ApiResponse } from '@shared/types'
import { resolveErrorText } from '@/lib/errorMessages'

let csrfToken = ''

export function setCsrfToken(token: string) {
  csrfToken = token
}

export function getCsrfToken() {
  return csrfToken
}

interface FetchOptions extends Omit<RequestInit, 'body'> {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  params?: Record<string, string | number | boolean | undefined>
  body?: unknown
  json?: boolean
  responseType?: 'json' | 'blob' | 'text'
}

export async function apiFetch<T = unknown>(
  path: string,
  options: FetchOptions = {},
): Promise<T> {
  const { method = 'GET', params, body, json = true, responseType = 'json' } = options

  const base = (import.meta.env.BASE_URL || '/').replace(/\/$/, '')
  let url = base + `/api${path.startsWith('/') ? path : '/' + path}`

  if (params) {
    const search = new URLSearchParams()
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null) {
        search.append(k, String(v))
      }
    })
    const qs = search.toString()
    if (qs) url += '?' + qs
  }

  const headers: Record<string, string> = {}

  if (json && body !== undefined && !(body instanceof FormData)) {
    headers['Content-Type'] = 'application/json'
  }

  if (csrfToken && method !== 'GET') {
    headers['X-CSRF-Token'] = csrfToken
  }

  const res = await fetch(url, {
    method,
    headers,
    body:
      body === undefined
        ? undefined
        : body instanceof FormData
          ? body
          : JSON.stringify(body),
    credentials: 'same-origin',
  })

  if (res.status === 401) {
    window.dispatchEvent(new CustomEvent('auth:expired'))
    throw new ApiError('unauthorized', '登录已过期', res.status)
  }

  if (res.status === 403) {
    const data = await safeParseJson(res)
    throw new ApiError(data?.error?.code || 'forbidden', data?.error?.message || '权限不足', res.status)
  }

  if (res.status === 404) {
    const data = await safeParseJson(res)
    if (data?.error?.code === 'not_found') {
      window.dispatchEvent(new CustomEvent('access:denied'))
    }
    throw new ApiError(data?.error?.code || 'not_found', data?.error?.message || 'Not Found', res.status)
  }

  if (res.status === 429) {
    const data = await safeParseJson(res)
    const errCode = data?.error?.code || 'rate_limited'
    const errMsg = data?.error?.message || '请求过于频繁，请稍后再试'
    const retryAfter = typeof data?.error?.retry_after === 'number' ? data.error.retry_after : undefined
    throw new ApiError(errCode, errMsg, res.status, retryAfter)
  }

  if (res.status >= 400 && res.status < 500) {
    const data = await safeParseJson(res)
    throw new ApiError(
      data?.error?.code || 'bad_request',
      data?.error?.message || '请求错误',
      res.status,
    )
  }

  if (res.status >= 500) {
    throw new ApiError('server_error', '服务器错误', res.status)
  }

  if (responseType === 'blob') {
    return res.blob() as unknown as T
  }
  if (responseType === 'text') {
    return res.text() as unknown as T
  }

  const data = (await res.json()) as ApiResponse<T>

  if (!data.ok || data.error) {
    throw new ApiError(data.error?.code || 'unknown', data.error?.message || '请求失败', res.status)
  }

  return data.data as T
}

async function safeParseJson(res: Response) {
  try {
    return await res.json()
  } catch {
    return null
  }
}

export class ApiError extends Error {
  code: string
  status: number
  retryAfter?: number

  constructor(code: string, message: string, status: number, retryAfter?: number) {
    super(message)
    this.code = code
    this.status = status
    this.retryAfter = retryAfter
    this.name = 'ApiError'
  }

  
  getLocalizedMessage(): string {
    return resolveErrorText(this)
  }
}
