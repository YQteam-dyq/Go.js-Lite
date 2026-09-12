import { apiFetch } from './client'

export interface ActivityAggregateRow {
  key: string
  total: number
  failed: number
  error_rate: number
  last_at: number
}

export interface ActivityAggregateResponse {
  since: string
  since_ts: number | null
  total: number
  failed: number
  rows: ActivityAggregateRow[]
}

export interface AuditAggregateResponse extends ActivityAggregateResponse {
  by: 'user_id' | 'action' | 'hour'
}

export interface ActivityEntry {
  time?: string
  timestamp: number
  ip?: string
  action: string
  target?: string
  result?: boolean
  detail?: string
  user_id?: string
}

export interface ActivityUserFeedResponse {
  user_id: string
  username: string | null
  role: string | null
  since: string
  since_ts: number | null
  count: number
  entries: ActivityEntry[]
}

export type ActivityAggregateBy = 'user_id' | 'action' | 'hour'

export const userActivityApi = {
  recent: (since = '24h') =>
    apiFetch<ActivityAggregateResponse>('/user_activity/recent', { params: { since } }),
  aggregate: (since = '24h', by: ActivityAggregateBy = 'user_id') =>
    apiFetch<AuditAggregateResponse>('/audit/aggregate', { params: { since, by } }),
  online: () => apiFetch<{ sessions: unknown[]; total: number }>('/user_activity/online'),
  userFeed: (userId: string, since = '7d', limit = 100) =>
    apiFetch<ActivityUserFeedResponse>(`/user_activity/${encodeURIComponent(userId)}`, {
      params: { since, limit },
    }),
}
