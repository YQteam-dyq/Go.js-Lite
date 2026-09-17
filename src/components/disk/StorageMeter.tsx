import { useId } from 'react'
import { useFormat } from '@/lib/format'
import { usageLevel, usagePercent, type UsageLevel } from '@/lib/usage'
import { useI18n } from '@/hooks/useI18n'

const LEVEL_BAR: Record<UsageLevel, string> = {
  healthy: 'bg-accent',
  warning: 'bg-warning',
  critical: 'bg-danger',
}

export interface StorageMeterProps {
  used: number
  total: number
  free?: number
  label?: string
  compact?: boolean
  className?: string
}

export function StorageMeter({ used, total, free, label, compact, className = '' }: StorageMeterProps) {
  const { t } = useI18n()
  const { formatBytes, formatNumber } = useFormat()
  const descriptionId = useId()

  if (!total || total <= 0) {
    return (
      <p className={`text-sm text-fg-muted ${className}`}>{t('statusPage.storageUnavailable')}</p>
    )
  }

  const percent = usagePercent(used, total)
  const level = usageLevel(percent)
  const heading = label ?? t('diskAnalysis.usageRing')
  const freeBytes = free ?? Math.max(0, total - used)

  const levelHint =
    level === 'critical'
      ? t('statusPage.thresholdCritical')
      : level === 'warning'
        ? t('statusPage.thresholdWarning')
        : t('statusPage.thresholdHealthy')

  return (
    <div className={className}>
      <div className="flex items-baseline justify-between gap-3">
        <span className="text-sm font-medium text-fg">{heading}</span>
        <span className="text-sm font-semibold tabular-nums text-fg" aria-hidden="true">
          {formatNumber(Math.round(percent))}%
        </span>
      </div>

      <div
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={Math.round(percent)}
        aria-valuetext={t('statusPage.usageValue', {
          percent: Math.round(percent),
          used: formatBytes(used),
          total: formatBytes(total),
        })}
        aria-label={heading}
        aria-describedby={descriptionId}
        className={`mt-2 h-2.5 w-full rounded-full bg-bg-sunken overflow-hidden ${compact ? '' : 'sm:h-3'}`}
      >
        <div
          className={`h-full rounded-full transition-[width] duration-500 ease-out ${LEVEL_BAR[level]}`}
          style={{ width: `${percent}%` }}
        />
      </div>

      <div className="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs text-fg-subtle">
        <span>{t('diskAnalysis.usedOfTotal', { used: formatBytes(used), total: formatBytes(total) })}</span>
        <span>
          {t('diskAnalysis.free')}: {formatBytes(freeBytes)}
        </span>
      </div>

      <p id={descriptionId} className="mt-1.5 text-xs text-fg-muted">
        {levelHint}
      </p>
    </div>
  )
}
