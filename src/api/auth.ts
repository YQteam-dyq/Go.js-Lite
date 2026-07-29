import { apiFetch } from './client'
import { mockApi } from './mock'
import type {
  AuthCredentials,
  BootstrapData,
  InstallRequest,
  UserSettings,
} from '@shared/types'

const USE_MOCK = false

export const authApi = {
  bootstrap() {
    if (USE_MOCK) return mockApi.bootstrap()
    return apiFetch<BootstrapData>('/bootstrap')
  },

  login(credentials: AuthCredentials) {
    if (USE_MOCK) return mockApi.login(credentials.password)
    return apiFetch<{ token: string }>('/login', {
      method: 'POST',
      body: credentials,
    })
  },

  logout() {
    if (USE_MOCK) return mockApi.logout()
    return apiFetch<void>('/logout', { method: 'POST' })
  },

  install(data: InstallRequest) {
    if (USE_MOCK) return mockApi.install(data.password)
    return apiFetch<BootstrapData>('/install', {
      method: 'POST',
      body: data,
    })
  },

  changePassword(oldPassword: string, newPassword: string) {
    if (USE_MOCK) return mockApi.changePassword()
    return apiFetch<void>('/change-password', {
      method: 'POST',
      body: { oldPassword, newPassword },
    })
  },

  getSettings() {
    return apiFetch<UserSettings>('/settings')
  },

  updateSettings(settings: Partial<UserSettings>) {
    return apiFetch<UserSettings>('/settings', {
      method: 'POST',
      body: settings,
    })
  },

  regenerateAccessToken() {
    return apiFetch<{ accessToken: string }>('/regenerate-access-token', {
      method: 'POST',
    })
  },

  async exportConfig(): Promise<void> {
    const blob = await apiFetch<Blob>('/settings/export', {
      responseType: 'blob',
    })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'gojs-config.json'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
  },

  resetPanel() {
    return apiFetch<{ success: boolean }>('/settings/reset', {
      method: 'POST',
    })
  },
}
