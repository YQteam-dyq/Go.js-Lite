import { apiFetch } from './client'
import type { ApiToken, ApiTokenCreateInput, ApiTokenCreateResult } from '@shared/types'

export const apiTokensApi = {
  list() {
    return apiFetch<{ tokens: ApiToken[] }>('/api-tokens').then((res) => res.tokens)
  },

  create(input: ApiTokenCreateInput) {
    return apiFetch<ApiTokenCreateResult>('/api-tokens', {
      method: 'POST',
      body: { name: input.name, scopes: input.scopes },
    })
  },

  revoke(id: string) {
    return apiFetch<{ success: boolean }>(`/api-tokens/$$encodeURIComponent(id)}`, {
      method: 'DELETE',
    })
  },
}
