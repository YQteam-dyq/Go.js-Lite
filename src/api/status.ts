import { apiFetch } from './client'
import type { BootstrapData, DiskAnalysisData, HealthCheckData } from '@shared/types'

export interface PublicStatusData {
  installed: boolean
  authenticated: boolean
  backendVersion: string
  frontendVersion: string
  phpVersion: string
  sapi: string
}

export const statusApi = {
  async get(): Promise<PublicStatusData> {
    const bootstrap = await apiFetch<BootstrapData>('/bootstrap')
    const caps = bootstrap.capabilities
    return {
      installed: bootstrap.installed,
      authenticated: bootstrap.authenticated,
      backendVersion: bootstrap.backendVersion,
      frontendVersion: bootstrap.frontendVersion,
      phpVersion: caps ? caps.phpVersion : '',
      sapi: caps ? caps.sapi : '',
    }
  },

  health() {
    return apiFetch<HealthCheckData>('/health-check')
  },

  disk() {
    return apiFetch<DiskAnalysisData>('/disk-analysis')
  },
}
