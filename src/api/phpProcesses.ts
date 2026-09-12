import { apiFetch } from './client'

export interface PhpProcess {
  pid: number
  user: string | null
  mem_percent: number | null
  cpu_percent: number | null
  elapsed: string | null
  cmdline: string
  mem_kb: number | null
}

export interface PhpProcessesResponse {
  supported: boolean
  os: string
  self_pid: number | null
  php_binary: string
  sapi: string
  count: number
  processes: PhpProcess[]
}

export interface SnapshotResponse {
  path: string
  bytes: number
  modules_lines: number
  php_binary: string
}

export interface SnapshotDownloadResponse {
  path: string
  bytes: number
  content: string
}

export const phpProcessesApi = {
  list: () => apiFetch<PhpProcessesResponse>('/php/processes'),
  snapshot: () => apiFetch<SnapshotResponse>('/php/processes/snapshot', { method: 'POST' }),
  snapshotContent: () => apiFetch<SnapshotDownloadResponse>('/php/processes/snapshot'),
}
