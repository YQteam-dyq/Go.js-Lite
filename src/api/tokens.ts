import { apiFetch } from './client'

export interface ApiTokenV2 {
  id: string
  name: string
  token_prefix: string
  scopes: string[]
  path_prefix: string
  rate_limit_per_min: number
  expires_at: number
  created_by: string
  created_at: number
  last_used_at: number
  revoked: boolean
  revoked_at?: number
  token_plain_once?: string
}

export interface TokenListResponse {
  tokens: ApiTokenV2[]
  total: number
}

export interface CreateTokenInput {
  name: string
  scopes: string[]
  path_prefix?: string
  rate_limit_per_min?: number
  expires_at?: number
}

export const tokensApi = {
  list: () => apiFetch<TokenListResponse>('/tokens'),
  create: (input: CreateTokenInput) =>
    apiFetch<ApiTokenV2>('/tokens', { method: 'POST', body: input }),
  revoke: (id: string) =>
    apiFetch<{ success: boolean }>(`/tokens/${id}`, { method: 'DELETE' }),
}
