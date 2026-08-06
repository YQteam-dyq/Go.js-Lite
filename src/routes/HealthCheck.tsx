import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { HeartPulse, ShieldCheck, Gauge, Boxes, CheckCircle2, AlertTriangle, XCircle } from 'lucide-react'
import { Card, CardBody } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Skeleton, SkeletonCard } from '@/components/ui/Skeleton'
import { healthCheckApi } from '@/api/healthCheck'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'
import type { HealthCheckItem, CompatibilityItem } from '@shared/types'

type TabKey = 'security' | 'performance' | 'compatibility'
type ItemStatus = 'pass' | 'warning' | 'danger'

export default function HealthCheck() {
  const { t } = useI18n()
  const [tab, setTab] = useState<TabKey>('security')

  const { data, isLoading, error } = useQuery({
    queryKey: ['health-check'],
    queryFn: () => healthCheckApi.get(),
  })

  const summary = data?.summary
  const score = summary && summary.total > 0 ? Math.round((summary.pass / summary.total) * 100) : 0

  const tabs: { key: TabKey; label: string; icon: React.ReactNode }[] = [
    { key: 'security', label: t('healthCheck.tabSecurity'), icon: <ShieldCheck size={16} /> },
    { key: 'performance', label: t('healthCheck.tabPerformance'), icon: <Gauge size={16} /> },
    { key: 'compatibility', label: t('healthCheck.tabCompatibility'), icon: <Boxes size={16} /> },
  ]

  return (
    <div className="p-4 md:p-6 space-y-5">
      <div>
        <h1 className="text-xl font-semibold text-fg">{t('healthCheck.title')}</h1>
        <p className="text-sm text-fg-muted mt-0.5">{t('healthCheck.subtitle')}</p>
      </div>

      {isLoading ? (
        <div className="space-y-5">
          <Skeleton variant="rectangular" height={96} className="w-full" />
          <div className="flex gap-2">
            <Skeleton variant="rectangular" height={40} width={120} />
            <Skeleton variant="rectangular" height={40} width={120} />
            <Skeleton variant="rectangular" height={40} width={120} />
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <SkeletonCard />
            <SkeletonCard />
            <SkeletonCard />
            <SkeletonCard />
          </div>
        </div>
      ) : error ? (
        <Card className="p-6 text-center text-danger">
          {t('common.error')}：{resolveErrorText(error) || t('common.unknownError')}
        </Card>
      ) : data ? (
        <>
          
          <Card>
            <CardBody className="flex flex-col sm:flex-row sm:items-center gap-4">
              <div className="flex items-center gap-3 shrink-0">
                <div className="w-12 h-12 rounded-lg bg-accent/10 text-accent flex items-center justify-center">
                  <HeartPulse size={24} />
                </div>
                <div>
                  <div className="text-xs text-fg-subtle">{t('healthCheck.overallScore')}</div>
                  <div className="text-2xl font-bold text-fg">
                    {summary?.pass ?? 0}
                    <span className="text-base text-fg-muted font-normal">/{summary?.total ?? 0}</span>
                    <span className="ml-2 text-sm text-fg-muted font-normal">({score}%)</span>
                  </div>
                </div>
              </div>
              <div className="flex-1 min-w-0">
                <div className="h-2.5 rounded-full bg-bg-sunken overflow-hidden">
                  <div
                    className="h-full transition-all duration-500"
                    style={{
                      width: `$$score}%`,
                      background:
                        score >= 80
                          ? 'hsl(var(--success))'
                          : score >= 50
                            ? 'hsl(var(--warning))'
                            : 'hsl(var(--danger))',
                    }}
                  />
                </div>
                <div className="flex flex-wrap gap-3 mt-3 text-xs">
                  <span className="inline-flex items-center gap-1 text-success">
                    <CheckCircle2 size={14} />
                    {t('healthCheck.statusPass')} {summary?.pass ?? 0}
                  </span>
                  <span className="inline-flex items-center gap-1 text-warning">
                    <AlertTriangle size={14} />
                    {t('healthCheck.statusWarning')} {summary?.warning ?? 0}
                  </span>
                  <span className="inline-flex items-center gap-1 text-danger">
                    <XCircle size={14} />
                    {t('healthCheck.statusDanger')} {summary?.danger ?? 0}
                  </span>
                </div>
              </div>
            </CardBody>
          </Card>

          
          <div className="flex gap-1 p-1 bg-bg-sunken rounded-lg overflow-x-auto">
            {tabs.map((tb) => (
              <button
                key={tb.key}
                type="button"
                onClick={() => setTab(tb.key)}
                className={`flex-1 min-w-[100px] flex items-center justify-center gap-2 h-10 px-3 rounded-md text-sm font-medium transition-colors duration-150 focus-ring $$
                  tab === tb.key
                    ? 'bg-bg-elevated text-fg shadow-sm'
                    : 'text-fg-muted hover:text-fg'
                }`}
              >
                {tb.icon}
                <span>{tb.label}</span>
              </button>
            ))}
          </div>

          
          {tab === 'security' && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {data.security.map((item) => (
                <CheckCard key={item.name} item={item} />
              ))}
            </div>
          )}
          {tab === 'performance' && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {data.performance.map((item) => (
                <CheckCard key={item.name} item={item} />
              ))}
            </div>
          )}
          {tab === 'compatibility' && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {data.compatibility.map((item) => (
                <CompatibilityCard key={item.name} item={item} />
              ))}
            </div>
          )}
        </>
      ) : null}
    </div>
  )
}

function StatusBadge({ status }: { status: ItemStatus }) {
  const { t } = useI18n()
  if (status === 'pass') {
    return (
      <Badge variant="success">
        <CheckCircle2 size={12} />
        {t('healthCheck.statusPass')}
      </Badge>
    )
  }
  if (status === 'warning') {
    return (
      <span className="badge bg-warning/10 text-warning">
        <AlertTriangle size={12} />
        {t('healthCheck.statusWarning')}
      </span>
    )
  }
  return (
    <Badge variant="danger">
      <XCircle size={12} />
      {t('healthCheck.statusDanger')}
    </Badge>
  )
}

function CheckCard({ item }: { item: HealthCheckItem }) {
  const { t } = useI18n()
  return (
    <Card>
      <CardBody className="space-y-3">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="font-mono text-sm font-medium text-fg break-all">{item.name}</div>
            <p className="text-xs text-fg-muted mt-1 leading-relaxed">{item.description}</p>
          </div>
          <div className="shrink-0">
            <StatusBadge status={item.status} />
          </div>
        </div>
        <div className="grid grid-cols-2 gap-2 pt-2 border-t border-border/60">
          <div>
            <div className="text-[10px] uppercase tracking-wide text-fg-subtle">
              {t('healthCheck.currentValue')}
            </div>
            <div className="font-mono text-xs text-fg mt-0.5 break-all">{item.currentValue || '—'}</div>
          </div>
          <div>
            <div className="text-[10px] uppercase tracking-wide text-fg-subtle">
              {t('healthCheck.recommendedValue')}
            </div>
            <div className="font-mono text-xs text-fg mt-0.5 break-all">{item.recommendedValue}</div>
          </div>
        </div>
      </CardBody>
    </Card>
  )
}

function CompatibilityCard({ item }: { item: CompatibilityItem }) {
  const { t } = useI18n()
  return (
    <Card>
      <CardBody className="space-y-3">
        <div className="flex items-center justify-between gap-3">
          <div className="text-sm font-semibold text-fg">{item.name}</div>
          {item.pass ? (
            <Badge variant="success">
              <CheckCircle2 size={12} />
              {t('healthCheck.compatSupported')}
            </Badge>
          ) : (
            <Badge variant="danger">
              <XCircle size={12} />
              {t('healthCheck.compatNotSupported')}
            </Badge>
          )}
        </div>

        <div>
          <div className="text-[10px] uppercase tracking-wide text-fg-subtle mb-1.5">
            {t('healthCheck.requirements')}
          </div>
          <div className="flex flex-wrap gap-1.5">
            {item.requirements.map((req, i) => {
              const isMissingExt = item.missing.some((m) => req.endsWith(': ' + m))
              return (
                <span
                  key={i}
                  className={`badge font-mono $$
                    isMissingExt ? 'bg-danger/10 text-danger' : 'bg-success/10 text-success'
                  }`}
                >
                  {req}
                </span>
              )
            })}
          </div>
        </div>

        {item.missing.length > 0 && (
          <div className="pt-2 border-t border-border/60">
            <div className="text-[10px] uppercase tracking-wide text-danger mb-1">
              {t('healthCheck.missingItems')}
            </div>
            <ul className="space-y-1">
              {item.missing.map((m, i) => (
                <li key={i} className="text-xs text-danger flex items-start gap-1.5">
                  <XCircle size={12} className="mt-0.5 shrink-0" />
                  <span>
                    <span className="font-mono font-medium">{m}</span>
                    <span className="text-fg-muted ml-1">
                      {m === 'PHP 版本不满足'
                        ? t('healthCheck.suggestionPhp')
                        : t('healthCheck.suggestionExt', { name: m })}
                    </span>
                  </span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </CardBody>
    </Card>
  )
}
