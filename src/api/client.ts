const API_BASE = '/gojs/api'

export type ApiResponseType = 'json' | 'text' | 'blob'

export interface ApiFetchOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH'
  headers?: Record<string, string>
  body?: any
  signal?: AbortSignal
  params?: Record<string, string | number | boolean | null | undefined>
  responseType?: ApiResponseType
}

export async function apiFetch<T = unknown>(
  path: string,
  options: ApiFetchOptions = {},
): Promise<T> {
  const method = options.method || 'GET'
  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(options.headers || {}),
  }

  let body: BodyInit | undefined
  if (options.body !== undefined) {
    if (
      options.body instanceof FormData ||
      options.body instanceof Blob ||
      options.body instanceof ArrayBuffer
    ) {
      body = options.body as any
    } else {
      headers['Content-Type'] = headers['Content-Type'] || 'application/json'
      body = JSON.stringify(options.body)
    }
  }

  let url = path.startsWith('http') ? path : `${API_BASE}/${path.replace(/^\//, '')}`
  if (options.params) {
    const qs = new URLSearchParams()
    for (const [k, v] of Object.entries(options.params)) {
      if (v !== undefined && v !== null) qs.append(k, String(v))
    }
    const query = qs.toString()
    if (query) url += (url.includes('?') ? '&' : '?') + query
  }

  const csrf = getCsrfToken()
  if (csrf) {
    headers['X-CSRF-Token'] = csrf
  }

  const res = await fetch(url, {
    method,
    headers,
    body,
    credentials: 'include',
    signal: options.signal,
  })

  if (!res.ok) {
    const text = await res.text()
    let errBody: any = null
    try {
      errBody = text ? JSON.parse(text) : null
    } catch {
      errBody = null
    }
    throw buildApiError(res.status, errBody, res.statusText)
  }

  const responseType = options.responseType || 'json'

  if (responseType === 'blob') {
    return (await res.blob()) as unknown as T
  }
  if (responseType === 'text') {
    return (await res.text()) as unknown as T
  }

  const contentType = res.headers.get('content-type') || ''
  if (!contentType.includes('application/json')) {
    return undefined as unknown as T
  }

  let data: any = null
  try {
    data = await res.json()
  } catch {
    return undefined as unknown as T
  }

  if (data && typeof data === 'object') {
    if (data.ok === false) {
      const errBody = data.error
      const code = typeof errBody === 'string' ? errBody : errBody?.code
      const message =
        typeof errBody === 'string' ? errBody : errBody?.message || res.statusText || 'Request failed'
      throw buildApiError(res.status, errBody, message, code)
    }
    if (data.ok === true && 'data' in data) {
      return data.data as T
    }
  }
  return data as T
}

function buildApiError(
  status: number,
  errBody: any,
  fallbackMessage: string,
  fallbackCode?: string,
): ApiError {
  const raw = errBody && typeof errBody === 'object' ? errBody.error || errBody : errBody
  const code =
    (typeof raw === 'object' && (raw.code || fallbackCode)) ||
    fallbackCode ||
    httpErrorCode(status)
  const message =
    (typeof raw === 'object' && raw.message) ||
    (typeof raw === 'string' && raw) ||
    fallbackMessage ||
    `HTTP ${status}`
  const retryAfter =
    (typeof raw === 'object' && (raw.retryAfter ?? raw.retry_after)) || undefined
  return new ApiError(code, message, status, { retryAfter, ...(typeof raw === 'object' ? raw : {}) })
}

function httpErrorCode(status: number): string {
  switch (status) {
    case 400:
      return 'bad_request'
    case 401:
      return 'unauthorized'
    case 403:
      return 'forbidden'
    case 404:
      return 'not_found'
    case 409:
      return 'conflict'
    case 422:
      return 'validation_error'
    case 429:
      return 'rate_limited'
    default:
      return status >= 500 ? 'server_error' : `http_${status}`
  }
}

export function getCsrfToken(): string {
  if (typeof document === 'undefined') return ''
  const meta = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null
  if (meta?.content) return meta.content
  const match = document.cookie.match(/(?:^|;\s*)csrf_token=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

export function setCsrfToken(token: string): void {
  if (typeof document === 'undefined') return
  if (!token) return

  let meta = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null
  if (!meta) {
    meta = document.createElement('meta')
    meta.setAttribute('name', 'csrf-token')
    document.head.appendChild(meta)
  }
  meta.setAttribute('content', token)

  const oneYear = 60 * 60 * 24 * 365
  document.cookie = `csrf_token=${encodeURIComponent(token)}; path=/; max-age=${oneYear}; SameSite=Lax`
}

export function clearCsrfToken(): void {
  if (typeof document === 'undefined') return
  const meta = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null
  if (meta) meta.setAttribute('content', '')
  document.cookie = 'csrf_token=; path=/; max-age=0; SameSite=Lax'
}

export class ApiError extends Error {
  code: string
  status?: number
  retryAfter?: number
  payload?: any

  constructor(
    code: string | number,
    message: string,
    status?: number,
    payload?: any,
  ) {
    super(message)
    this.name = 'ApiError'
    if (typeof code === 'number') {
      this.status = code
      this.code = httpErrorCode(code)
      this.payload = payload
    } else {
      this.code = code
      this.status = status
      this.payload = payload
    }
    const retry = payload && (payload.retryAfter ?? payload.retry_after)
    if (typeof retry === 'number' && Number.isFinite(retry)) {
      this.retryAfter = retry
    }
  }
}