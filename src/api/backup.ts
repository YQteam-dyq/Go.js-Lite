import { apiFetch } from './client'
import type {
  BackupCreateRequest,
  BackupCreateResult,
  BackupListResponse,
  BackupRestoreResult,
  BackupSchedule,
  BackupRunRecord,
} from '@shared/types'

export interface BackupScheduleCreateInput {
  name: string
  enabled?: boolean
  cron_expr: string
  destination_ids: string[]
  source: {
    include_files?: boolean
    include_db?: boolean
    include_config?: boolean
    exclude_dirs?: string[]
  }
  retention: {
    keep_last?: number
    keep_daily?: number
    keep_weekly?: number
    keep_monthly?: number
  }
}

export type BackupScheduleUpdateInput = BackupScheduleCreateInput

export interface BackupSchedulesListResponse {
  schedules: BackupSchedule[]
}

export interface BackupScheduleResponse {
  schedule: BackupSchedule
}

export interface BackupRunNowResponse {
  run_id: string
  ok: boolean
}

export interface BackupRunsListResponse {
  runs: BackupRunRecord[]
  total: number
  limit: number
  offset: number
}

export interface BackupRunResponse {
  run: BackupRunRecord
}

export const backupApi = {
  list() {
    return apiFetch<BackupListResponse>('/backup/list')
  },

  create(data: BackupCreateRequest) {
    return apiFetch<BackupCreateResult>('/backup/create', {
      method: 'POST',
      body: data,
    })
  },

  delete(filename: string) {
    return apiFetch<{ success: boolean }>('/backup/delete', {
      method: 'POST',
      body: { filename },
    })
  },

  restore(filename: string) {
    return apiFetch<BackupRestoreResult>('/backup/restore', {
      method: 'POST',
      params: { filename },
    })
  },

  download(filename: string) {
    const base = (import.meta.env.BASE_URL || '/').replace(/\/$/, '')
    const params = new URLSearchParams({ filename })
    const url = `${base}/api/backup/download?${params.toString()}`
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
  },

  listSchedules() {
    return apiFetch<BackupSchedulesListResponse>('/backup/schedules')
  },

  createSchedule(data: BackupScheduleCreateInput) {
    return apiFetch<BackupScheduleResponse>('/backup/schedules', {
      method: 'POST',
      body: data,
    })
  },

  updateSchedule(id: string, data: BackupScheduleUpdateInput) {
    return apiFetch<BackupScheduleResponse>(`/backup/schedules/${id}`, {
      method: 'PUT',
      body: data,
    })
  },

  deleteSchedule(id: string) {
    return apiFetch<{ ok: boolean }>(`/backup/schedules/${id}`, {
      method: 'DELETE',
    })
  },

  runScheduleNow(id: string) {
    return apiFetch<BackupRunNowResponse>(`/backup/schedules/${id}/run-now`, {
      method: 'POST',
    })
  },

  listRuns(params?: { schedule_id?: string; limit?: number; offset?: number }) {
    return apiFetch<BackupRunsListResponse>('/backup/runs', {
      params,
    })
  },

  getRun(id: string) {
    return apiFetch<BackupRunResponse>(`/backup/runs/${id}`)
  },
}
