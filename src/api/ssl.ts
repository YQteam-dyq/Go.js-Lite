import { apiFetch } from './client'
import type { SSLInfo, SSLListResponse, AcmeCapabilities, AcmeCertificatesResponse, AcmeIssueCertPayload } from '@shared/types'

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

  acmeCapabilities() {
    return apiFetch<AcmeCapabilities>('/ssl/capabilities-acme')
  },

  listCertificates() {
    return apiFetch<AcmeCertificatesResponse>('/ssl/certificates').then((res) => res.records)
  },

  issueCert(payload: AcmeIssueCertPayload) {
    return apiFetch<{ ok: boolean; certificate_id: string }>('/ssl/issue-cert', {
      method: 'POST',
      body: payload,
    })
  },

  renewCert(id: string) {
    return apiFetch<{ ok: boolean }>(`/ssl/certificates/${id}/renew`, {
      method: 'POST',
    })
  },

  removeCert(id: string) {
    return apiFetch<{ ok: boolean }>(`/ssl/certificates/${id}`, {
      method: 'DELETE',
    })
  },

  downloadPem(id: string) {
    return apiFetch<Blob>(`/ssl/certificates/${id}/download-pem`, {
      method: 'POST',
      responseType: 'blob',
    })
  },

  updateAutoRenew(id: string, daysBefore: number) {
    return apiFetch<{ ok: boolean; auto_renew_days_before: number }>(`/ssl/certificates/${id}/auto-renew`, {
      method: 'PATCH',
      body: { auto_renew_days_before: daysBefore },
    })
  },
}
