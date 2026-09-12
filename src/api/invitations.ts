import { apiFetch } from './client'

export type InvitationStatus = 'pending' | 'accepted' | 'expired' | 'revoked'
export type InvitationRole = 'admin' | 'operator' | 'viewer'

export interface InvitationRecord {
  id: string
  email: string
  role: InvitationRole
  path_allowlist: string[]
  groups: string[]
  message: string
  token_prefix: string
  expires_at: number
  created_at: number
  created_by: string | null
  accepted_at: number
  accepted_user_id: string | null
  status: InvitationStatus
  token?: string
  invite_url?: string
}

export interface InvitationListResponse {
  invitations: InvitationRecord[]
  total: number
}

export interface CreateInvitationInput {
  email: string
  role: InvitationRole
  path_allowlist?: string[]
  groups?: string[]
  message?: string
}

export interface InvitationPreview {
  status: InvitationStatus
  role: InvitationRole
  email_masked: string
  expires_at: number
  suggested_username: string
}

export interface AcceptInvitationInput {
  token: string
  username: string
  password: string
}

export const invitationsApi = {
  list: () => apiFetch<InvitationListResponse>('/invitations'),
  create: (input: CreateInvitationInput) =>
    apiFetch<InvitationRecord>('/invitations', { method: 'POST', body: input }),
  revoke: (id: string) =>
    apiFetch<{ success: boolean }>(`/invitations/${id}`, { method: 'DELETE' }),
  preview: (token: string) =>
    apiFetch<InvitationPreview>('/invitations/preview', { params: { token } }),
  accept: (input: AcceptInvitationInput) =>
    apiFetch<{ success: boolean; username: string; role: string }>('/invitations/accept', {
      method: 'POST',
      body: input,
    }),
}
