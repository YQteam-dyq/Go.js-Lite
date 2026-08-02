import { apiFetch } from './client'
import type { TrashItem, TrashListResult } from '@shared/types'

export const trashApi = {
  list() {
    return apiFetch<TrashListResult>('/trash')
  },

  restore(id: string) {
    return apiFetch<{ success: boolean }>('/trash/restore', {
      method: 'POST',
      body: { id },
    })
  },

  purge(id?: string) {
    return apiFetch<{ success: boolean; purged?: number }>('/trash/purge', {
      method: 'POST',
      body: id ? { id } : {},
    })
  },

  getConfig() {
    return apiFetch<{ enabled: boolean }>('/trash/config')
  },

  setConfig(enabled: boolean) {
    return apiFetch<{ enabled: boolean }>('/trash/config', {
      method: 'POST',
      body: { enabled },
    })
  },
}

export type { TrashItem, TrashListResult }
