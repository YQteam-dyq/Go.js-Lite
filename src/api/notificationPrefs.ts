import { apiFetch } from './client'

export interface NotificationChannelPrefs {
  severity_min: string
  categories: string[]
}

export interface NotificationPreferences {
  email: NotificationChannelPrefs
  inapp: NotificationChannelPrefs
}

export interface NotificationPreferencesResponse {
  notifications: NotificationPreferences
  defaults: NotificationPreferences
  levels: string[]
  categories: string[]
  role: string
}

export const notificationPrefsApi = {
  get: () => apiFetch<NotificationPreferencesResponse>('/notification-preferences'),
  update: (notifications: Partial<NotificationPreferences>) =>
    apiFetch<NotificationPreferencesResponse>('/notification-preferences', {
      method: 'PATCH',
      body: { notifications },
    }),
}
