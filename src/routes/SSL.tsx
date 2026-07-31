import { useCallback, useEffect, useState } from 'react'
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
} from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Skeleton } from '@/components/ui/Skeleton'
import { EmptyError } from '@/components/ui/EmptyState'
import { Confirm } from '@/components/ui/Modal'
import { sslApi } from '@/api/ssl'
import { toast } from '@/components/ui/Toast'
import { useI18n } from '@/hooks/useI18n'
import type { SSLInfo } from '@shared/types'

type SSLStatus = 'ok' | 'warning' | 'critical' | 'expired'

export default function SSL() {
  const { t, hasKey } = useI18n()
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

  // 首次加载域名列表后自动检测全部
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
      <div className="p-4 md:p-6 space-y-5">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <Shield size={22} className="text-accent" />
            {t('ssl.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('ssl.subtitle')}</p>
        </div>
        <Skeleton variant="rectangular" height={64} className="w-full" />
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Skeleton variant="rectangular" height={140} className="w-full" />
          <Skeleton variant="rectangular" height={140} className="w-full" />
        </div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="p-4 md:p-6">
        <EmptyError
          error={error instanceof Error ? error.message : t('common.unknownError')}
          onRetry={() => refetch()}
        />
      </div>
    )
  }

  const list = domains ?? []

  return (
    <div className="p-4 md:p-6 space-y-5 page-enter">
      <div className="flex items-start justify-between gap-4 flex-wrap stagger-1">
        <div className="min-w-0">
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <Shield size={22} className="text-accent" />
            {t('ssl.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('ssl.subtitle')}</p>
        </div>
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

      {/* 添加域名 */}
      <Card className="stagger-2">
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

  const status = info?.status as SSLStatus | undefined
  const enabled = info?.enabled

  const errorKey = info?.error_key
  const errorTranslateKey = errorKey ? `ssl.${errorKey}` : null
  const errorText = errorTranslateKey && hasKey(errorTranslateKey)
    ? t(errorTranslateKey, info?.error_params as Record<string, string | number> | undefined)
    : (info?.message ?? '')

  return (
    <Card className="card-hover">
      <CardHeader className="flex items-center justify-between gap-3 py-4">
        <div className="flex items-center gap-3 min-w-0">
          <div
            className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${
              status === 'ok'
                ? 'bg-success/10 text-success'
                : status === 'warning'
                  ? 'bg-warning/10 text-warning'
                  : status === 'critical' || status === 'expired'
                    ? 'bg-danger/10 text-danger'
                    : 'bg-bg-sunken text-fg-muted'
            }`}
          >
            {loading ? (
              <RefreshCw size={18} className="animate-spin" />
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
        <StatusBadge status={status} enabled={enabled} />
      </CardHeader>
      <CardBody className="space-y-3">
        {loading ? (
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
            disabled={loading}
            loading={loading}
          >
            <RefreshCw size={14} />
            {t('ssl.check')}
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

function StatusBadge({ status, enabled }: { status?: SSLStatus; enabled?: boolean }) {
  const { t } = useI18n()
  if (!enabled && status === undefined) {
    return <Badge variant="muted">{t('ssl.statusPending')}</Badge>
  }
  if (!enabled) {
    return (
      <Badge variant="danger">
        <XCircle size={12} />
        {t('ssl.statusError')}
      </Badge>
    )
  }
  if (status === 'ok') {
    return (
      <Badge variant="success">
        <CheckCircle2 size={12} />
        {t('ssl.statusOk')}
      </Badge>
    )
  }
  if (status === 'warning') {
    return (
      <span className="badge bg-warning/10 text-warning">
        <AlertTriangle size={12} />
        {t('ssl.statusWarning')}
      </span>
    )
  }
  if (status === 'critical' || status === 'expired') {
    return (
      <Badge variant="danger">
        <XCircle size={12} />
        {status === 'expired' ? t('ssl.statusExpired') : t('ssl.statusCritical')}
      </Badge>
    )
  }
  return <Badge variant="muted">{t('ssl.statusPending')}</Badge>
}

function InfoCell({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="text-[10px] uppercase tracking-wide text-fg-subtle">{label}</div>
      <div className="font-mono text-xs text-fg mt-0.5 break-all">{value}</div>
    </div>
  )
}
