import { apiFetch } from './client'
import type {
  HtaccessData,
  HtaccessGenerateRequest,
  HtaccessGenerateResult,
} from '@shared/types'

export const htaccessApi = {
  get() {
    return apiFetch<HtaccessData>('/htaccess')
  },

  save(content: string) {
    return apiFetch<{ success: boolean }>('/htaccess', {
      method: 'PUT',
      body: { content },
    })
  },

  generate(params: HtaccessGenerateRequest) {
    return apiFetch<HtaccessGenerateResult>('/htaccess/generate', {
      method: 'POST',
      body: params,
    })
  },

  reset() {
    return apiFetch<{ success: boolean; content: string }>('/htaccess/reset', {
      method: 'POST',
    })
  },
}
