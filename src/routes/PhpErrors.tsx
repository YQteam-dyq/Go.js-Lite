import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Bug, RefreshCw } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/EmptyState'
import { Skeleton } from '@/components/ui/Skeleton'
import { phpErrorsApi, type PhpErrorBucket, type PhpErrorSeverity } from '@/api/phpErrors'
import { useI18n } from '@/hooks/useI18n'

const SEVERITIES: PhpErrorSeverity[] = ['fatal', 'warning', 'notice', 'deprecated']
const WINDOWS = ['1h', '6h', '24h', '7d'] as const

const BAR_COLORS: Record<PhpErrorSeverity, string> = {
  fatal: 'bg-danger',
  warning: 'bg-warning',
  notice: 'bg-info',
  deprecated: 'bg-fg-subtle',
}

export default function PhpErrors() {
  const { t } = useI18n()
  const [since, setSince] = useState<string>('24h')
  const [active, setActive] = useState<PhpErrorSeverity[]>([])

  const { data, isLoading, refetch, isFetching } = useQuery({
    queryKey: ['php-errors', since, active.join(',')],
    queryFn: () => phpErrorsApi.list({ since, severity: active }),
  })

  const toggleSeverity = (s: PhpErrorSeverity) => {
    setActive((prev) => (prev.includes(s) ? prev.filter((x) => x !== s) : [...prev, s]))
  }

  const agg = data?.aggregate
  const bucketMax = Math.max(1, ...(agg?.buckets || []).map((b) => b.fatal + b.warning + b.notice + b.deprecated))

  const segment = (b: PhpErrorBucket, key: PhpErrorSeverity) => {
    const value = b[key]
    if (!value) return null
    return (
      <div
        key={key}
        className={`${BAR_COLORS[key]} rounded-sm`}
        style={{ height: `${(value / bucketMax) * 100}%` }}
        title={`${key}: ${value}`}
      />
    )
  }

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-5xl mx-auto page-enter">
      <div className="stagger-1 flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <Bug size={20} className="text-accent" />
            {t('phpErrors.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">
            {t('phpErrors.subtitle', { sources: data?.sources_count ?? 0 })}
          </p>
        </div>
        <Button variant="ghost" onClick={() => refetch()} loading={isFetching}>
          <RefreshCw size={16} />
          {t('common.refresh')}
        </Button>
      </div>

      <Card className="stagger-2">
        <CardBody className="flex flex-wrap items-center gap-3">
          <div className="flex items-center gap-2">
            <span className="text-xs text-fg-muted">{t('phpErrors.since')}</span>
            <select
              className="h-9 rounded-lg border border-border bg-bg-elevated px-3 text-sm"
              value={since}
              onChange={(e) => setSince(e.target.value)}
            >
              {WINDOWS.map((w) => (
                <option key={w} value={w}>
                  {t(`phpErrors.window_${w}` as never)}
                </option>
              ))}
            </select>
          </div>
          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-xs text-fg-muted">{t('phpErrors.severity')}</span>
            {SEVERITIES.map((s) => (
              <button
                key={s}
                type="button"
                onClick={() => toggleSeverity(s)}
                className={`px-2.5 py-1 rounded-full text-xs border transition-colors ${
                  active.includes(s)
                    ? 'border-accent bg-accent/10 text-accent'
                    : 'border-border text-fg-muted hover:text-fg'
                }`}
              >
                {t(`phpErrors.${s}` as never)}
              </button>
            ))}
          </div>
          <div className="text-xs text-fg-subtle ml-auto">
            {t('phpErrors.total')}: {agg?.total ?? 0}
          </div>
        </CardBody>
      </Card>

      {isLoading ? (
        <Card className="stagger-3">
          <CardBody>
            <Skeleton variant="rectangular" height={160} />
          </CardBody>
        </Card>
      ) : !data || (data.sources_count === 0 && (agg?.total ?? 0) === 0) ? (
        <Card className="stagger-3">
          <CardBody>
            <EmptyState title={t('phpErrors.noData')} description={t('phpErrors.noDataDesc')} />
          </CardBody>
        </Card>
      ) : (
        <>
          <Card className="stagger-3">
            <CardHeader>
              <div className="text-sm font-semibold text-fg">{t('phpErrors.bySeverity')}</div>
            </CardHeader>
            <CardBody className="grid grid-cols-2 md:grid-cols-4 gap-3">
              {SEVERITIES.map((s) => (
                <div key={s} className="px-3 py-2 rounded-lg bg-bg-sunken">
                  <div className="text-[11px] text-fg-subtle">{t(`phpErrors.${s}` as never)}</div>
                  <div className="text-lg font-semibold text-fg">{agg?.by_severity[s] ?? 0}</div>
                </div>
              ))}
            </CardBody>
          </Card>

          {(agg?.buckets?.length || 0) > 0 && (
            <Card className="stagger-4">
              <CardHeader>
                <div className="text-sm font-semibold text-fg">{t('phpErrors.timeline')}</div>
                <div className="flex items-center gap-3 text-[11px] text-fg-subtle">
                  {SEVERITIES.map((s) => (
                    <span key={s} className="flex items-center gap-1">
                      <span className={`inline-block w-2.5 h-2.5 rounded-sm ${BAR_COLORS[s]}`} />
                      {t(`phpErrors.${s}` as never)}
                    </span>
                  ))}
                </div>
              </CardHeader>
              <CardBody>
                <div className="flex items-end gap-1 h-40 overflow-x-auto">
                  {(agg?.buckets || []).map((b) => (
                    <div key={b.hour} className="flex flex-col justify-end gap-0.5 min-w-[14px] flex-1 h-full" title={b.hour}>
                      {SEVERITIES.map((s) => segment(b, s))}
                    </div>
                  ))}
                </div>
              </CardBody>
            </Card>
          )}

          <Card className="stagger-5">
            <CardHeader>
              <div className="text-sm font-semibold text-fg">{t('phpErrors.topCodes')}</div>
            </CardHeader>
            <CardBody>
              {(agg?.top_codes?.length || 0) === 0 ? (
                <div className="text-xs text-fg-subtle">{t('phpErrors.noData')}</div>
              ) : (
                <div className="overflow-x-auto -mx-2">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-left text-fg-subtle border-b border-border">
                        <th className="px-3 py-2 font-medium">{t('phpErrors.code')}</th>
                        <th className="px-3 py-2 font-medium text-right">{t('phpErrors.count')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(agg?.top_codes || []).map((row) => (
                        <tr key={row.code} className="border-b border-border/40">
                          <td className="px-3 py-2 font-mono text-fg">
                            <Badge variant="muted">{row.code}</Badge>
                          </td>
                          <td className="px-3 py-2 text-right text-fg-muted">{row.count}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </CardBody>
          </Card>
        </>
      )}
    </div>
  )
}
