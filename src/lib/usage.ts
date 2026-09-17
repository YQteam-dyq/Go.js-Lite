export type UsageLevel = 'healthy' | 'warning' | 'critical'

export type OverallStatus = 'operational' | 'degraded' | 'down' | 'unknown'

export interface HealthSummary {
  pass: number
  warning: number
  danger: number
  total: number
}

export function clampPercent(value: number): number {
  if (!Number.isFinite(value)) return 0
  if (value < 0) return 0
  if (value > 100) return 100
  return value
}

export function usagePercent(used: number, total: number): number {
  if (!Number.isFinite(used) || !Number.isFinite(total) || total <= 0) return 0
  return clampPercent((used / total) * 100)
}

export function usageLevel(percent: number, warningAt = 75, criticalAt = 90): UsageLevel {
  const value = clampPercent(percent)
  if (value >= criticalAt) return 'critical'
  if (value >= warningAt) return 'warning'
  return 'healthy'
}

export function deriveOverallStatus(input: {
  reachable: boolean
  summary?: HealthSummary | null
  percent?: number
}): OverallStatus {
  if (!input.reachable) return 'down'
  if (!input.summary && input.percent === undefined) return 'unknown'
  if (input.summary && (input.summary.danger > 0 || input.summary.warning > 0)) return 'degraded'
  if (input.percent !== undefined && usageLevel(input.percent) !== 'healthy') return 'degraded'
  return 'operational'
}
