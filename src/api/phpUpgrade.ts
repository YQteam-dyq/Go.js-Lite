import { apiFetch } from './client'

export interface UpgradeBlocker {
  file: string
  line: number | null
  msg: string
  requires: string
}

export interface UpgradeCheckResponse {
  current: string
  required_constraint: string | null
  required_min: string | null
  recommended: string
  upgrade_needed: boolean
  blocker_count: number
  blockers: UpgradeBlocker[]
}

export interface AutoloadSuggestion {
  file: string
  line: number
  statement: string
  target: string
  suggestion: string
}

export interface AutoloadAuditResponse {
  backend_dir: string
  autoload_file: string
  vendor_autoload_present: boolean
  registered_count: number
  unregistered_count: number
  registered: AutoloadSuggestion[]
  suggestions: AutoloadSuggestion[]
}

export const phpUpgradeApi = {
  check: () => apiFetch<UpgradeCheckResponse>('/php/upgrade-check'),
  autoloadAudit: () => apiFetch<AutoloadAuditResponse>('/php/autoload-audit'),
}
