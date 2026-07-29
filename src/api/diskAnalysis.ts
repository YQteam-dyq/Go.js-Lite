import { apiFetch } from './client'
import type { DiskAnalysisData, LargeFilesData } from '@shared/types'

export const diskAnalysisApi = {
  get(path?: string) {
    return apiFetch<DiskAnalysisData>('/disk-analysis', {
      params: path ? { path } : undefined,
    })
  },
  getLargeFiles(path?: string, threshold?: number) {
    return apiFetch<LargeFilesData>('/disk-analysis/large-files', {
      params: { path, threshold },
    })
  },
}
