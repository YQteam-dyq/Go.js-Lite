import { apiFetch } from './client'
import type { HealthCheckData } from '@shared/types'

export const healthCheckApi = {
  get() {
    return apiFetch<HealthCheckData>('/health-check')
  },
}
