import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Server, RefreshCw, FileWarning } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/Alert'
import { Skeleton } from '@/components/ui/Skeleton'
import { phpFpmApi } from '@/api/phpFpm'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'

export default function PhpFpm() {
  const { t } = useI18n()
  const [slowlogPath, setSlowlogPath] = useState('')

  const statusQuery = useQuery({
    queryKey: ['php-fpm-status'],
    queryFn: () => phpFpmApi.status(),
    retry: false,
  })

  const slowlogQuery = useQuery({
    queryKey: ['php-fpm-slowlog', slowlogPath],
    queryFn: () => phpFpmApi.slowlog(slowlogPath || undefined),
    retry: false,
    enabled: false,
  })

  const statusErr = (statusQuery.error as { code?: string } | null | undefined)?.code
  const slowlogErr = (slowlogQuery.error as { code?: string } | null | undefined)?.code
  const status = statusQuery.data?.status

  const stat = (label: string, value: React.ReactNode) => (
    <div className="px-3 py-2 rounded-lg bg-bg-sunken">
      <div className="text-[11px] text-fg-subtle">{label}</div>
      <div className="text-sm font-mono text-fg">{value}</div>
    </div>
  )

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-4xl mx-auto page-enter">
      <div className="stagger-1 flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <Server size={20} className="text-accent" />
            {t('phpFpm.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('phpFpm.subtitle')}</p>
        </div>
        <Button variant="ghost" onClick={() => statusQuery.refetch()} loading={statusQuery.isFetching}>
          <RefreshCw size={16} />
          {t('common.refresh')}
        </Button>
      </div>

      {statusQuery.isLoading ? (
        <Card className="stagger-2">
          <CardBody>
            <Skeleton variant="rectangular" height={120} />
          </CardBody>
        </Card>
      ) : statusErr === 'fpm_not_applicable' ? (
        <Alert variant="warning" className="stagger-2">
          <AlertTitle>{t('phpFpm.notApplicable')}</AlertTitle>
          <AlertDescription>{t('phpFpm.notApplicableHint')}</AlertDescription>
        </Alert>
      ) : statusQuery.isError ? (
        <Alert variant="destructive" className="stagger-2">
          <AlertTitle>{t('common.error')}</AlertTitle>
          <AlertDescription>{resolveErrorText(statusQuery.error)}</AlertDescription>
        </Alert>
      ) : status ? (
        <Card className="stagger-2">
          <CardHeader>
            <div className="text-sm font-semibold text-fg">{t('phpFpm.statusTitle')}</div>
            <div className="text-xs text-fg-subtle font-mono">{statusQuery.data?.url}</div>
          </CardHeader>
          <CardBody className="grid grid-cols-2 md:grid-cols-4 gap-3">
            {stat(t('phpFpm.pool'), status.pool || '—')}
            {stat(t('phpFpm.active'), status.active ?? '—')}
            {stat(t('phpFpm.idle'), status.idle ?? '—')}
            {stat(t('phpFpm.totalProc'), status.total ?? '—')}
            {stat(t('phpFpm.maxActive'), status.max_active ?? '—')}
            {stat(t('phpFpm.maxChildrenReached'), status.max_children_reached ?? '—')}
            {stat(t('phpFpm.acceptedConn'), status.accepted_conn ?? '—')}
            {stat(t('phpFpm.listenQueue'), status.listen_queue ?? '—')}
          </CardBody>
        </Card>
      ) : null}

      <Card className="stagger-3">
        <CardHeader className="flex flex-wrap items-center gap-3">
          <div className="text-sm font-semibold text-fg flex items-center gap-2">
            <FileWarning size={16} />
            {t('phpFpm.slowlog')}
          </div>
          <div className="flex-1 min-w-[200px]">
            <input
              className="w-full h-9 rounded-lg border border-border bg-bg-elevated px-3 text-sm font-mono"
              value={slowlogPath}
              onChange={(e) => setSlowlogPath(e.target.value)}
              placeholder={t('phpFpm.slowlogPathPlaceholder')}
            />
          </div>
          <Button
            variant="secondary"
            loading={slowlogQuery.isFetching}
            onClick={() => slowlogQuery.refetch()}
          >
            {t('phpFpm.loadSlowlog')}
          </Button>
        </CardHeader>
        <CardBody>
          {slowlogErr === 'fpm_not_applicable' ? (
            <div className="text-xs text-fg-subtle">{t('phpFpm.notApplicableHint')}</div>
          ) : slowlogQuery.isError ? (
            <div className="text-xs text-danger">{resolveErrorText(slowlogQuery.error)}</div>
          ) : slowlogQuery.data ? (
            slowlogQuery.data.count === 0 ? (
              <div className="text-xs text-fg-subtle">{t('phpFpm.noLines')}</div>
            ) : (
              <>
                <div className="text-[11px] text-fg-subtle font-mono mb-2">
                  {slowlogQuery.data.path} · {slowlogQuery.data.count} {t('common.rows')}
                </div>
                <pre className="text-xs font-mono whitespace-pre-wrap max-h-80 overflow-auto text-fg-muted">
                  {slowlogQuery.data.lines.join('\n')}
                </pre>
              </>
            )
          ) : (
            <div className="text-xs text-fg-subtle">{t('phpFpm.slowlogHint')}</div>
          )}
        </CardBody>
      </Card>
    </div>
  )
}
