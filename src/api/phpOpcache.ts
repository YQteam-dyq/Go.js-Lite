import { apiFetch } from './client'

export interface OpcacheSummary {
  enabled: boolean
  hits: number
  misses: number
  hit_rate: number | null
  cached_scripts: number
  used_memory: number | null
  free_memory: number | null
  wasted_memory: number | null
  oom_restarts: number
  hash_restarts: number
  manual_restarts: number
  jit: Record<string, unknown> | null
}

export interface OpcacheStatusResponse {
  available: boolean
  summary: OpcacheSummary
  raw: Record<string, unknown>
  config: Record<string, string | false>
}

export interface IniApplyResult {
  directive: string
  target: string
  before: string | null
  after: string | null
  applied: boolean
}

export interface OpcacheMutationResponse {
  target?: string
  reset?: boolean
  summary?: OpcacheSummary
  runtime?: IniApplyResult
  effective?: boolean
  current?: string | false
  reload_required?: boolean
  applied?: IniApplyResult[]
  php_ini_required?: IniApplyResult[]
  profile?: string
}

export const phpOpcacheApi = {
  status: () => apiFetch<OpcacheStatusResponse>('/php/opcache/status'),
  reset: () => apiFetch<OpcacheMutationResponse>('/php/opcache/reset', { method: 'POST' }),
  toggle: (enable: boolean) =>
    apiFetch<OpcacheMutationResponse>('/php/opcache/toggle', { method: 'POST', body: { enable } }),
  applyProfile: () => apiFetch<OpcacheMutationResponse>('/php/opcache/profile', { method: 'POST' }),
}
