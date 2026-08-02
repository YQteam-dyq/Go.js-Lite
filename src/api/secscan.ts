import { apiFetch } from './client'
import type { SecurityScanFrontendResult, SecurityScanBackendResult } from '@shared/types'

export const secscanApi = {
  frontend: (force = false) =>
    apiFetch<SecurityScanFrontendResult>(
      'secscan/frontend',
      force ? { method: 'POST' } : {},
    ),
  backend: (force = false) =>
    apiFetch<SecurityScanBackendResult>(
      'secscan/backend',
      force ? { method: 'POST' } : {},
    ),
}
