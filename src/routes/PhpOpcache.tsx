import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Gauge, RefreshCw, Power, Zap, AlertTriangle } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/Alert'
import { Skeleton } from '@/components/ui/Skeleton'
import { toast } from '@/components/ui/Toast'
import { phpOpcacheApi, type OpcacheMutationResponse } from '@/api/phpOpcache'
import { useI18n } from '@/hooks/useI18n'
import { formatBytes } from '@/lib/format'
import { resolveErrorText } from '@/lib/errorMessages'

export default function PhpOpcache() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [lastResult, setLastResult] = useState<OpcacheMutationResponse | null>(null)
  const [lastError, setLastError] = useState<string | null>(null)

  const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey: ['php-opcache'],
    queryFn: () => phpOpcacheApi.status(),
    retry: false,
  })

  const errCode = (error as { code?: string } | null | undefined)?.code

  const afterMutation = (res: OpcacheMutationResponse, key: string) => {
    setLastResult(res)
    setLastError(null)
    toast({ type: 'success', title: t(key) })
    queryClient.invalidateQueries({ queryKey: ['php-opcache'] })
  }

  const onMutationError = (err: Error) => {
    setLastError(resolveErrorText(err))
    toast({ type: 'error', title: t('common.failure'), description: resolveErrorText(err) })
  }

  const resetMutation = useMutation({
    mutationFn: () => phpOpcacheApi.reset(),
    onSuccess: (res) => afterMutation(res, 'phpOpcache.resetDone'),
    onError: onMutationError,
  })

  const toggleMutation = useMutation({
    mutationFn: (enable: boolean) => phpOpcacheApi.toggle(enable),
    onSuccess: (res) => afterMutation(res, 'phpOpcache.toggleDone'),
    onError: onMutationError,
  })

  const profileMutation = useMutation({
    mutationFn: () => phpOpcacheApi.applyProfile(),
    onSuccess: (res) => afterMutation(res, 'phpOpcache.profileApplied'),
    onError: onMutationError,
  })

  const summary = data?.summary

  const stat = (label: string, value: React.ReactNode, tone?: string) => (
    <div className="px-3 py-2 rounded-lg bg-bg-sunken">
      <div className="text-[11px] text-fg-subtle">{label}</div>
      <div className={`text-sm font-mono ${tone || 'text-fg'}`}>{value}</div>
    </div>
  )

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-4xl mx-auto page-enter">
      <div className="stagger-1 flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <Gauge size={20} className="text-accent" />
            {t('phpOpcache.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('phpOpcache.subtitle')}</p>
        </div>
        <Button variant="ghost" onClick={() => refetch()} loading={isFetching}>
          <RefreshCw size={16} />
          {t('common.refresh')}
        </Button>
      </div>

      {isLoading ? (
        <Card className="stagger-2">
          <CardBody>
            <Skeleton variant="rectangular" height={140} />
          </CardBody>
        </Card>
      ) : isError ? (
        <Alert variant="warning" className="stagger-2">
          <AlertTitle className="flex items-center gap-2">
            <AlertTriangle size={16} />
            {errCode === 'opcache_unavailable' ? t('phpOpcache.unavailable') : t('common.error')}
          </AlertTitle>
          <AlertDescription>
            <div className="space-y-1">
              <div>{resolveErrorText(error)}</div>
              {errCode === 'opcache_unavailable' && (
                <div className="text-xs opacity-80">{t('phpOpcache.unavailableHint')}</div>
              )}
            </div>
          </AlertDescription>
        </Alert>
      ) : data && summary ? (
        <>
          <Card className="stagger-2">
            <CardHeader>
              <div className="text-sm font-semibold text-fg">{t('phpOpcache.summaryTitle')}</div>
              <Badge variant={summary.enabled ? 'success' : 'danger'}>
                {summary.enabled ? t('phpOpcache.enabled') : t('phpOpcache.disabled')}
              </Badge>
            </CardHeader>
            <CardBody className="grid grid-cols-2 md:grid-cols-3 gap-3">
              {stat(
                t('phpOpcache.hitRate'),
                summary.hit_rate === null ? '—' : `${(summary.hit_rate * 100).toFixed(2)}%`,
                summary.hit_rate !== null && summary.hit_rate >= 0.95 ? 'text-success' : 'text-warning',
              )}
              {stat(t('phpOpcache.hits'), summary.hits)}
              {stat(t('phpOpcache.misses'), summary.misses)}
              {stat(t('phpOpcache.cachedScripts'), summary.cached_scripts)}
              {stat(t('phpOpcache.memoryUsed'), summary.used_memory === null ? '—' : formatBytes(summary.used_memory))}
              {stat(t('phpOpcache.memoryFree'), summary.free_memory === null ? '—' : formatBytes(summary.free_memory))}
              {stat(t('phpOpcache.memoryWasted'), summary.wasted_memory === null ? '—' : formatBytes(summary.wasted_memory))}
              {stat(t('phpOpcache.oom'), summary.oom_restarts)}
              {stat(t('phpOpcache.hash'), summary.hash_restarts)}
            </CardBody>
          </Card>

          <Card className="stagger-3">
            <CardHeader>
              <div className="text-sm font-semibold text-fg">{t('phpOpcache.actionsTitle')}</div>
            </CardHeader>
            <CardBody className="flex flex-wrap gap-2">
              <Button
                variant="secondary"
                loading={resetMutation.isPending}
                onClick={() => resetMutation.mutate()}
              >
                <RefreshCw size={16} />
                {t('phpOpcache.reset')}
              </Button>
              <Button
                variant="secondary"
                loading={toggleMutation.isPending}
                onClick={() => toggleMutation.mutate(!summary.enabled)}
              >
                <Power size={16} />
                {summary.enabled ? t('phpOpcache.disable') : t('phpOpcache.enable')}
              </Button>
              <Button
                variant="primary"
                loading={profileMutation.isPending}
                onClick={() => profileMutation.mutate()}
              >
                <Zap size={16} />
                {t('phpOpcache.applyProfile')}
              </Button>
            </CardBody>
          </Card>

          {(lastError || lastResult) && (
            <Alert variant={lastError ? 'destructive' : 'default'} className="stagger-4">
              <AlertTitle>{lastError ? t('common.failure') : t('phpOpcache.lastResult')}</AlertTitle>
              <AlertDescription>
                {lastError ? (
                  <div>{lastError}</div>
                ) : (
                  <div className="space-y-1 text-xs font-mono">
                    <div>
                      {t('phpOpcache.effective')}: {String(lastResult?.effective)}
                    </div>
                    {lastResult?.reload_required && (
                      <div className="text-warning">{t('phpOpcache.reloadRequired')}</div>
                    )}
                    {(lastResult?.php_ini_required || []).map((r) => (
                      <div key={r.directive}>
                        {r.directive} = {r.target} → {t('phpOpcache.phpIniRequired')}
                      </div>
                    ))}
                  </div>
                )}
              </AlertDescription>
            </Alert>
          )}
        </>
      ) : null}
    </div>
  )
}
