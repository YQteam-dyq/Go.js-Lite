import { apiFetch } from './client'
import type { ErrorLogData } from '@shared/types'

export const errorLogApi = {
  get(limit = 50) {
    return apiFetch<ErrorLogData>('/error-log', {
      params: { limit },
    })
  },

  clear() {
    return apiFetch<{ success: boolean }>('/error-log/clear', {
      method: 'POST',
    })
  },
}
