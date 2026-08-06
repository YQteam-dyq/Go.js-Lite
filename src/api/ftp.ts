import { apiFetch } from './client'
import type {
  FtpCapabilities,
  FtpAccount,
  FtpProvider,
  FtpAccountCreateInput,
  FtpAccountUpdateInput,
} from '@shared/types'

export interface FtpListResponse {
  accounts: FtpAccount[]
}

export interface FtpSyncResponse {
  ok: boolean
  count: number
}

export interface FtpTestLoginResponse {
  ok: boolean
}

export const ftpApi = {
  capabilities() {
    return apiFetch<FtpCapabilities>('/ftp/capabilities')
  },

  list() {
    return apiFetch<FtpListResponse>('/ftp/accounts').then((res) => res.accounts)
  },

  create(data: FtpAccountCreateInput) {
    return apiFetch<{ account: FtpAccount }>('/ftp/accounts', {
      method: 'POST',
      body: data,
    }).then((res) => res.account)
  },

  update(id: string, data: FtpAccountUpdateInput) {
    return apiFetch<{ account: FtpAccount }>(`/ftp/accounts/$$id}`, {
      method: 'PUT',
      body: data,
    }).then((res) => res.account)
  },

  remove(id: string) {
    return apiFetch<{ ok: boolean }>(`/ftp/accounts/$$id}`, {
      method: 'DELETE',
    })
  },

  testLogin(id: string, password: string) {
    return apiFetch<FtpTestLoginResponse>(`/ftp/accounts/$$id}/test-login`, {
      method: 'POST',
      body: { password },
    })
  },

  sync() {
    return apiFetch<FtpSyncResponse>('/ftp/sync', {
      method: 'POST',
    })
  },

  async exportBlob(format: FtpProvider = 'proftpd_authfile'): Promise<Blob> {
    return apiFetch<Blob>('/ftp/export', {
      method: 'POST',
      body: { format },
      responseType: 'blob',
    })
  },
}
