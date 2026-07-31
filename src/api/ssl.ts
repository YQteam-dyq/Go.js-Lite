import { apiFetch } from './client'
import type { SSLInfo, SSLListResponse } from '@shared/types'

export const sslApi = {
  list() {
    return apiFetch<SSLListResponse>('/ssl/list').then((res) => res.domains)
  },

  check(domain: string) {
    return apiFetch<SSLInfo>('/ssl/check', {
      method: 'POST',
      body: { domain },
    })
  },

  addDomain(domain: string) {
    return apiFetch<{ ok: boolean }>('/ssl/add-domain', {
      method: 'POST',
      body: { domain },
    })
  },

  removeDomain(domain: string) {
    return apiFetch<{ ok: boolean }>('/ssl/remove-domain', {
      method: 'POST',
      body: { domain },
    })
  },
}
