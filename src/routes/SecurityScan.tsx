import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Shield, ShieldCheck, ExternalLink, RefreshCw, AlertTriangle, AlertOctagon, Info } from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { Skeleton } from '@/components/ui/Skeleton'
import { secscanApi } from '@/api/secscan'
import { useI18n } from '@/hooks/useI18n'
import { useUiStore } from '@/stores/uiStore'
import { useFormat } from '@/lib/format'
import type { SecurityVulnItem, SecurityScanFrontendResult, SecurityScanBackendResult } from '@shared/types'

type ScanType = 'frontend' | 'backend'

const SEVERITY_ORDER: Array<SecurityVulnItem['severity']> = ['critical', 'high', 'moderate', 'low', 'info']

const SEVERITY_BADGE: Record<SecurityVulnItem['severity'], 'danger' | 'warning' | 'muted' | 'accent'> = {
  critical: 'danger',
  high: 'danger',
  moderate: 'warning',
  low: 'muted',
  info: 'accent',
}

interface SeverityCounts {
  critical: number
  high: number
  moderate: number
  low: number
  info: number
  total: number
}

function countSeverities(vulns: SecurityVulnItem[]): SeverityCounts {
  const counts: SeverityCounts = { critical: 0, high: 0, moderate: 0, low: 0, info: 0, total: vulns.length }
  for (const v of vulns) {
    if (v.severity in counts) {
      counts[v.severity]++
    }
  }
  return counts
}

function sortedVulns(vulns: SecurityVulnItem[]): SecurityVulnItem[] {
  const rank = Object.fromEntries(SEVERITY_ORDER.map((s, i) => [s, i])) as Record<string, number>
  return [...vulns].sort((a, b) => {
    const ra = rank[a.severity] ?? 99
    const rb = rank[b.severity] ?? 99
    if (ra !== rb) return ra - rb
    return a.package.localeCompare(b.package)
  })
}

function ScanAvailabilityBanner({
  result,
  type,
}: {
  result: SecurityScanFrontendResult | SecurityScanBackendResult
  type: ScanType
}) {
  const { t } = useI18n()

  if (result.available === false) {
    return (
      <div className="flex items-start gap-2.5 rounded-lg border border-warning/30 bg-warning/10 p-3 mb-4">
        <AlertTriangle size={16} className="shrink-0 mt-0.5 text-warning" />
        <div className="text-xs text-fg leading-relaxed">
          {t(result.reason_key ?? 'secscan.npmUnavailable')}
        </div>
      </div>
    )
  }

  if (type === 'backend' && (result as SecurityScanBackendResult).heuristicOnly) {
    return (
      <div className="flex items-start gap-2.5 rounded-lg border border-border bg-bg-sunken/60 p-3 mb-4">
        <Info size={16} className="shrink-0 mt-0.5 text-fg-subtle" />
        <div className="text-xs text-fg-muted leading-relaxed">
          {t((result as SecurityScanBackendResult).notice_key ?? 'secscan.heuristicOnlyBanner')}
        </div>
      </div>
    )
  }

  return null
}

function SeverityChipsRow({ counts }: { counts: SeverityCounts }) {
  const { t } = useI18n()
  return (
    <div className="flex flex-wrap items-center gap-2 mb-4">
      <span className="text-xs font-medium text-fg-muted mr-1">{t('secscan.vulnsBySeverity')}:</span>
      <Badge variant={SEVERITY_BADGE.critical}>
        <AlertOctagon size={12} />
        {t('secscan.criticalCount')} {counts.critical}
      </Badge>
      <Badge variant={SEVERITY_BADGE.high}>
        <AlertTriangle size={12} />
        {t('secscan.highCount')} {counts.high}
      </Badge>
      <Badge variant={SEVERITY_BADGE.moderate}>{t('secscan.moderateCount')} {counts.moderate}</Badge>
      <Badge variant={SEVERITY_BADGE.low}>{t('secscan.lowCount')} {counts.low}</Badge>
      <span className="text-xs text-fg-subtle ml-auto font-mono">
        {t('secscan.totalCount')}: <span className="text-fg font-medium">{counts.total}</span>
      </span>
    </div>
  )
}

function VulnTable({ vulns }: { vulns: SecurityVulnItem[] }) {
  const { t } = useI18n()
  const items = sortedVulns(vulns)

  if (items.length === 0) {
    return (
      <EmptyState
        icon={
          <div className="w-20 h-20 rounded-2xl bg-success/10 text-success flex items-center justify-center mx-auto">
            <ShieldCheck size={36} strokeWidth={1.8} />
          </div>
        }
        title={t('secscan.noVulnsFound')}
      />
    )
  }

  return (
    <>
      <div className="hidden md:block overflow-x-auto rounded-lg border border-border/60">
        <table className="w-full text-xs">
          <thead>
            <tr className="bg-bg-sunken/70 text-fg-muted">
              <th className="text-left font-medium px-3 py-2.5">{t('secscan.colPackage')}</th>
              <th className="text-left font-medium px-3 py-2.5">{t('secscan.colInstalled')}</th>
              <th className="text-left font-medium px-3 py-2.5">{t('secscan.colFixedIn')}</th>
              <th className="text-left font-medium px-3 py-2.5">{t('secscan.colSeverity')}</th>
              <th className="text-left font-medium px-3 py-2.5">{t('secscan.colTitle')}</th>
              <th className="text-left font-medium px-3 py-2.5 w-24">{t('secscan.colAdvisory')}</th>
            </tr>
          </thead>
          <tbody>
            {items.map((v, i) => (
              <tr
                key={`${v.package}-${v.installed_version}-${i}`}
                className="border-t border-border/50 hover:bg-bg-sunken/40 transition-colors"
              >
                <td className="px-3 py-2.5 font-mono text-fg break-all">{v.package}</td>
                <td className="px-3 py-2.5 font-mono text-fg-subtle">{v.installed_version}</td>
                <td className="px-3 py-2.5 font-mono text-success/90">
                  {v.fixed_version ?? <span className="text-fg-subtle/70">—</span>}
                </td>
                <td className="px-3 py-2.5">
                  <Badge variant={v.severityBadgeVariant as 'danger' | 'warning' | 'muted' | 'accent' | 'success'}>
                    {t(`secscan.severity${v.severity.charAt(0).toUpperCase() + v.severity.slice(1)}` as any) ?? v.severity}
                  </Badge>
                </td>
                <td className="px-3 py-2.5 text-fg max-w-xs truncate" title={v.title}>
                  {v.title || <span className="text-fg-subtle/70">—</span>}
                </td>
                <td className="px-3 py-2.5">
                  {v.url ? (
                    <a
                      href={v.url}
                      target="_blank"
                      rel="noreferrer noopener"
                      className="inline-flex items-center gap-1 text-accent hover:text-accent-hover transition-colors"
                    >
                      {t('secscan.colAdvisory')}
                      <ExternalLink size={11} />
                    </a>
                  ) : (
                    <span className="text-fg-subtle/70">—</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="md:hidden space-y-2.5">
        {items.map((v, i) => (
          <div
            key={`${v.package}-${v.installed_version}-${i}-m`}
            className="rounded-lg border border-border/60 p-3 bg-bg-sunken/20"
          >
            <div className="flex items-start justify-between gap-2 mb-2">
              <div className="min-w-0 flex-1">
                <div className="font-mono text-sm font-medium text-fg break-all">{v.package}</div>
                <div className="text-[11px] font-mono text-fg-subtle mt-0.5">
                  {t('secscan.colInstalled')}: {v.installed_version}
                  {v.fixed_version && (
                    <span className="ml-2 text-success/90">
                      → {t('secscan.colFixedIn')}: {v.fixed_version}
                    </span>
                  )}
                </div>
              </div>
              <Badge
                variant={v.severityBadgeVariant as 'danger' | 'warning' | 'muted' | 'accent' | 'success'}
                className="shrink-0"
              >
                {t(`secscan.severity${v.severity.charAt(0).toUpperCase() + v.severity.slice(1)}` as any) ?? v.severity}
              </Badge>
            </div>
            {v.title && (
              <div className="text-xs text-fg-muted leading-relaxed mb-2">{v.title}</div>
            )}
            {v.url && (
              <a
                href={v.url}
                target="_blank"
                rel="noreferrer noopener"
                className="inline-flex items-center gap-1 text-[11px] text-accent hover:text-accent-hover transition-colors"
              >
                {t('secscan.colAdvisory')}
                <ExternalLink size={10} />
              </a>
            )}
          </div>
        ))}
      </div>
    </>
  )
}

function ScanCardSkeleton() {
  return (
    <Card>
      <CardHeader>
        <div className="flex items-center justify-between gap-3">
          <Skeleton variant="text" width={140} height={18} />
          <div className="flex items-center gap-2">
            <Skeleton variant="text" width={80} height={12} />
            <Skeleton variant="rectangular" width={90} height={32} className="rounded-md" />
          </div>
        </div>
      </CardHeader>
      <CardBody>
        <div className="space-y-2 mb-5">
          <Skeleton variant="text" width="100%" height={20} />
          <div className="flex gap-2">
            <Skeleton variant="rectangular" width={70} height={22} className="rounded-full" />
            <Skeleton variant="rectangular" width={70} height={22} className="rounded-full" />
            <Skeleton variant="rectangular" width={70} height={22} className="rounded-full" />
            <Skeleton variant="rectangular" width={70} height={22} className="rounded-full" />
          </div>
        </div>
        <div className="space-y-1.5">
          <Skeleton variant="text" width="100%" height={36} />
          <Skeleton variant="text" width="100%" height={36} />
          <Skeleton variant="text" width="100%" height={36} />
          <Skeleton variant="text" width="100%" height={36} />
        </div>
      </CardBody>
    </Card>
  )
}

interface ScanCardProps {
  type: ScanType
  result: SecurityScanFrontendResult | SecurityScanBackendResult | undefined
  isLoading: boolean
  error: unknown
  counts: SeverityCounts
  onRescan: () => void
  isRescanning: boolean
}

function ScanCard({
  type,
  result,
  isLoading,
  error,
  counts,
  onRescan,
  isRescanning,
}: ScanCardProps) {
  const { t } = useI18n()
  const { formatRelativeTime } = useFormat()

  const titleKey = type === 'frontend' ? 'secscan.frontendCard' : 'secscan.backendCard'

  if (isLoading && !result) return <ScanCardSkeleton />

  if (error && !result) {
    return (
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between gap-3">
            <div className="text-base font-semibold text-fg">{t(titleKey)}</div>
            <Button variant="primary" size="sm" onClick={onRescan} loading={isRescanning}>
              <RefreshCw size={14} />
              {t('secscan.rescan')}
            </Button>
          </div>
        </CardHeader>
        <CardBody>
          <div className="rounded-lg border border-danger/30 bg-danger/10 p-3 flex items-start gap-2.5">
            <AlertOctagon size={16} className="shrink-0 mt-0.5 text-danger" />
            <div className="text-xs text-fg leading-relaxed">
              {t('secscan.scanFailed')} —{' '}
              {error instanceof Error ? error.message : t('common.unknownError')}
            </div>
          </div>
        </CardBody>
      </Card>
    )
  }

  const scannedAt = result?.scanned_at

  return (
    <Card>
      <CardHeader>
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div className="text-base font-semibold text-fg">{t(titleKey)}</div>
          <div className="flex items-center gap-2.5 sm:justify-end">
            {scannedAt && (
              <div className="text-[11px] text-fg-subtle font-mono whitespace-nowrap">
                {t('secscan.lastScanned')}: {formatRelativeTime(scannedAt)}
              </div>
            )}
            <Button variant="primary" size="sm" onClick={onRescan} loading={isRescanning}>
              <RefreshCw size={14} />
              {t('secscan.rescan')}
            </Button>
          </div>
        </div>
      </CardHeader>
      <CardBody>
        {result && <ScanAvailabilityBanner result={result} type={type} />}
        <SeverityChipsRow counts={counts} />
        <VulnTable vulns={result?.vulns ?? []} />
      </CardBody>
    </Card>
  )
}

export default function SecurityScan() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const addToast = useUiStore((s) => s.addToast)

  const frontendQuery = useQuery({
    queryKey: ['secscan', 'frontend'],
    queryFn: () => secscanApi.frontend(false),
    retry: 1,
    staleTime: 30 * 60 * 1000,
  })

  const backendQuery = useQuery({
    queryKey: ['secscan', 'backend'],
    queryFn: () => secscanApi.backend(false),
    retry: 1,
    staleTime: 30 * 60 * 1000,
  })

  const rescanFrontendMut = useMutation({
    mutationFn: () => secscanApi.frontend(true),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['secscan', 'frontend'] })
      addToast({ type: 'success', title: t('secscan.frontendCard'), description: t('secscan.scanning') })
    },
    onError: (err) => {
      addToast({
        type: 'error',
        title: t('secscan.frontendCard'),
        description: err instanceof Error ? err.message : t('secscan.scanFailed'),
      })
    },
  })

  const rescanBackendMut = useMutation({
    mutationFn: () => secscanApi.backend(true),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['secscan', 'backend'] })
      addToast({ type: 'success', title: t('secscan.backendCard'), description: t('secscan.scanning') })
    },
    onError: (err) => {
      addToast({
        type: 'error',
        title: t('secscan.backendCard'),
        description: err instanceof Error ? err.message : t('secscan.scanFailed'),
      })
    },
  })

  const frontendResult = frontendQuery.data as SecurityScanFrontendResult | undefined
  const backendResult = backendQuery.data as SecurityScanBackendResult | undefined

  const frontendCounts = countSeverities(frontendResult?.vulns ?? [])
  const backendCounts = countSeverities(backendResult?.vulns ?? [])

  const bothFailed =
    frontendQuery.isError && backendQuery.isError && !frontendResult && !backendResult

  return (
    <div className="p-4 md:p-6 space-y-5">
      <div className="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <Shield size={20} className="text-accent" />
            {t('nav.securityScan')}
          </h1>
          <p className="text-xs text-fg-muted mt-1">
            <Shield size={14} className="inline mr-1 mb-0.5 opacity-70" />
            {t('security.policyLinkIntro')}
            <a
              href="/SECURITY.md"
              target="_blank"
              rel="noreferrer noopener"
              className="underline text-accent hover:text-accent-hover ml-1"
            >
              {t('security.policyLinkLabel')}
            </a>
          </p>
        </div>
      </div>

      {bothFailed && (
        <Card className="border-danger/40">
          <CardBody>
            <div className="flex items-start gap-2.5">
              <AlertOctagon size={18} className="shrink-0 mt-0.5 text-danger" />
              <div className="text-sm text-danger leading-relaxed">
                {t('secscan.scanFailed')} (frontend + backend) —{' '}
                {frontendQuery.error instanceof Error
                  ? frontendQuery.error.message
                  : backendQuery.error instanceof Error
                    ? backendQuery.error.message
                    : t('common.unknownError')}
              </div>
            </div>
          </CardBody>
        </Card>
      )}

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <ScanCard
          type="frontend"
          result={frontendResult}
          isLoading={frontendQuery.isLoading}
          error={frontendQuery.error}
          counts={frontendCounts}
          onRescan={() => rescanFrontendMut.mutate()}
          isRescanning={rescanFrontendMut.isPending}
        />
        <ScanCard
          type="backend"
          result={backendResult}
          isLoading={backendQuery.isLoading}
          error={backendQuery.error}
          counts={backendCounts}
          onRescan={() => rescanBackendMut.mutate()}
          isRescanning={rescanBackendMut.isPending}
        />
      </div>
    </div>
  )
}
