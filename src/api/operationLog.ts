import { apiFetch } from './client'
import type { OperationLogData } from '@shared/types'

export const operationLogApi = {
  list(params?: { type?: string; ip?: string; page?: number }) {
    return apiFetch<OperationLogData>('/operation-log', {
      params: {
        type: params?.type,
        ip: params?.ip,
        page: params?.page,
      },
    })
  },

  clear() {
    return apiFetch<{ ok: boolean }>('/operation-log/clear', {
      method: 'POST',
    })
  },
}
