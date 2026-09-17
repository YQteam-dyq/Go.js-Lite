import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Activity, CheckCircle2, Copy, HardDrive, RefreshCw, ShieldAlert, XCircle } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Skeleton, SkeletonCard } from '@/components/ui/Skeleton'
import { StorageMeter } from '@/components/disk/StorageMeter'
import { statusApi } from '@/api/status'
import { useFormat } from '@/lib/format'
import { deriveOverallStatus, usagePercent, type OverallStatus } from '@/lib/usage'
import { useI18n } from '@/hooks/useI18n'

const STATUS_VARIANT: Record<OverallStatus, 'success' | 'warning' | 'danger' | 'muted'> = {
  operational: 'success',
  degraded: 'warning',
  down: 'danger',
  unknown: 'muted',
}

export default function StatusPage() {
  const { t } = useI18n()
  const { formatDate } = useFormat()
  const [copied, setCopied] = useState(false)

  const statusQuery = useQuery({
    queryKey: ['public-status'],
    queryFn: () => statusApi.get(),
    retry: 1,
    staleTime: 30 * 1000,
  })

  const authenticated = statusQuery.data?.authenticated === true

  const healthQuery = useQuery({
    queryKey: ['public-status', 'health'],
    queryFn: () => statusApi.health(),
    enabled: authenticated,
    retry: 0,
  })

  const diskQuery = useQuery({
    queryKey: ['public-status', 'disk'],
    queryFn: () => statusApi.disk(),
    enabled: authenticated,
    retry: 0,
  })

  const diskTotal = diskQuery.data?.diskTotal ?? 0
  const diskFree = diskQuery.data?.diskFree ?? 0
  const diskUsed = diskTotal > 0 ? diskTotal - diskFree : 0
  const percent = diskTotal > 0 ? usagePercent(diskUsed, diskTotal) : undefined

  const unreachable = statusQuery.isError && !statusQuery.data

  const status: OverallStatus = unreachable
    ? 'down'
    : statusQuery.data
      ? authenticated
        ? deriveOverallStatus({
            reachable: true,
            summary: healthQuery.data?.summary ?? null,
            percent,
          })
        : 'operational'
      : 'unknown'

  const handleCopy = useCallback(async () => {
    try {
      await navigator.clipboard.writeText(window.location.href)
      setCopied(true)
      window.setTimeout(() => setCopied(false), 2000)
    } catch {
      setCopied(false)
    }
  }, [])

  const refresh = () => {
    void statusQuery.refetch()
    if (authenticated) {
      void healthQuery.refetch()
      void diskQuery.refetch()
    }
  }

  const summary = healthQuery.data?.summary
  const checkedAt = statusQuery.dataUpdatedAt

  return (
    <div className="min-h-screen bg-bg">
      <div className="mx-auto max-w-3xl p-4 md:p-8 space-y-5">
        <header className="flex items-start justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
              <Activity size={20} className="text-accent" />
              {t('statusPage.title')}
            </h1>
            <p className="text-sm text-fg-muted mt-0.5">{t('statusPage.subtitle')}</p>
          </div>
          <Button variant="secondary" size="sm" onClick={refresh}>
            <RefreshCw size={16} />
            {t('common.refresh')}
          </Button>
        </header>

        {statusQuery.isLoading ? (
          <SkeletonCard />
        ) : (
          <Card>
            <CardBody className="space-y-3">
              <div className="flex flex-wrap items-center gap-3">
                <Badge variant={STATUS_VARIANT[status]}>
                  {t(`statusPage.status_${status}`)}
                </Badge>
                <span className="text-xs text-fg-subtle">
                  {t('statusPage.lastChecked', { time: formatDate(checkedAt) })}
                </span>
              </div>
              {unreachable ? (
                <p className="text-sm text-danger">{t('statusPage.unreachable')}</p>
              ) : (
                <p className="text-sm text-fg-muted">
                  {authenticated ? t('statusPage.authenticatedHint') : t('statusPage.publicHint')}
                </p>
              )}
              <div className="flex flex-wrap items-center gap-2">
                <Button variant="ghost" size="sm" onClick={handleCopy}>
                  <Copy size={14} />
                  {copied ? t('statusPage.linkCopied') : t('statusPage.copyLink')}
                </Button>
                {!authenticated && (
                  <Link
                    to="/login"
                    className="text-sm text-accent hover:underline"
                  >
                    {t('statusPage.signIn')}
                  </Link>
                )}
              </div>
            </CardBody>
          </Card>
        )}

        <Card>
          <CardHeader>
            <div className="text-sm font-semibold text-fg flex items-center gap-2">
              <HardDrive size={16} />
              {t('statusPage.storage')}
            </div>
            <div className="text-xs text-fg-subtle">{t('statusPage.storageSubtitle')}</div>
          </CardHeader>
          <CardBody>
            {!authenticated ? (
              <div className="space-y-1">
                <p className="text-sm font-medium text-fg">{t('statusPage.restrictedTitle')}</p>
                <p className="text-sm text-fg-muted">{t('statusPage.restrictedDescription')}</p>
              </div>
            ) : diskQuery.isLoading ? (
              <div className="space-y-3">
                <Skeleton variant="text" className="w-1/3" />
                <Skeleton variant="rectangular" height={12} />
                <Skeleton variant="text" className="w-2/3" />
              </div>
            ) : diskQuery.isError || diskTotal <= 0 ? (
              <p className="text-sm text-fg-muted">{t('statusPage.storageUnavailable')}</p>
            ) : (
              <>
                <StorageMeter used={diskUsed} total={diskTotal} free={diskFree} />
                <Link
                  to="/disk-analysis"
                  className="inline-block mt-3 text-sm text-accent hover:underline"
                >
                  {t('statusPage.openDiskAnalysis')}
                </Link>
              </>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <div className="text-sm font-semibold text-fg">{t('statusPage.checks')}</div>
            <div className="text-xs text-fg-subtle">{t('statusPage.checksSubtitle')}</div>
          </CardHeader>
          <CardBody>
            {!authenticated ? (
              <p className="text-sm text-fg-muted">{t('statusPage.checksRestricted')}</p>
            ) : healthQuery.isLoading ? (
              <div className="space-y-2">
                <Skeleton variant="text" className="w-1/2" />
                <Skeleton variant="text" className="w-2/3" />
              </div>
            ) : !summary ? (
              <p className="text-sm text-fg-muted">{t('statusPage.checksUnavailable')}</p>
            ) : (
              <ul className="space-y-2 text-sm">
                <li className="flex items-center gap-2 text-fg">
                  <CheckCircle2 size={16} className="text-success shrink-0" />
                  {t('statusPage.checkPassed', { count: summary.pass })}
                </li>
                <li className="flex items-center gap-2 text-fg">
                  <ShieldAlert size={16} className="text-warning shrink-0" />
                  {t('statusPage.checkWarning', { count: summary.warning })}
                </li>
                <li className="flex items-center gap-2 text-fg">
                  <XCircle size={16} className="text-danger shrink-0" />
                  {t('statusPage.checkDanger', { count: summary.danger })}
                </li>
              </ul>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <div className="text-sm font-semibold text-fg">{t('statusPage.versions')}</div>
          </CardHeader>
          <CardBody>
            <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
              <div className="flex justify-between gap-3">
                <dt className="text-fg-muted">{t('statusPage.backendVersion')}</dt>
                <dd className="text-fg tabular-nums">{statusQuery.data?.backendVersion || '—'}</dd>
              </div>
              <div className="flex justify-between gap-3">
                <dt className="text-fg-muted">{t('statusPage.frontendVersion')}</dt>
                <dd className="text-fg tabular-nums">{statusQuery.data?.frontendVersion || '—'}</dd>
              </div>
              <div className="flex justify-between gap-3">
                <dt className="text-fg-muted">{t('statusPage.phpVersion')}</dt>
                <dd className="text-fg tabular-nums">{statusQuery.data?.phpVersion || '—'}</dd>
              </div>
              <div className="flex justify-between gap-3">
                <dt className="text-fg-muted">{t('statusPage.phpSapi')}</dt>
                <dd className="text-fg tabular-nums">{statusQuery.data?.sapi || '—'}</dd>
              </div>
            </dl>
          </CardBody>
        </Card>
      </div>
    </div>
  )
}
