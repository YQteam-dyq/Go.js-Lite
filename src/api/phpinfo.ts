import { apiFetch } from './client'
import { mockApi } from './mock'
import type { PhpInfoData } from '@shared/types'

const USE_MOCK = false

export const phpInfoApi = {
  get() {
    if (USE_MOCK) {
      return mockApi.getPhpInfo()
    }
    return apiFetch<PhpInfoData>('/phpinfo')
  },

  getIni(search?: string) {
    return apiFetch<Record<string, string>>('/phpinfo/ini', {
      params: { search },
    })
  },
}
