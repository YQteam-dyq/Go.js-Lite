import { apiFetch } from './client'
import { mockApi } from './mock'
import type { DashboardData } from '@shared/types'

const USE_MOCK = false

export const dashboardApi = {
  get() {
    if (USE_MOCK) {
      return mockApi.getDashboard()
    }
    return apiFetch<DashboardData>('/dashboard')
  },
}
