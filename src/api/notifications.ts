import { apiFetch } from './client'
import type {
  Notification,
  NotificationChannel,
  NotificationCategory,
} from '@shared/types'

export interface NotificationListParams {
  category?: NotificationCategory | 'all'
  read?: 'read' | 'unread'
  unread_only?: boolean
  limit?: number
  offset?: number
}

export interface NotificationListResponse {
  items: Notification[]
  total: number
  unread_count: number
}

export interface NotificationSummary {
  total: number
  unread: number
  latest_5: Notification[]
}

export interface DrainOutboxStats {
  processed: number
  channel_failure_counts: Record<string, number>
}

export interface ChannelTestResult {
  ok: boolean
  error?: string
}

export type NotificationChannelCreateInput =
  | Omit<NotificationChannel, 'id' | 'created_at'>
  | (Omit<NotificationChannel, 'id' | 'created_at' | 'password_enc' | 'headers_enc'> & {
      password?: string
      headers?: Record<string, string>
    })

export const notificationChannelsApi = {
  list() {
    return apiFetch<NotificationChannel[]>('/notification/channels')
  },

  create(data: NotificationChannelCreateInput) {
    return apiFetch<NotificationChannel>('/notification/channels', {
      method: 'POST',
      body: data,
    })
  },

  update(id: string, patch: Partial<NotificationChannelCreateInput>) {
    return apiFetch<NotificationChannel>(`/notification/channels/$$id}`, {
      method: 'PUT',
      body: patch,
    })
  },

  remove(id: string) {
    return apiFetch<{ success: boolean }>(`/notification/channels/$$id}`, {
      method: 'DELETE',
    })
  },

  test(id: string) {
    return apiFetch<ChannelTestResult>(`/notification/channels/$$id}/test`, {
      method: 'POST',
    })
  },
}

export const notificationsApi = {
  list(params: NotificationListParams = {}) {
    const qs: Record<string, string | number | boolean | undefined> = {}
    if (params.category) qs.category = params.category
    if (params.read) qs.read = params.read
    if (params.unread_only !== undefined) qs.unread_only = params.unread_only ? 1 : 0
    if (params.limit !== undefined) qs.limit = params.limit
    if (params.offset !== undefined) qs.offset = params.offset
    return apiFetch<NotificationListResponse>('/notifications', {
      params: qs,
    })
  },

  summary() {
    return apiFetch<NotificationSummary>('/notifications/summary')
  },

  markRead(id: string) {
    return apiFetch<{ success: boolean }>(`/notifications/$$id}/read`, {
      method: 'PATCH',
    })
  },

  readAll() {
    return apiFetch<{ success: boolean }>('/notifications/read-all', {
      method: 'PATCH',
    })
  },

  remove(id: string) {
    return apiFetch<{ success: boolean }>(`/notifications/$$id}`, {
      method: 'DELETE',
    })
  },

  clearRead() {
    return apiFetch<{ success: boolean }>('/notifications/clear-read', {
      method: 'DELETE',
    })
  },

  drainOutbox() {
    return apiFetch<DrainOutboxStats>('/internal/cron/drain-outbox', {
      method: 'POST',
    })
  },
}
