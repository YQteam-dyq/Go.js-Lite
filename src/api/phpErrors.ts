import { apiFetch } from './client'

export type PhpErrorSeverity = 'fatal' | 'warning' | 'notice' | 'deprecated'

export interface PhpErrorBucket {
  hour: string
  fatal: number
  warning: number
  notice: number
  deprecated: number
}

export interface PhpErrorAggregate {
  total: number
  by_severity: Record<PhpErrorSeverity, number>
  by_code: Record<string, number>
  buckets: PhpErrorBucket[]
  top_codes: { code: string; count: number }[]
}

export interface PhpErrorsResponse {
  since: string
  since_ts: number | null
  severity_filter: string[] | null
  sources: string[]
  sources_count: number
  parsed_total: number
  aggregate: PhpErrorAggregate
}

export interface PhpErrorsParams {
  since?: string
  severity?: string[]
}

export const phpErrorsApi = {
  list: (params?: PhpErrorsParams) =>
    apiFetch<PhpErrorsResponse>('/php/errors', {
      params: {
        since: params?.since ?? '24h',
        severity: params?.severity && params.severity.length ? params.severity.join(',') : undefined,
      },
    }),
}
