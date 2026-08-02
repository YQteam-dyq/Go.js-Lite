import { useCallback, useEffect, useMemo, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Shield,
  ShieldCheck,
  Plus,
  Trash2,
  RefreshCw,
  CheckCircle2,
  AlertTriangle,
  XCircle,
  Lock,
  Unlock,
  Globe,
  Clock,
  Download,
  Mail,
  Server,
  FileCheck2,
  ExternalLink,
  CheckSquare,
  Square,
  Settings,
} from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Skeleton } from '@/components/ui/Skeleton'
import { Spinner } from '@/components/ui/Spinner'
import { EmptyError } from '@/components/ui/EmptyState'
import { Confirm, Modal } from '@/components/ui/Modal'
import { sslApi } from '@/api/ssl'
import { toast } from '@/components/ui/Toast'
import { useI18n } from '@/hooks/useI18n'
import { useIsMobile } from '@/hooks/useMediaQuery'
import { truncate } from '@/lib/format'
import type { SSLInfo, AcmeCapabilities, AcmeCertificateRecord, AcmeCertStatus } from '@shared/types'

export default function SSL() {
  const { t } = useI18n()
  const [activeTab, setActiveTab] = useState<'checker' | 'certs'>('checker')

  return (
    <div className="p-4 md:p-6 space-y-5 page-enter">
      <div className="stagger-1">
        <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
          <Shield size={22} className="text-accent" />
          {t('ssl.title')}
        </h1>
        <p className="text-sm text-fg-muted mt-0.5">{t('ssl.subtitle')}</p>
      </div>

      <div className="flex gap-1 p-1 bg-bg-sunken rounded-xl w-fit stagger-2">
        <button
          onClick={() => setActiveTab('checker')}
          className={`px-4 py-2 rounded-lg text-sm font-medium transition-all ${
            activeTab === 'checker'
              ? 'bg-bg-elevated text-fg shadow-sm'
              : 'text-fg-muted hover:text-fg'
          }`}
        >
          {t('ssl.title')}
        </button>
        <button
          onClick={() => setActiveTab('certs')}
          className={`px-4 py-2 rounded-lg text-sm font-medium transition-all ${
            activeTab === 'certs'
              ? 'bg-bg-elevated text-fg shadow-sm'
              : 'text-fg-muted hover:text-fg'
          }`}
        >
          {t('ssl.acme.tabCertificates')}
        </button>
      </div>

      {activeTab === 'checker' ? <SSLChecker /> : <SSLCertificates />}
    </div>
  )
}

function SSLChecker() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const [newDomain, setNewDomain] = useState('')
  const [results, setResults] = useState<Record<string, SSLInfo>>({})
  const [checking, setChecking] = useState<Record<string, boolean>>({})
  const [checkingAll, setCheckingAll] = useState(false)
  const [deleteDomain, setDeleteDomain] = useState<string | null>(null)

  const { data: domains, isLoading, error, refetch } = useQuery({
    queryKey: ['ssl-domains'],
    queryFn: () => sslApi.list(),
  })

  const runCheck = useCallback(async (domain: string) => {
    setChecking((prev) => ({ ...prev, [domain]: true }))
    try {
      const info = await sslApi.check(domain)
      setResults((prev) => ({ ...prev, [domain]: info }))
    } catch (err) {
      setResults((prev) => ({
        ...prev,
        [domain]: {
          domain,
          enabled: false,
          status: 'failed',
          error: 'request_failed',
          error_key: 'connect_failed',
          error_params: { detail: err instanceof Error ? err.message : 'request_failed' },
          message: err instanceof Error ? err.message : t('common.unknownError'),
        },
      }))
    } finally {
      setChecking((prev) => {
        const next = { ...prev }
        delete next[domain]
        return next
      })
    }
  }, [t])

  const checkAll = useCallback(async (list: string[]) => {
    if (list.length === 0) return
    setCheckingAll(true)
    await Promise.allSettled(list.map((d) => runCheck(d)))
    setCheckingAll(false)
  }, [runCheck])

  useEffect(() => {
    if (domains && domains.length > 0) {
      checkAll(domains)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [domains])

  const addMutation = useMutation({
    mutationFn: (domain: string) => sslApi.addDomain(domain),
    onSuccess: async (_, domain) => {
      toast({ type: 'success', title: t('ssl.addSuccess') })
      await queryClient.invalidateQueries({ queryKey: ['ssl-domains'] })
      runCheck(domain)
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('ssl.addFailed'), description: err.message })
    },
  })

  const removeMutation = useMutation({
    mutationFn: (domain: string) => sslApi.removeDomain(domain),
    onSuccess: (_, domain) => {
      toast({ type: 'success', title: t('ssl.removeSuccess') })
      setResults((prev) => {
        const next = { ...prev }
        delete next[domain]
        return next
      })
      queryClient.invalidateQueries({ queryKey: ['ssl-domains'] })
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('ssl.removeFailed'), description: err.message })
    },
  })

  const handleAdd = async () => {
    const domain = newDomain.trim()
    if (!domain) {
      toast({ type: 'warning', title: t('ssl.domainRequired') })
      return
    }
    if (!/^[a-zA-Z0-9][a-zA-Z0-9\-\.]*(\.[a-zA-Z]{2,})?$/.test(domain)) {
      toast({ type: 'warning', title: t('ssl.domainInvalid') })
      return
    }
    if (domains && domains.includes(domain)) {
      toast({ type: 'warning', title: t('ssl.domainExists') })
      return
    }
    setNewDomain('')
    await addMutation.mutateAsync(domain)
  }

  const handleConfirmDelete = async () => {
    if (deleteDomain === null) return
    await removeMutation.mutateAsync(deleteDomain)
    setDeleteDomain(null)
  }

  if (isLoading) {
    return (
      <div className="space-y-5">
        <Skeleton variant="rectangular" height={64} className="w-full" />
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Skeleton variant="rectangular" height={140} className="w-full" />
          <Skeleton variant="rectangular" height={140} className="w-full" />
        </div>
      </div>
    )
  }

  if (error) {
    return <EmptyError error={error instanceof Error ? error.message : t('common.unknownError')} onRetry={() => refetch()} />
  }

  const list = domains ?? []

  return (
    <div className="space-y-5">
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <Button
          variant="secondary"
          size="sm"
          onClick={() => checkAll(list)}
          disabled={checkingAll || list.length === 0}
          loading={checkingAll}
        >
          <RefreshCw size={16} />
          {t('ssl.checkAll')}
        </Button>
      </div>

      <Card>
        <CardBody className="flex flex-col sm:flex-row gap-3 sm:items-center">
          <div className="flex-1">
            <Input
              value={newDomain}
              onChange={(e) => setNewDomain(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') handleAdd()
              }}
              placeholder={t('ssl.domainPlaceholder')}
              icon={<Globe size={16} />}
              disabled={addMutation.isPending}
            />
          </div>
          <Button
            onClick={handleAdd}
            loading={addMutation.isPending}
            disabled={addMutation.isPending}
            className="sm:shrink-0"
          >
            <Plus size={16} />
            {t('ssl.addDomain')}
          </Button>
        </CardBody>
      </Card>

      {list.length === 0 ? (
        <Card>
          <CardBody className="py-12 text-center">
            <div className="w-16 h-16 mx-auto mb-3 rounded-2xl bg-bg-sunken flex items-center justify-center text-fg-subtle">
              <Shield size={28} />
            </div>
            <p className="text-sm text-fg-muted">{t('ssl.empty')}</p>
            <p className="text-xs text-fg-subtle mt-1">{t('ssl.emptyHint')}</p>
          </CardBody>
        </Card>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 stagger-3">
          {list.map((domain) => (
            <DomainCard
              key={domain}
              domain={domain}
              info={results[domain]}
              loading={!!checking[domain]}
              onCheck={() => runCheck(domain)}
              onDelete={() => setDeleteDomain(domain)}
              deleting={removeMutation.isPending && deleteDomain === domain}
            />
          ))}
        </div>
      )}

      <Confirm
        open={deleteDomain !== null}
        title={t('ssl.deleteTitle')}
        message={t('ssl.deleteConfirm', { domain: deleteDomain ?? '' })}
        variant="danger"
        onConfirm={handleConfirmDelete}
        onCancel={() => setDeleteDomain(null)}
        loading={removeMutation.isPending}
      />
    </div>
  )
}

function DomainCard({
  domain,
  info,
  loading,
  onCheck,
  onDelete,
  deleting,
}: {
  domain: string
  info?: SSLInfo
  loading: boolean
  onCheck: () => void
  onDelete: () => void
  deleting: boolean
}) {
  const { t, hasKey } = useI18n()

  const checkStatus: SSLInfo['status'] = loading
    ? 'checking'
    : !info
      ? 'pending'
      : info.status
  const certStatus = info?.cert_status
  const enabled = info?.enabled

  const errorKey = info?.error_key
  const errorTranslateKey = errorKey ? `ssl.${errorKey}` : null
  const errorText = errorTranslateKey && hasKey(errorTranslateKey)
    ? t(errorTranslateKey, info?.error_params as Record<string, string | number> | undefined)
    : (info?.message ?? '')

  const isFailed = checkStatus === 'failed'

  return (
    <Card className="card-hover">
      <CardHeader className="flex items-center justify-between gap-3 py-4">
        <div className="flex items-center gap-3 min-w-0">
          <div
            className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${
              checkStatus === 'ok'
                ? certStatus === 'ok'
                  ? 'bg-success/10 text-success'
                  : certStatus === 'warning'
                    ? 'bg-warning/10 text-warning'
                    : certStatus === 'critical' || certStatus === 'expired'
                      ? 'bg-danger/10 text-danger'
                      : 'bg-success/10 text-success'
                : isFailed
                  ? 'bg-danger/10 text-danger'
                  : checkStatus === 'checking'
                    ? 'bg-accent/10 text-accent'
                    : 'bg-bg-sunken text-fg-muted'
            }`}
          >
            {checkStatus === 'checking' ? (
              <RefreshCw size={18} className="animate-spin" />
            ) : isFailed ? (
              <XCircle size={18} />
            ) : (
              <ShieldCheck size={18} />
            )}
          </div>
          <div className="min-w-0">
            <div className="text-sm font-semibold text-fg truncate">{domain}</div>
            <div className="text-xs text-fg-subtle truncate">
              {info?.issuer ? info.issuer : t('ssl.notChecked')}
            </div>
          </div>
        </div>
        <StatusBadge status={checkStatus} certStatus={certStatus} daysRemaining={info?.days_remaining} />
      </CardHeader>
      <CardBody className="space-y-3">
        {checkStatus === 'checking' ? (
          <div className="space-y-2">
            <Skeleton variant="rectangular" height={16} className="w-3/4" />
            <Skeleton variant="rectangular" height={16} className="w-1/2" />
          </div>
        ) : info ? (
          enabled ? (
            <>
              <div className="grid grid-cols-2 gap-3 text-sm">
                <InfoCell label={t('ssl.validFrom')} value={info.valid_from ?? '—'} />
                <InfoCell label={t('ssl.validTo')} value={info.valid_to ?? '—'} />
              </div>
              <div className="flex items-center justify-between gap-3 pt-2 border-t border-border/60">
                <div className="flex items-center gap-2 text-sm">
                  <span className="text-fg-muted">{t('ssl.daysRemaining')}</span>
                  <span
                    className={`font-semibold ${
                      (info.days_remaining ?? 0) < 0
                        ? 'text-danger'
                        : (info.days_remaining ?? 0) < 7
                          ? 'text-danger'
                          : (info.days_remaining ?? 0) < 14
                            ? 'text-warning'
                            : 'text-success'
                    }`}
                  >
                    {t('ssl.days', { count: info.days_remaining ?? 0 })}
                  </span>
                </div>
                <span
                  className={`inline-flex items-center gap-1 text-xs ${
                    info.chain_complete ? 'text-success' : 'text-warning'
                  }`}
                >
                  {info.chain_complete ? <Lock size={12} /> : <Unlock size={12} />}
                  {info.chain_complete ? t('ssl.chainComplete') : t('ssl.chainIncomplete')}
                </span>
              </div>
            </>
          ) : (
            <div className="flex items-start gap-2 text-sm text-danger">
              <AlertTriangle size={16} className="mt-0.5 shrink-0" />
              <div className="min-w-0">
                <div className="font-medium">{t('ssl.checkFailed')}</div>
                {errorText && (
                  <p className="text-xs text-fg-muted mt-0.5 break-all">{errorText}</p>
                )}
              </div>
            </div>
          )
        ) : (
          <p className="text-sm text-fg-muted">{t('ssl.notCheckedHint')}</p>
        )}
        <div className="flex items-center justify-end gap-2 pt-2 border-t border-border/60">
          <Button
            variant="ghost"
            size="sm"
            onClick={onCheck}
            loading={loading}
          >
            <RefreshCw size={14} />
            {isFailed ? t('ssl.retry') : t('ssl.check')}
          </Button>
          <Button
            variant="ghost"
            size="icon-sm"
            onClick={onDelete}
            disabled={deleting}
            loading={deleting}
            aria-label={t('common.delete')}
            className="text-fg-muted hover:text-danger"
          >
            <Trash2 size={14} />
          </Button>
        </div>
      </CardBody>
    </Card>
  )
}

function StatusBadge({
  status,
  certStatus,
  daysRemaining,
}: {
  status: SSLInfo['status']
  certStatus?: SSLInfo['cert_status']
  daysRemaining?: number
}) {
  const { t } = useI18n()

  if (status === 'checking') {
    return (
      <Badge variant="muted" aria-label={t('ssl.statusChecking')}>
        <Spinner size="sm" />
        {t('ssl.statusChecking')}
      </Badge>
    )
  }

  if (status === 'failed') {
    return (
      <Badge variant="danger" aria-label={t('ssl.statusFailed')}>
        <XCircle size={12} />
        {t('ssl.statusFailed')}
      </Badge>
    )
  }

  if (status === 'pending') {
    return (
      <Badge variant="warning" aria-label={t('ssl.statusPending')}>
        <Clock size={12} />
        {t('ssl.statusPending')}
      </Badge>
    )
  }

  if (status === 'ok') {
    if (certStatus === 'expired') {
      return (
        <Badge variant="danger" aria-label={t('ssl.statusExpired')}>
          <XCircle size={12} />
          {t('ssl.statusExpired')}
        </Badge>
      )
    }
    if (certStatus === 'critical') {
      return (
        <Badge variant="danger" aria-label={t('ssl.statusCritical')}>
          <AlertTriangle size={12} />
          {t('ssl.statusCritical')}
        </Badge>
      )
    }
    if (certStatus === 'warning') {
      return (
        <Badge variant="warning" aria-label={t('ssl.statusWarning')}>
          <AlertTriangle size={12} />
          {t('ssl.statusWarning')}
        </Badge>
      )
    }
    const label = typeof daysRemaining === 'number'
      ? t('ssl.days', { count: daysRemaining })
      : t('ssl.statusOk')
    return (
      <Badge variant="success" aria-label={t('ssl.statusOk')}>
        <CheckCircle2 size={12} />
        {label}
      </Badge>
    )
  }

  return (
    <Badge variant="muted" aria-label={t('ssl.statusPending')}>
      <Clock size={12} />
      {t('ssl.statusPending')}
    </Badge>
  )
}

function InfoCell({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="text-[10px] uppercase tracking-wide text-fg-subtle">{label}</div>
      <div className="font-mono text-xs text-fg mt-0.5 break-all">{value}</div>
    </div>
  )
}

type CertListRecord = Omit<AcmeCertificateRecord, 'privkey_pem_enc'> & {
  status_derived: AcmeCertStatus
}

function SSLCertificates() {
  const { t } = useI18n()
  const isMobile = useIsMobile()
  const queryClient = useQueryClient()
  const [issueModalOpen, setIssueModalOpen] = useState(false)
  const [deleteId, setDeleteId] = useState<string | null>(null)

  const { data: caps, isLoading: capsLoading } = useQuery({
    queryKey: ['ssl-acme-caps'],
    queryFn: () => sslApi.acmeCapabilities(),
  })

  const { data: records, isLoading: listLoading } = useQuery({
    queryKey: ['ssl-acme-certs'],
    queryFn: () => sslApi.listCertificates(),
  })

  const refreshList = useCallback(() => {
    queryClient.invalidateQueries({ queryKey: ['ssl-acme-certs'] })
  }, [queryClient])

  return (
    <div className="space-y-5">
      <div className="flex items-start justify-between gap-4 flex-wrap stagger-2">
        <div className="min-w-0">
          <h2 className="text-base font-semibold text-fg flex items-center gap-2">
            <FileCheck2 size={18} className="text-accent" />
            {t('ssl.acme.tabCertificates')}
          </h2>
          <p className="text-sm text-fg-muted mt-0.5">{t('ssl.acme.subheaderReadyOk')}</p>
        </div>
        <Button
          onClick={() => setIssueModalOpen(true)}
          disabled={!caps?.available && !capsLoading}
        >
          <Plus size={16} />
          {t('ssl.acme.issueCert')}
        </Button>
      </div>

      <CapabilityBanner caps={caps} loading={capsLoading} />

      {listLoading ? (
        <div className="space-y-3">
          <Skeleton variant="rectangular" height={56} className="w-full" />
          <Skeleton variant="rectangular" height={56} className="w-full" />
          <Skeleton variant="rectangular" height={56} className="w-full" />
        </div>
      ) : !records || records.length === 0 ? (
        <Card>
          <CardBody className="py-12 text-center">
            <div className="w-16 h-16 mx-auto mb-3 rounded-2xl bg-bg-sunken flex items-center justify-center text-fg-subtle">
              <FileCheck2 size={28} />
            </div>
            <p className="text-sm text-fg-muted">{t('ssl.empty')}</p>
            <p className="text-xs text-fg-subtle mt-1">{t('ssl.emptyHint')}</p>
          </CardBody>
        </Card>
      ) : isMobile ? (
        <div className="space-y-3">
          {records.map((r) => (
            <CertCard
              key={r.id}
              record={r}
              onRefresh={refreshList}
              onDelete={() => setDeleteId(r.id)}
            />
          ))}
        </div>
      ) : (
        <Card className="overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-bg-sunken/50 border-b border-border">
                  <th className="text-left font-medium text-fg-muted px-4 py-3">{t('ssl.acme.colDomain')}</th>
                  <th className="text-left font-medium text-fg-muted px-4 py-3">{t('ssl.acme.colStatus')}</th>
                  <th className="text-left font-medium text-fg-muted px-4 py-3">{t('ssl.acme.colNotBefore')}</th>
                  <th className="text-left font-medium text-fg-muted px-4 py-3">{t('ssl.acme.colNotAfter')}</th>
                  <th className="text-left font-medium text-fg-muted px-4 py-3">{t('ssl.acme.colIssuer')}</th>
                  <th className="text-left font-medium text-fg-muted px-4 py-3">{t('ssl.acme.colAutoRenew')}</th>
                  <th className="text-right font-medium text-fg-muted px-4 py-3">{t('ssl.acme.colActions')}</th>
                </tr>
              </thead>
              <tbody>
                {records.map((r) => (
                  <CertRow
                    key={r.id}
                    record={r}
                    onRefresh={refreshList}
                    onDelete={() => setDeleteId(r.id)}
                  />
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      <IssueCertModal
        open={issueModalOpen}
        onClose={() => setIssueModalOpen(false)}
        onIssued={refreshList}
      />

      <Confirm
        open={deleteId !== null}
        title={t('common.delete')}
        message={t('ssl.deleteConfirm', {
          domain: records?.find((r) => r.id === deleteId)?.domain ?? '',
        })}
        variant="danger"
        onConfirm={async () => {
          if (deleteId === null) return
          try {
            await sslApi.removeCert(deleteId)
            toast({ type: 'success', title: t('common.deleted') })
            refreshList()
          } catch (err) {
            toast({ type: 'error', title: t('common.deleteFailed'), description: err instanceof Error ? err.message : t('common.unknownError') })
          }
          setDeleteId(null)
        }}
        onCancel={() => setDeleteId(null)}
      />
    </div>
  )
}

function CapabilityBanner({ caps, loading }: { caps?: AcmeCapabilities; loading: boolean }) {
  const { t } = useI18n()
  if (loading || !caps) return null

  if (caps.available) {
    return (
      <div className="rounded-xl border border-success/30 bg-success/5 px-4 py-3 flex items-start gap-3">
        <CheckCircle2 size={18} className="text-success mt-0.5 shrink-0" />
        <div className="text-sm">
          <div className="font-medium text-success">{t('ssl.acme.subheaderReadyOk')}</div>
          <div className="text-fg-muted mt-1 text-xs">{t('ssl.acme.wellKnownNoticeBanner')}</div>
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-warning/30 bg-warning/5 px-4 py-3 space-y-2">
      {!caps.acme_extensions_ok && (
        <div className="flex items-start gap-3">
          <AlertTriangle size={18} className="text-warning mt-0.5 shrink-0" />
          <div className="text-sm text-fg">
            <div className="font-medium text-warning">{t('ssl.acme.subheaderNeedExtOpenssl')}</div>
          </div>
        </div>
      )}
      {!caps.docroot_known && (
        <div className="flex items-start gap-3">
          <AlertTriangle size={18} className="text-warning mt-0.5 shrink-0" />
          <div className="text-sm text-fg">
            <div className="font-medium">{t('ssl.acme.subheaderDocrootUnknown')}</div>
          </div>
        </div>
      )}
      {!caps.challenges_dir_writable && (
        <div className="flex items-start gap-3">
          <AlertTriangle size={18} className="text-warning mt-0.5 shrink-0" />
          <div className="text-sm text-fg">
            <div className="font-medium">{t('ssl.acme.subheaderChallengesNotWritable')}</div>
          </div>
        </div>
      )}
    </div>
  )
}

function CertStatusBadge({ status }: { status: AcmeCertStatus }) {
  const { t } = useI18n()
  const map: Record<AcmeCertStatus, { variant: 'success' | 'warning' | 'danger' | 'muted' | 'accent'; label: string; icon: typeof CheckCircle2 }> = {
    valid: { variant: 'success', label: t('ssl.acme.statusValid'), icon: CheckCircle2 },
    pending: { variant: 'accent', label: t('ssl.acme.statusPending'), icon: Clock },
    invalid: { variant: 'muted', label: t('ssl.acme.statusInvalid'), icon: XCircle },
    expiring_soon: { variant: 'warning', label: t('ssl.acme.statusExpiringSoon'), icon: AlertTriangle },
    expired: { variant: 'danger', label: t('ssl.acme.statusExpired'), icon: XCircle },
    renew_failed: { variant: 'danger', label: t('ssl.acme.renewFailed'), icon: AlertTriangle },
  }
  const cfg = map[status] ?? map.invalid
  const Icon = cfg.icon
  return (
    <Badge variant={cfg.variant}>
      <Icon size={12} />
      {cfg.label}
    </Badge>
  )
}

function formatDate(ts: number) {
  if (!ts) return '—'
  return new Date(ts * 1000).toISOString().slice(0, 10)
}

function daysFromNow(ts: number): string {
  if (!ts) return ''
  const diff = ts - Math.floor(Date.now() / 1000)
  const days = Math.floor(diff / 86400)
  if (days >= 0) return `Expires in ${days}d`
  return `Expired ${-days}d ago`
}

function CertRow({
  record,
  onRefresh,
  onDelete,
}: {
  record: CertListRecord
  onRefresh: () => void
  onDelete: () => void
}) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [renewing, setRenewing] = useState(false)
  const [downloading, setDownloading] = useState(false)
  const [editingAutoRenew, setEditingAutoRenew] = useState(false)
  const [autoRenewInput, setAutoRenewInput] = useState(String(record.auto_renew_days_before ?? 30))

  const handleRenew = async () => {
    setRenewing(true)
    try {
      await sslApi.renewCert(record.id)
      toast({ type: 'success', title: t('ssl.acme.renewButton') })
      onRefresh()
    } catch (err) {
      toast({ type: 'error', title: t('ssl.addFailed'), description: err instanceof Error ? err.message : t('common.unknownError') })
    } finally {
      setRenewing(false)
    }
  }

  const handleDownload = async () => {
    setDownloading(true)
    try {
      const blob = await sslApi.downloadPem(record.id)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `${record.domain}.pem`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    } catch (err) {
      toast({ type: 'error', title: t('common.download') + ' ' + t('common.failure'), description: err instanceof Error ? err.message : t('common.unknownError') })
    } finally {
      setDownloading(false)
    }
  }

  const saveAutoRenew = async () => {
    const days = Math.max(0, Math.min(90, parseInt(autoRenewInput, 10) || 0))
    try {
      await sslApi.updateAutoRenew(record.id, days)
      toast({ type: 'success', title: t('common.updated') })
      queryClient.invalidateQueries({ queryKey: ['ssl-acme-certs'] })
    } catch (err) {
      toast({ type: 'error', title: t('common.saveFailed'), description: err instanceof Error ? err.message : t('common.unknownError') })
    } finally {
      setEditingAutoRenew(false)
    }
  }

  const autoRenewDays = record.auto_renew_days_before ?? 30
  const autoRenewEnabled = autoRenewDays > 0

  return (
    <tr className="border-b border-border/50 hover:bg-bg-sunken/30 transition-colors">
      <td className="px-4 py-3">
        <div className="font-medium text-fg">{record.domain}</div>
        {record.san_domains && record.san_domains.length > 1 && (
          <div className="text-xs text-fg-subtle mt-0.5">+{record.san_domains.length - 1} SAN</div>
        )}
      </td>
      <td className="px-4 py-3">
        <CertStatusBadge status={record.status_derived} />
        {record.last_renew_error && (
          <div
            className="text-[11px] text-danger mt-1 max-w-[240px] truncate"
            title={truncate(record.last_renew_error, 120)}
          >
            {t('ssl.acme.renewError')}：{truncate(record.last_renew_error, 120)}
          </div>
        )}
      </td>
      <td className="px-4 py-3 font-mono text-xs text-fg-muted">
        {formatDate(record.not_before_ts)}
      </td>
      <td className="px-4 py-3">
        <div className="font-mono text-xs text-fg">{formatDate(record.not_after_ts)}</div>
        <div className="text-xs text-fg-subtle mt-0.5">{daysFromNow(record.not_after_ts)}</div>
      </td>
      <td className="px-4 py-3 text-sm text-fg-muted">
        {record.issuer_url || 'Let\'s Encrypt'}
      </td>
      <td className="px-4 py-3">
        {editingAutoRenew ? (
          <div className="flex items-center gap-2">
            <input
              type="number"
              min={0}
              max={90}
              value={autoRenewInput}
              onChange={(e) => setAutoRenewInput(e.target.value)}
              className="w-20 px-2 py-1 text-xs border border-border rounded-lg bg-bg text-fg focus:outline-none focus:border-accent"
            />
            <Button size="icon-sm" variant="primary" onClick={saveAutoRenew}>
              <CheckCircle2 size={14} />
            </Button>
            <Button size="icon-sm" variant="ghost" onClick={() => setEditingAutoRenew(false)}>
              <XCircle size={14} />
            </Button>
          </div>
        ) : (
          <button
            onClick={() => {
              setAutoRenewInput(String(autoRenewDays))
              setEditingAutoRenew(true)
            }}
            className="group inline-flex items-center gap-1.5 text-xs"
          >
            {autoRenewEnabled ? (
              <span className="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-success/10 text-success group-hover:bg-success/20">
                <CheckCircle2 size={12} />
                Renews {autoRenewDays}d before
              </span>
            ) : (
              <span className="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-bg-sunken text-fg-muted group-hover:bg-bg-sunken/80">
                <Settings size={12} />
                Manual renew
              </span>
            )}
          </button>
        )}
      </td>
      <td className="px-4 py-3">
        <div className="flex items-center justify-end gap-1">
          <Button variant="ghost" size="icon-sm" onClick={handleRenew} loading={renewing} aria-label={t('ssl.acme.renewButton')}>
            <RefreshCw size={14} />
          </Button>
          <Button variant="ghost" size="icon-sm" onClick={handleDownload} loading={downloading} aria-label={t('ssl.acme.downloadPem')}>
            <Download size={14} />
          </Button>
          <Button variant="ghost" size="icon-sm" onClick={onDelete} aria-label={t('ssl.acme.deleteButton')} className="text-fg-muted hover:text-danger">
            <Trash2 size={14} />
          </Button>
        </div>
      </td>
    </tr>
  )
}

function CertCard({
  record,
  onRefresh,
  onDelete,
}: {
  record: CertListRecord
  onRefresh: () => void
  onDelete: () => void
}) {
  const { t } = useI18n()
  const [renewing, setRenewing] = useState(false)
  const [downloading, setDownloading] = useState(false)

  const handleRenew = async () => {
    setRenewing(true)
    try {
      await sslApi.renewCert(record.id)
      toast({ type: 'success', title: t('ssl.acme.renewButton') })
      onRefresh()
    } catch (err) {
      toast({ type: 'error', title: t('ssl.addFailed'), description: err instanceof Error ? err.message : t('common.unknownError') })
    } finally {
      setRenewing(false)
    }
  }

  const handleDownload = async () => {
    setDownloading(true)
    try {
      const blob = await sslApi.downloadPem(record.id)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `${record.domain}.pem`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    } catch (err) {
      toast({ type: 'error', title: t('common.download') + ' ' + t('common.failure'), description: err instanceof Error ? err.message : t('common.unknownError') })
    } finally {
      setDownloading(false)
    }
  }

  const autoRenewDays = record.auto_renew_days_before ?? 30
  const autoRenewEnabled = autoRenewDays > 0

  return (
    <Card>
      <CardHeader className="flex items-start justify-between gap-3 py-3">
        <div className="min-w-0">
          <div className="text-sm font-semibold text-fg truncate">{record.domain}</div>
          <div className="mt-2"><CertStatusBadge status={record.status_derived} /></div>
          {record.last_renew_error && (
            <div className="text-[11px] text-danger mt-1 break-all" title={truncate(record.last_renew_error, 120)}>
              {t('ssl.acme.renewError')}：{truncate(record.last_renew_error, 120)}
            </div>
          )}
        </div>
        <div className="flex items-center gap-1 shrink-0">
          <Button variant="ghost" size="icon-sm" onClick={handleRenew} loading={renewing} aria-label={t('ssl.acme.renewButton')}>
            <RefreshCw size={14} />
          </Button>
          <Button variant="ghost" size="icon-sm" onClick={handleDownload} loading={downloading} aria-label={t('ssl.acme.downloadPem')}>
            <Download size={14} />
          </Button>
          <Button variant="ghost" size="icon-sm" onClick={onDelete} aria-label={t('ssl.acme.deleteButton')} className="text-fg-muted hover:text-danger">
            <Trash2 size={14} />
          </Button>
        </div>
      </CardHeader>
      <CardBody className="space-y-2 pt-0">
        <div className="grid grid-cols-2 gap-3 text-xs">
          <div>
            <div className="text-[10px] uppercase tracking-wide text-fg-subtle">{t('ssl.acme.colNotBefore')}</div>
            <div className="font-mono text-fg mt-0.5">{formatDate(record.not_before_ts)}</div>
          </div>
          <div>
            <div className="text-[10px] uppercase tracking-wide text-fg-subtle">{t('ssl.acme.colNotAfter')}</div>
            <div className="font-mono text-fg mt-0.5">{formatDate(record.not_after_ts)}</div>
            <div className="text-fg-subtle mt-0.5">{daysFromNow(record.not_after_ts)}</div>
          </div>
        </div>
        <div className="flex items-center justify-between pt-2 border-t border-border/50">
          <div className="text-xs text-fg-muted flex items-center gap-1">
            <Server size={12} />
            {record.issuer_url || 'Let\'s Encrypt'}
          </div>
          {autoRenewEnabled ? (
            <Badge variant="success" className="text-[11px]">
              <CheckCircle2 size={11} />
              Renews {autoRenewDays}d before
            </Badge>
          ) : (
            <Badge variant="muted" className="text-[11px]">
              Manual renew
            </Badge>
          )}
        </div>
      </CardBody>
    </Card>
  )
}

function IssueCertModal({
  open,
  onClose,
  onIssued,
}: {
  open: boolean
  onClose: () => void
  onIssued: () => void
}) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [email, setEmail] = useState('')
  const [domain, setDomain] = useState('')
  const [acceptTos, setAcceptTos] = useState(false)
  const [ca, setCa] = useState<'letsencrypt' | 'letsencrypt-staging'>('letsencrypt')
  const [issuing, setIssuing] = useState(false)

  const domainValid = useMemo(() => {
    const d = domain.trim()
    if (!d) return false
    return /^[a-zA-Z0-9][a-zA-Z0-9\-\.]*\.[a-zA-Z]{2,}$/.test(d)
  }, [domain])

  const emailValid = useMemo(() => {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())
  }, [email])

  const canSubmit = domainValid && emailValid && acceptTos && !issuing

  const handleSubmit = async () => {
    if (!canSubmit) return
    setIssuing(true)
    try {
      const result = await sslApi.issueCert({
        domain: domain.trim(),
        email: email.trim(),
        accept_tos: acceptTos,
        ca,
      })
      if (result.ok) {
        toast({ type: 'success', title: t('ssl.acme.issueCert') })
        queryClient.invalidateQueries({ queryKey: ['ssl-acme-certs'] })
        queryClient.invalidateQueries({ queryKey: ['ssl-acme-caps'] })
        onIssued()
        onClose()
        setEmail('')
        setDomain('')
        setAcceptTos(false)
      }
    } catch (err) {
      toast({
        type: 'error',
        title: t('ssl.checkFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    } finally {
      setIssuing(false)
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('ssl.acme.issueTitle')}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={issuing}>
            {t('common.cancel')}
          </Button>
          <Button
            variant="primary"
            onClick={handleSubmit}
            disabled={!canSubmit}
            loading={issuing}
          >
            {t('ssl.acme.issueCert')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div className="space-y-2">
            <label className="block text-sm font-medium text-fg">
              {t('ssl.acme.emailLabel')} <span className="text-danger">*</span>
            </label>
            <Input
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="admin@example.com"
              icon={<Mail size={16} />}
              disabled={issuing}
            />
            <p className="text-xs text-fg-subtle">{t('ssl.acme.emailHint')}</p>
          </div>
          <div className="space-y-2">
            <label className="block text-sm font-medium text-fg">
              {t('ssl.acme.domainLabel')} <span className="text-danger">*</span>
            </label>
            <Input
              value={domain}
              onChange={(e) => setDomain(e.target.value)}
              placeholder="example.com"
              icon={<Globe size={16} />}
              disabled={issuing}
            />
          </div>
        </div>

        <div className="space-y-2">
          <label className="block text-sm font-medium text-fg">{t('ssl.acme.sansDisabledLabel')}</label>
          <Input
            value=""
            disabled
            placeholder="SANs: Future release will support multiple domains"
            icon={<Plus size={16} />}
          />
        </div>

        <div className="space-y-2">
          <label className="block text-sm font-medium text-fg">{t('ssl.acme.caLabel')}</label>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => setCa('letsencrypt')}
              disabled={issuing}
              className={`flex-1 px-3 py-2.5 rounded-xl text-sm border transition-all ${
                ca === 'letsencrypt'
                  ? 'border-accent bg-accent/10 text-fg'
                  : 'border-border bg-bg text-fg-muted hover:border-border/80'
              }`}
            >
              <div className="font-medium">Let's Encrypt Production</div>
              <div className="text-xs opacity-70 mt-0.5">Real certificates, rate limits apply</div>
            </button>
            <button
              type="button"
              disabled
              title="Staging environment - Coming soon"
              className="flex-1 px-3 py-2.5 rounded-xl text-sm border border-border bg-bg-sunken text-fg-subtle opacity-60 cursor-not-allowed"
            >
              <div className="font-medium">Let's Encrypt Staging</div>
              <div className="text-xs mt-0.5">For testing, untrusted certs</div>
            </button>
          </div>
        </div>

        <div className="rounded-xl border border-border bg-bg-sunken/30 p-4">
          <label className="flex items-start gap-3 cursor-pointer select-none">
            <button
              type="button"
              onClick={() => !issuing && setAcceptTos((v) => !v)}
              className="mt-0.5 shrink-0 text-fg hover:text-accent transition-colors disabled:opacity-50"
              disabled={issuing}
            >
              {acceptTos ? (
                <CheckSquare size={20} className="text-accent" />
              ) : (
                <Square size={20} />
              )}
            </button>
            <div className="text-sm">
              <div className="font-medium text-fg">
                {t('ssl.acme.tosLabel')} <span className="text-danger">*</span>
              </div>
              <a
                href="https://letsencrypt.org/repository/"
                target="_blank"
                rel="noreferrer noopener"
                className="text-xs text-accent hover:underline inline-flex items-center gap-1 mt-1"
              >
                {t('ssl.acme.tosLink')}
                <ExternalLink size={12} />
              </a>
              {!acceptTos && (
                <p className="text-xs text-warning mt-2">{t('ssl.acme.acceptTosRequired')}</p>
              )}
            </div>
          </label>
        </div>
      </div>
    </Modal>
  )
}
