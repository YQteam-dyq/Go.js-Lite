import { apiFetch } from './client'

export interface TrustedDevice {
  fingerprint: string
  ip: string
  ua: string
  first_seen_at: number
  trusted_at: number
  expires_at: number
  current?: boolean
}

export interface DeviceListResponse {
  devices: TrustedDevice[]
  total: number
  ttl_days: number
}

export const devicesApi = {
  list: () => apiFetch<DeviceListResponse>('/devices'),
  trust: () => apiFetch<TrustedDevice>('/devices/trust', { method: 'POST' }),
  revoke: (fingerprint: string) =>
    apiFetch<{ success: boolean }>(`/devices/${fingerprint}`, { method: 'DELETE' }),
}
