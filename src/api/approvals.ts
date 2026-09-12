import { apiFetch } from './client'

export type ApprovalStatus = 'pending' | 'approved' | 'denied' | 'expired'

export interface ApprovalRecord {
  id: string
  action: string
  api: string
  method: string
  payload: Record<string, unknown>
  requester: string
  expires_at: number
  status: ApprovalStatus
  second_factor_user_id: string | null
  decided_at: number
  reason: string
  created_at: number
}

export interface ApprovalListResponse {
  pending: ApprovalRecord[]
  mine: ApprovalRecord[]
  total: number
  pending_total: number
  admin_count: number
  ttl_seconds: number
  policy: string[]
}

export interface ApprovalDecisionResponse {
  status: ApprovalStatus
  approval: ApprovalRecord
  result: unknown
}

export const approvalsApi = {
  list: () => apiFetch<ApprovalListResponse>('/approvals'),
  approve: (id: string, reason = '') =>
    apiFetch<ApprovalDecisionResponse>(`/approvals/${id}/approve`, {
      method: 'POST',
      body: { reason },
    }),
  deny: (id: string, reason = '') =>
    apiFetch<ApprovalDecisionResponse>(`/approvals/${id}/deny`, {
      method: 'POST',
      body: { reason },
    }),
}
