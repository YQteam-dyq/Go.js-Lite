import { apiFetch } from './client'
import type { MonitorReport } from '@shared/types'

export const monitorApi = {
  status() {
    return apiFetch<MonitorReport>('/monitor')
  },
}
