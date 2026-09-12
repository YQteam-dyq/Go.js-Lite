import { apiFetch } from './client'

export interface ComposerLockStats {
  packages: number
  dev_packages: number
  depth: number
  content_hash: string | null
  plugin_api_version: string | null
  platform: Record<string, string>
}

export interface ComposerStatus {
  available: boolean
  executable: string | null
  php_version: string
  php_requirement: string | null
  composer_json: boolean
  composer_lock: boolean
  composer_json_path: string | null
  composer_lock_path: string | null
  vendor_autoload: string | null
  vendor_present: boolean
  lock: ComposerLockStats
  install_guide: { url: string; steps: string[] } | null
}

export interface ComposerActionResponse {
  ok?: boolean
  package?: string
  log: string
}

export interface ComposerJsonResponse {
  json: Record<string, unknown> | null
  lock: Record<string, unknown> | null
  json_path: string | null
  lock_path: string | null
}

export const composerApi = {
  status: () => apiFetch<ComposerStatus>('/composer/status'),
  install: () => apiFetch<ComposerActionResponse>('/composer/install', { method: 'POST' }),
  requirePackage: (packageName: string, version?: string) =>
    apiFetch<ComposerActionResponse>('/composer/require', {
      method: 'POST',
      body: { package: packageName, version },
    }),
  update: () => apiFetch<ComposerActionResponse>('/composer/update', { method: 'POST' }),
  json: () => apiFetch<ComposerJsonResponse>('/composer/json'),
}
