import { apiFetch } from './client'

export interface ExportCreateResponse {
  ok: boolean
  export_id: string
  bytes: number
  signed_expires_at: number
  download_path: string
}

export interface ExportDownloadTarget {
  export_id: string
  exp: number
  sig: string
}

export const profileExportApi = {
  create: () => apiFetch<ExportCreateResponse>('/profile/export', { method: 'POST' }),

  downloadUrl(exportId: string, exp: number, sig: string): string {
    const qs = new URLSearchParams({ exp: String(exp), sig })
    return `/gojs/api/profile/export/${exportId}?${qs.toString()}`
  },

  download(exportId: string, exp: number, sig: string) {
    return apiFetch<Blob>(`profile/export/${exportId}`, {
      params: { exp, sig },
      responseType: 'blob',
    })
  },
}

export function parseExportPath(downloadPath: string): ExportDownloadTarget | null {
  if (!downloadPath) return null
  const queryIndex = downloadPath.indexOf('?')
  if (queryIndex === -1) return null
  const pathPart = downloadPath.slice(0, queryIndex)
  const params = new URLSearchParams(downloadPath.slice(queryIndex + 1))

  const match = pathPart.match(/profile\/export\/([A-Za-z0-9_]+)$/)
  if (!match) return null

  const exp = Number(params.get('exp'))
  const sig = params.get('sig') || ''
  if (!Number.isFinite(exp) || exp <= 0 || sig === '') return null

  return { export_id: match[1], exp, sig }
}
