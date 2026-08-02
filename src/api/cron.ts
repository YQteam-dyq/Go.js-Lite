import { apiFetch } from './client'
import type { CronCapabilities, CronJob, CronListResponse } from '@shared/types'

export interface InternalCronTickStats {
  processed_schedules: number
  processed_runs: number
  drained_outbox: number
  tick_at: number
}

export interface InternalCronRegenerateTokenResponse {
  token: string
}

export const cronApi = {
  capabilities() {
    return apiFetch<CronCapabilities>('/cron/capabilities')
  },

  list() {
    return apiFetch<CronListResponse>('/cron/list').then((res) => res.jobs)
  },

  save(jobs: CronJob[]) {
    return apiFetch<{ ok: boolean }>('/cron/save', {
      method: 'POST',
      body: { jobs },
    })
  },

  internalCronTick() {
    return apiFetch<InternalCronTickStats>('/internal/cron/tick', {
      method: 'POST',
    })
  },

  regenerateInternalCronToken() {
    return apiFetch<InternalCronRegenerateTokenResponse>('/internal/cron/regenerate-token', {
      method: 'POST',
    })
  },
}
