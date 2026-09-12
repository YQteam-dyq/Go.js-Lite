import { apiFetch } from './client'

export type IniSeverity = 'info' | 'warning' | 'danger'

export interface IniDiffRow {
  directive: string
  current: string | null
  recommended: string
  severity: IniSeverity
  note: string
  match: boolean
}

export interface IniDiffResponse {
  loaded_file: string | false
  scanned_file: string | false
  baseline_file: string | null
  total: number
  mismatch: number
  rows: IniDiffRow[]
}

export type JitMode = 'tracing' | 'function' | 'none'

export interface JitState {
  raw_mode: string | null
  mode: JitMode | 'custom'
  buffer_size: string | null
  buffer_size_mb: number | null
  user_ini_path: string | null
}

export interface JitSetResponse {
  saved_to_ini: boolean
  user_ini_path: string | null
  runtime: { directive: string; applied: boolean; before: string | null; after: string | null }[]
  effective: boolean
  current: JitState
  reload_required: boolean
}

export interface IncludePathResponse {
  current: string | false
  user_ini_path: string | null
  user_ini: Record<string, string>
  user_ini_include_path: string | null
  writable: boolean
  separator?: string
}

export interface IncludePathSetResponse {
  saved: boolean
  user_ini_path: string | null
  include_path: string
  paths: string[]
  reload_required: boolean
}

export const phpIniApi = {
  diff: () => apiFetch<IniDiffResponse>('/php/ini-diff'),
  jit: () => apiFetch<JitState>('/php/jit'),
  setJit: (mode: JitMode, bufferSizeMb: number) =>
    apiFetch<JitSetResponse>('/php/jit', { method: 'POST', body: { mode, buffer_size_mb: bufferSizeMb } }),
  includePath: () => apiFetch<IncludePathResponse>('/php/include-path'),
  setIncludePath: (paths: string[]) =>
    apiFetch<IncludePathSetResponse>('/php/include-path', { method: 'POST', body: { paths } }),
}
