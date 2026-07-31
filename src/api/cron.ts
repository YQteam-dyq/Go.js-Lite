import { apiFetch } from './client'
import type { CronCapabilities, CronJob, CronListResponse } from '@shared/types'

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
}
