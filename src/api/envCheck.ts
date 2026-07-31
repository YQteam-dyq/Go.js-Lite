import { apiFetch } from './client'
import type { EnvCheckData } from '@shared/types'

export const envCheckApi = {
  get: () => apiFetch<EnvCheckData>('/env-check'),
}
