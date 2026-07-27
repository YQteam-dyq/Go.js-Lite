import { apiFetch } from './client'
import { mockApi } from './mock'
import type { SystemData, ProcessInfo, CronJob } from '@shared/types'

const USE_MOCK = false

export const systemApi = {
  get() {
    if (USE_MOCK) return mockApi.getSystem()
    return apiFetch<SystemData>('/system')
  },

  processes() {
    if (USE_MOCK) return mockApi.getProcesses()
    return apiFetch<ProcessInfo[]>('/system/processes')
  },

  cron() {
    if (USE_MOCK) return mockApi.getCron()
    return apiFetch<CronJob[]>('/system/cron')
  },
}
