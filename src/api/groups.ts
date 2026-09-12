import { apiFetch } from './client'

export interface GroupRecord {
  id: string
  name: string
  path_allowlist: string[]
  member_ids: string[]
  created_at: number
}

export interface GroupListResponse {
  groups: GroupRecord[]
  total: number
}

export interface CreateGroupInput {
  name: string
  path_allowlist?: string[]
  member_ids?: string[]
}

export interface UpdateGroupInput {
  name?: string
  path_allowlist?: string[]
  member_ids?: string[]
}

export const groupsApi = {
  list: () => apiFetch<GroupListResponse>('/groups'),
  create: (input: CreateGroupInput) =>
    apiFetch<GroupRecord>('/groups', { method: 'POST', body: input }),
  update: (id: string, input: UpdateGroupInput) =>
    apiFetch<GroupRecord>(`/groups/${id}`, { method: 'PATCH', body: input }),
  remove: (id: string) =>
    apiFetch<{ success: boolean }>(`/groups/${id}`, { method: 'DELETE' }),
  setMembers: (id: string, add: string[], remove: string[]) =>
    apiFetch<GroupRecord>(`/groups/${id}/members`, {
      method: 'POST',
      body: { add, remove },
    }),
}
