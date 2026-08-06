import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Rocket, Globe, Feather, Download, Database, FolderOpen, CheckCircle2, AlertCircle } from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Skeleton } from '@/components/ui/Skeleton'
import { Modal } from '@/components/ui/Modal'
import { EmptyError } from '@/components/ui/EmptyState'
import { deployApi } from '@/api/deploy'
import { toast } from '@/components/ui/Toast'
import { useI18n } from '@/hooks/useI18n'
import { useDocumentTitle } from '@/hooks/useDocumentTitle'
import type { DeployAppInfo, DeployRunResult } from '@shared/types'

const APP_ICONS: Record<string, React.ComponentType<{ size?: number | string; className?: string }>> = {
  wordpress: Globe,
  typecho: Feather,
}

export default function Deploy() {
  const { t } = useI18n()
  useDocumentTitle('deploy.documentTitle')

  const appsQuery = useQuery({
    queryKey: ['deploy', 'apps'],
    queryFn: () => deployApi.apps(),
  })

  const [selected, setSelected] = useState<DeployAppInfo | null>(null)
  const [targetDir, setTargetDir] = useState('')
  const [dbHost, setDbHost] = useState('')
  const [dbName, setDbName] = useState('')
  const [dbUser, setDbUser] = useState('')
  const [dbPass, setDbPass] = useState('')
  const [dbPrefix, setDbPrefix] = useState('')
  const [overwrite, setOverwrite] = useState(false)
  const [deploying, setDeploying] = useState(false)
  const [result, setResult] = useState<DeployRunResult | null>(null)

  const openModal = (app: DeployAppInfo) => {
    setSelected(app)
    setTargetDir(`/$$app.id}`)
    setDbHost('')
    setDbName('')
    setDbUser('')
    setDbPass('')
    setDbPrefix('')
    setOverwrite(false)
    setResult(null)
  }

  const closeModal = () => {
    if (deploying) return
    setSelected(null)
    setResult(null)
  }

  const handleSubmit = async () => {
    if (!selected) return
    setDeploying(true)
    setResult(null)
    try {
      const res = await deployApi.run({
        app_id: selected.id,
        target_dir: targetDir,
        db_host: dbHost || undefined,
        db_name: dbName || undefined,
        db_user: dbUser || undefined,
        db_pass: dbPass || undefined,
        db_prefix: dbPrefix || undefined,
        overwrite,
      })
      setResult(res)
      toast({ type: 'success', title: t('deploy.deploySuccess') })
    } catch (e) {
      toast({
        type: 'error',
        title: t('deploy.deployFailed'),
        description: e instanceof Error ? e.message : undefined,
      })
    } finally {
      setDeploying(false)
    }
  }

  const resultIcon = result?.db_configured ? CheckCircle2 : AlertCircle

  return (
    <div className="p-4 md:p-6 space-y-5">
      <Card>
        <CardHeader className="flex items-center justify-between gap-2">
          <div className="flex items-center gap-2 min-w-0">
            <Rocket size={16} className="text-fg-muted shrink-0" />
            <div className="min-w-0">
              <div className="text-sm font-medium text-fg">{t('deploy.pageTitle')}</div>
              <div className="text-xs text-fg-subtle">{t('deploy.description')}</div>
            </div>
          </div>
          <Badge variant="muted" className="shrink-0">
            {t('deploy.apps')}
          </Badge>
        </CardHeader>
        <CardBody>
          {appsQuery.isLoading && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {[0, 1].map((i) => (
                <div key={i} className="rounded-xl border border-border p-4 space-y-2">
                  <Skeleton className="h-10 w-10 rounded-full" />
                  <Skeleton className="h-4 w-32" />
                  <Skeleton className="h-3 w-full" />
                  <Skeleton className="h-8 w-20" />
                </div>
              ))}
            </div>
          )}

          {appsQuery.isError && (
            <EmptyError error={appsQuery.error instanceof Error ? appsQuery.error.message : undefined} onRetry={() => appsQuery.refetch()} />
          )}

          {appsQuery.data && appsQuery.data.length === 0 && (
            <div className="py-12 text-center">
              <p className="text-sm text-fg-muted">{t('deploy.noApps')}</p>
            </div>
          )}

          {appsQuery.data && appsQuery.data.length > 0 && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {appsQuery.data.map((app) => {
                const Icon = APP_ICONS[app.id] ?? Rocket
                return (
                  <div
                    key={app.id}
                    className="flex items-start justify-between gap-3 rounded-xl border border-border p-4 hover:border-accent/40 transition-colors"
                  >
                    <div className="flex items-start gap-3 min-w-0">
                      <div className="w-10 h-10 rounded-xl bg-accent/10 text-accent flex items-center justify-center shrink-0">
                        <Icon size={20} />
                      </div>
                      <div className="min-w-0">
                        <div className="flex items-center gap-2">
                          <span className="text-sm font-medium text-fg">{t(app.name_key)}</span>
                          <Badge variant="muted">{app.version}</Badge>
                        </div>
                        <p className="text-xs text-fg-subtle mt-1 leading-relaxed">
                          {t(app.description_key)}
                        </p>
                      </div>
                    </div>
                    <Button size="sm" variant="secondary" className="shrink-0" onClick={() => openModal(app)}>
                      <Download size={14} className="mr-1.5" />
                      {t('deploy.deploy')}
                    </Button>
                  </div>
                )
              })}
            </div>
          )}
        </CardBody>
      </Card>

      <Modal
        open={selected !== null}
        onClose={closeModal}
        size="md"
        title={
          selected ? (
            <span className="flex items-center gap-2">
              {(() => {
                const Icon = selected ? (APP_ICONS[selected.id] ?? Rocket) : Rocket
                return <Icon size={18} className="text-accent" />
              })()}
              {t('deploy.deploy')} · {t(selected.name_key)}
            </span>
          ) : undefined
        }
        footer={
          result ? (
            <Button variant="secondary" onClick={closeModal}>
              {t('common.close')}
            </Button>
          ) : (
            <>
              <Button variant="secondary" onClick={closeModal} disabled={deploying}>
                {t('common.cancel')}
              </Button>
              <Button onClick={handleSubmit} loading={deploying}>
                {deploying ? t('deploy.deploying') : t('deploy.deploy')}
              </Button>
            </>
          )
        }
      >
        {selected && (
          <div className="space-y-4">
            {result ? (
              <div className="space-y-3">
                <div className="flex items-start gap-2.5 rounded-lg border border-success/30 bg-success/10 p-3">
                  {(() => {
                    const Icon = resultIcon
                    return <Icon size={16} className="shrink-0 mt-0.5 text-success" />
                  })()}
                  <div className="text-xs text-fg leading-relaxed">
                    <div className="font-semibold">{t(result.next_step_key)}</div>
                    <div className="mt-1">{t('deploy.deployingTo', { dir: `/$$result.target_dir}` })}</div>
                    {result.db_configured === false && (
                      <div className="mt-1 opacity-80">{t('deploy.dbOptional')}</div>
                    )}
                  </div>
                </div>
                <div className="flex items-start gap-2.5 rounded-lg border border-border bg-bg-sunken p-3">
                  <FolderOpen size={16} className="shrink-0 mt-0.5 text-fg-muted" />
                  <div className="text-xs text-fg leading-relaxed break-all">
                    {t('deploy.targetDir')}：<span className="font-mono">/{result.target_dir}</span>
                  </div>
                </div>
              </div>
            ) : (
              <>
                <div>
                  <label className="text-xs text-fg-muted font-medium">{t('deploy.targetDir')}</label>
                  <Input
                    className="mt-1.5"
                    value={targetDir}
                    onChange={(e) => setTargetDir(e.target.value)}
                    placeholder={`/$$selected.id}`}
                    icon={<FolderOpen size={15} />}
                  />
                  <p className="text-[11px] text-fg-subtle mt-1">
                    {t('deploy.targetHint', { app: selected.id })}
                  </p>
                </div>

                {selected.db_required && (
                  <div className="space-y-3 rounded-xl border border-border p-3">
                    <div className="flex items-center gap-2 text-xs font-medium text-fg-muted">
                      <Database size={14} />
                      {t('deploy.dbOptional')}
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                      <div>
                        <label className="text-xs text-fg-muted">{t('deploy.dbHost')}</label>
                        <Input
                          className="mt-1"
                          value={dbHost}
                          onChange={(e) => setDbHost(e.target.value)}
                          placeholder="localhost"
                        />
                      </div>
                      <div>
                        <label className="text-xs text-fg-muted">{t('deploy.dbName')}</label>
                        <Input
                          className="mt-1"
                          value={dbName}
                          onChange={(e) => setDbName(e.target.value)}
                        />
                      </div>
                      <div>
                        <label className="text-xs text-fg-muted">{t('deploy.dbUser')}</label>
                        <Input
                          className="mt-1"
                          value={dbUser}
                          onChange={(e) => setDbUser(e.target.value)}
                        />
                      </div>
                      <div>
                        <label className="text-xs text-fg-muted">{t('deploy.dbPass')}</label>
                        <Input
                          type="password"
                          className="mt-1"
                          value={dbPass}
                          onChange={(e) => setDbPass(e.target.value)}
                        />
                      </div>
                      <div className="sm:col-span-2">
                        <label className="text-xs text-fg-muted">{t('deploy.dbPrefix')}</label>
                        <Input
                          className="mt-1"
                          value={dbPrefix}
                          onChange={(e) => setDbPrefix(e.target.value)}
                        />
                      </div>
                    </div>
                  </div>
                )}

                <label className="flex items-start gap-2.5 text-xs text-fg-muted cursor-pointer select-none">
                  <input
                    type="checkbox"
                    className="mt-0.5 accent-[var(--accent)]"
                    checked={overwrite}
                    onChange={(e) => setOverwrite(e.target.checked)}
                  />
                  <span>{t('deploy.overwrite')}</span>
                </label>
              </>
            )}
          </div>
        )}
      </Modal>
    </div>
  )
}
