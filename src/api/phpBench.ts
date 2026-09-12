import { apiFetch } from './client'

export interface BenchItem {
  name: string
  available: boolean
  reason: string | null
  avg_us: number | null
  ops_per_sec: number | null
}

export interface BenchRunResponse {
  id: string
  created_at: number
  iterations: number
  duration_ms: number
  items: BenchItem[]
}

export interface BenchCompareRow {
  name: string
  a_avg_us: number | null
  b_avg_us: number | null
  diff_pct: number | null
  faster: 'a' | 'b' | 'equal' | null
}

export interface BenchCompareResponse {
  a: { id: string; created_at: number | null; php_version: string | null }
  b: { id: string; created_at: number | null; php_version: string | null }
  rows: BenchCompareRow[]
}

export const phpBenchApi = {
  run: (iterations?: number) =>
    apiFetch<BenchRunResponse>('/php/bench/run', { method: 'POST', body: { iterations } }),
  compare: (version?: string) =>
    apiFetch<BenchCompareResponse>('/php/bench/compare', { params: { version } }),
}
