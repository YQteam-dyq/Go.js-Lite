import { apiFetch } from './client'
import type { UserSettings } from '@shared/types'

export type UserRole = 'admin' | 'operator' | 'viewer'

export interface UserRecord {
  id: string
  username: string
  role: UserRole
  path_allowlist: string[]
  permissions_boost?: string[]
  disabled: boolean
  created_at: number
  last_login_at: number
  avatar_color?: string
  preferences?: UserSettings
}

export interface ProfileResponse extends UserRecord {}

export interface UserListResponse {
  users: UserRecord[]
  total: number
}

export interface CreateUserInput {
  username: string
  password: string
  role: UserRole
  path_allowlist?: string[]
  permissions_boost?: string[]
}

export interface UpdateUserInput {
  username?: string
  role?: UserRole
  path_allowlist?: string[]
  permissions_boost?: string[]
  disabled?: boolean
  password?: string
}

export interface ActiveSession {
  sid: string
  user_id: string | null
  login_at: number
  last_activity_at: number
  ip: string
  ua: string
}

export interface SessionListResponse {
  sessions: ActiveSession[]
  total: number
}

export const usersApi = {
  list: () => apiFetch<UserListResponse>('/users'),
  create: (input: CreateUserInput) =>
    apiFetch<UserRecord>('/users', { method: 'POST', body: input }),
  update: (id: string, input: UpdateUserInput) =>
    apiFetch<UserRecord>(`/users/${id}`, { method: 'PATCH', body: input }),
  remove: (id: string) =>
    apiFetch<{ success: boolean }>(`/users/${id}`, { method: 'DELETE' }),

  sessions: {
    list: () => apiFetch<SessionListResponse>('/sessions'),
    kick: (sid: string) =>
      apiFetch<{ success: boolean }>(`/sessions/${encodeURIComponent(sid)}/kick`, {
        method: 'POST',
        body: { sid },
      }),
  },
  logoutAll: () =>
    apiFetch<{ success: boolean }>('/logout-all', { method: 'POST' }),

  profile: {
    get: () => apiFetch<ProfileResponse>('/profile'),
    update: (prefs: Partial<UserSettings>) =>
      apiFetch<UserSettings>('/profile', { method: 'PATCH', body: prefs }),
  },
}