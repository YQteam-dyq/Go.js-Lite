import { apiFetch } from './client'
import type { DeployAppInfo, DeployRunResult } from '@shared/types'

export interface DeployRunInput {
  app_id: string
  target_dir: string
  db_host?: string
  db_name?: string
  db_user?: string
  db_pass?: string
  db_prefix?: string
  overwrite?: boolean
}

export const deployApi = {
  apps() {
    return apiFetch<{ apps: DeployAppInfo[] }>('/deploy/apps').then((res) => res.apps)
  },

  run(body: DeployRunInput) {
    return apiFetch<DeployRunResult>('/deploy/run', {
      method: 'POST',
      body,
    })
  },
}
