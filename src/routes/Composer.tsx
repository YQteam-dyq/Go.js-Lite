import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { PackageCheck, Download, RefreshCw, Plus, Terminal } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/Alert'
import { Input } from '@/components/ui/Input'
import { Skeleton } from '@/components/ui/Skeleton'
import { toast } from '@/components/ui/Toast'
import { composerApi } from '@/api/composer'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'

export default function Composer() {
  const { t } = useI18n()
  const [pkg, setPkg] = useState('')
  const [log, setLog] = useState('')

  const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey: ['composer-status'],
    queryFn: () => composerApi.status(),
  })

  const action = useMutation({
    mutationFn: (kind: 'install' | 'update' | 'require') => {
      if (kind === 'install') return composerApi.install()
      if (kind === 'update') return composerApi.update()
      return composerApi.requirePackage(pkg.trim())
    },
    onSuccess: (res) => {
      setLog(res.log || '')
      toast({ type: 'success', title: t('composer.done') })
      refetch()
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.failure'), description: resolveErrorText(err) })
    },
  })

  const stat = (label: string, value: React.ReactNode) => (
    <div className="px-3 py-2 rounded-lg bg-bg-sunken">
      <div className="text-[11px] text-fg-subtle">{label}</div>
      <div className="text-sm text-fg font-mono truncate">{value}</div>
    </div>
  )

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-5xl mx-auto page-enter">
      <div className="stagger-1 flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <PackageCheck size={20} className="text-accent" />
            {t('composer.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('composer.subtitle')}</p>
        </div>
        <Button variant="ghost" onClick={() => refetch()} loading={isFetching}>
          <RefreshCw size={16} />
          {t('common.refresh')}
        </Button>
      </div>

      {isLoading ? (
        <Card className="stagger-2">
          <CardBody>
            <Skeleton variant="rectangular" height={120} />
          </CardBody>
        </Card>
      ) : isError ? (
        <Alert variant="destructive" className="stagger-2">
          <AlertTitle>{t('common.error')}</AlertTitle>
          <AlertDescription>{resolveErrorText(error)}</AlertDescription>
        </Alert>
      ) : data ? (
        <>
          {!data.available && (
            <Alert variant="warning" className="stagger-2">
              <AlertTitle>{t('composer.unavailable')}</AlertTitle>
              <AlertDescription>
                <div className="space-y-1">
                  <div>{t('composer.installGuide')}</div>
                  <pre className="text-xs whitespace-pre-wrap font-mono">
                    {(data.install_guide?.steps || []).join('\n')}
                  </pre>
                </div>
              </AlertDescription>
            </Alert>
          )}

          <Card className="stagger-2">
            <CardHeader>
              <div className="text-sm font-semibold text-fg">{t('composer.statusTitle')}</div>
              <Badge variant={data.available ? 'success' : 'danger'}>
                {data.available ? t('composer.available') : t('composer.missing')}
              </Badge>
            </CardHeader>
            <CardBody className="grid grid-cols-2 md:grid-cols-3 gap-3">
              {stat(t('composer.executable'), data.executable || '—')}
              {stat(t('composer.phpVersion'), data.php_version)}
              {stat(t('composer.phpRequirement'), data.php_requirement || '—')}
              {stat(t('composer.packages'), data.lock.packages)}
              {stat(t('composer.devPackages'), data.lock.dev_packages)}
              {stat(t('composer.treeDepth'), data.lock.depth)}
              {stat(t('composer.vendorAutoload'), data.vendor_present ? t('composer.present') : t('composer.missing'))}
              {stat(t('composer.pluginApi'), data.lock.plugin_api_version || '—')}
              {stat('content-hash', (data.lock.content_hash || '—').slice(0, 12))}
            </CardBody>
          </Card>

          <Card className="stagger-3">
            <CardHeader>
              <div className="text-sm font-semibold text-fg">{t('composer.actionsTitle')}</div>
              <div className="text-xs text-fg-subtle">{t('composer.installHint')}</div>
            </CardHeader>
            <CardBody className="space-y-4">
              <div className="flex flex-wrap gap-2">
                <Button
                  variant="secondary"
                  disabled={!data.available}
                  loading={action.isPending && action.variables === 'install'}
                  onClick={() => action.mutate('install')}
                >
                  <Download size={16} />
                  {t('composer.install')}
                </Button>
                <Button
                  variant="secondary"
                  disabled={!data.available}
                  loading={action.isPending && action.variables === 'update'}
                  onClick={() => action.mutate('update')}
                >
                  <RefreshCw size={16} />
                  {t('composer.update')}
                </Button>
              </div>
              <div className="flex flex-wrap gap-2 items-end">
                <div className="flex-1 min-w-[220px]">
                  <label className="text-xs text-fg-muted block mb-1">{t('composer.packageName')}</label>
                  <Input
                    value={pkg}
                    onChange={(e) => setPkg(e.target.value)}
                    placeholder={t('composer.packagePlaceholder')}
                  />
                </div>
                <Button
                  variant="primary"
                  disabled={!data.available || pkg.trim() === ''}
                  loading={action.isPending && action.variables === 'require'}
                  onClick={() => action.mutate('require')}
                >
                  <Plus size={16} />
                  {t('composer.require')}
                </Button>
              </div>
            </CardBody>
          </Card>

          <Card className="stagger-4">
            <CardHeader>
              <div className="text-sm font-semibold text-fg flex items-center gap-2">
                <Terminal size={16} />
                {t('composer.log')}
              </div>
            </CardHeader>
            <CardBody>
              {log ? (
                <pre className="text-xs font-mono whitespace-pre-wrap max-h-72 overflow-auto text-fg-muted">
                  {log}
                </pre>
              ) : (
                <div className="text-xs text-fg-subtle">{t('composer.emptyLog')}</div>
              )}
            </CardBody>
          </Card>
        </>
      ) : null}
    </div>
  )
}
