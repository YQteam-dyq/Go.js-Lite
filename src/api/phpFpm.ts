import { apiFetch } from './client'

export interface FpmStatus {
  pool: string | null
  active: number | null
  idle: number | null
  total: number | null
  max_active: number | null
  max_children_reached: number | null
  accepted_conn: number | null
  listen_queue: number | null
  requests_per_sec: number | null
  format: 'json' | 'text'
  raw: Record<string, unknown> | null
}

export interface FpmStatusResponse {
  url: string
  status: FpmStatus
}

export interface FpmSlowlogResponse {
  path: string
  count: number
  lines: string[]
}

export const phpFpmApi = {
  status: (port?: number) =>
    apiFetch<FpmStatusResponse>('/php/fpm/status', { params: { port } }),
  slowlog: (path?: string) =>
    apiFetch<FpmSlowlogResponse>('/php/fpm/slowlog', { params: { path } }),
}
