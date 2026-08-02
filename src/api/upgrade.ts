import { apiFetch } from './client'
import type { UpgradeCheckResult, UpgradeProgress } from '@shared/types'

export interface UpgradeApplyResult {
  ok: boolean
  backup_dir: string
  latest_version: string
}

export const upgradeApi = {
  check() {
    return apiFetch<UpgradeCheckResult>('/upgrade/check')
  },

  apply() {
    return apiFetch<UpgradeApplyResult>('/upgrade/apply', {
      method: 'POST',
    })
  },

  progress() {
    return apiFetch<UpgradeProgress>('/upgrade/progress')
  },
}
