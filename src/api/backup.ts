import { apiFetch } from './client'
import type {
  BackupCreateRequest,
  BackupCreateResult,
  BackupListResponse,
  BackupRestoreResult,
} from '@shared/types'

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

  /**
   * 下载备份。后端为 GET /backup/download?filename=...，CSRF 检查不拦截 GET，
   * 直接通过原生 <a download> 触发浏览器下载，避免 fetch 把大文件拉成 ArrayBuffer。
   */
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
}
