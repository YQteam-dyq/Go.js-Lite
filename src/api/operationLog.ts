import { apiFetch } from './client'
import type { OperationLogData, OperationLogAlertRule } from '@shared/types'

export interface ExportParams {
  format: 'csv' | 'jsonl'
  scope: 'current_filter' | 'all'
  action?: string[]
  ip_like?: string
  from_ts?: number
  to_ts?: number
}

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

  exportBlob(params: ExportParams) {
    return apiFetch<Blob>('/operation-log/export', {
      method: 'POST',
      body: params,
      responseType: 'blob',
    })
  },
}

export const alertRulesApi = {
  list() {
    return apiFetch<OperationLogAlertRule[]>('/alert-rules')
  },

  create(data: Omit<OperationLogAlertRule, 'id'>) {
    return apiFetch<OperationLogAlertRule>('/alert-rules', {
      method: 'POST',
      body: data,
    })
  },

  update(id: string, patch: Partial<OperationLogAlertRule>) {
    return apiFetch<OperationLogAlertRule>(`/alert-rules/${id}`, {
      method: 'PUT',
      body: patch,
    })
  },

  remove(id: string) {
    return apiFetch<{ ok: boolean }>(`/alert-rules/${id}`, {
      method: 'DELETE',
    })
  },

  test(id: string) {
    return apiFetch<{ ok: boolean; fired: boolean }>(`/alert-rules/${id}/test`, {
      method: 'POST',
    })
  },
}
