import { useCallback, useEffect, useRef, useState } from 'react'
import {
  PackageCheck,
  RefreshCw,
  AlertTriangle,
  CheckCircle2,
  XCircle,
  RotateCcw,
  ExternalLink,
} from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Spinner } from '@/components/ui/Spinner'
import { toast } from '@/components/ui/Toast'
import { useI18n } from '@/hooks/useI18n'
import { useDocumentTitle } from '@/hooks/useDocumentTitle'
import { upgradeApi } from '@/api/upgrade'
import type { UpgradeCheckResult, UpgradeProgress } from '@shared/types'

const STEP_KEYS: Record<string, string> = {
  download: 'upgrade.stepDownload',
  backup: 'upgrade.stepBackup',
  extract: 'upgrade.stepExtract',
  migrate: 'upgrade.stepMigrate',
  done: 'upgrade.stepDone',
  error: 'upgrade.stepError',
}

type UpgradeStep = NonNullable<UpgradeProgress['step']>

const STEP_ORDER: UpgradeStep[] = ['download', 'backup', 'extract', 'migrate']

function formatReleaseDate(value?: string): string {
  if (!value) return '—'
  const ts = Date.parse(value)
  if (Number.isNaN(ts)) return value
  const d = new Date(ts)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

export default function Upgrade() {
  const { t } = useI18n()
  useDocumentTitle('upgrade.documentTitle')

  const [checking, setChecking] = useState(false)
  const [checkResult, setCheckResult] = useState<UpgradeCheckResult | null>(null)
  const [checkError, setCheckError] = useState('')

  const [applying, setApplying] = useState(false)
  const [progress, setProgress] = useState<UpgradeProgress | null>(null)
  const [failed, setFailed] = useState('')
  const [done, setDone] = useState(false)
  const pollRef = useRef<number | null>(null)

  const stopPolling = useCallback(() => {
    if (pollRef.current !== null) {
      window.clearInterval(pollRef.current)
      pollRef.current = null
    }
  }, [])

  useEffect(() => {
    return () => stopPolling()
  }, [stopPolling])

  const handleCheck = async () => {
    setChecking(true)
    setCheckError('')
    try {
      const res = await upgradeApi.check()
      setCheckResult(res)
    } catch (e) {
      setCheckResult(null)
      setCheckError(e instanceof Error ? e.message : t('upgrade.checkFailed'))
    } finally {
      setChecking(false)
    }
  }

  const poll = useCallback(() => {
    pollRef.current = window.setInterval(async () => {
      try {
        const p = await upgradeApi.progress()
        if (!p.step) return
        setProgress(p)
        if (p.step === 'done') {
          stopPolling()
          setDone(true)
          setApplying(false)
          toast({ type: 'success', title: t('upgrade.doneMessage') })
        } else if (p.step === 'error') {
          stopPolling()
          setApplying(false)
          const msg = p.error || t('upgrade.errorMessage')
          setFailed(msg)
          toast({ type: 'error', title: t('upgrade.errorMessage'), description: msg })
        }
      } catch {
      }
    }, 2000)
  }, [stopPolling, t])

  const handleApply = async () => {
    setApplying(true)
    setFailed('')
    setProgress({ step: 'download', message_key: 'upgrade.stepDownload', percent: 5 })
    setDone(false)
    poll()
    try {
      await upgradeApi.apply()
    } catch (e) {
      stopPolling()
      setApplying(false)
      const msg = e instanceof Error ? e.message : t('upgrade.errorMessage')
      setFailed(msg)
      toast({ type: 'error', title: t('upgrade.errorMessage'), description: msg })
    }
  }

  const currentVersion = checkResult?.current_version || '0.6.0'
  const latestVersion = checkResult?.latest_version || '—'
  const updateAvailable = checkResult?.update_available === true
  const progressStep = progress?.step ?? null
  const percent = progress?.percent ?? 0

  return (
    <div className="p-4 md:p-6 space-y-5">
      <Card>
        <CardHeader className="flex items-center justify-between gap-2">
          <div className="flex items-center gap-2 min-w-0">
            <PackageCheck size={16} className="text-fg-muted shrink-0" />
            <div className="min-w-0">
              <div className="text-sm font-medium text-fg">{t('upgrade.pageTitle')}</div>
              <div className="text-xs text-fg-subtle">{t('upgrade.description')}</div>
            </div>
          </div>
          <Button
            size="sm"
            onClick={handleCheck}
            disabled={checking || applying}
            className="shrink-0"
          >
            {checking ? <Spinner size="sm" className="mr-2" /> : <RefreshCw size={14} className="mr-2" />}
            {checking ? t('upgrade.checking') : t('upgrade.checkNow')}
          </Button>
        </CardHeader>
        <CardBody>
          <div className="flex items-center gap-6 flex-wrap">
            <div>
              <div className="text-xs text-fg-muted">{t('upgrade.currentVersion')}</div>
              <div className="text-lg font-semibold mt-1">v{currentVersion}</div>
            </div>
            <div>
              <div className="text-xs text-fg-muted">{t('upgrade.latestVersion')}</div>
              <div className="text-lg font-semibold mt-1">v{latestVersion}</div>
            </div>
            {!checkResult && !checkError && <Badge variant="muted">—</Badge>}
            {checkResult && !updateAvailable && <Badge variant="success">{t('upgrade.noUpdate')}</Badge>}
            {checkResult && updateAvailable && <Badge variant="warning">{t('upgrade.updateAvailable')}</Badge>}
          </div>

          {checkResult && (
            <div className="mt-4 pt-4 border-t border-border space-y-1.5">
              <div className="text-sm text-fg">
                <span className="text-xs text-fg-muted mr-2">{t('upgrade.releaseDate')}</span>
                {formatReleaseDate(checkResult.published_at)}
              </div>
              {checkResult.release_name && checkResult.release_name !== '' && (
                <div className="text-sm text-fg break-all">{checkResult.release_name}</div>
              )}
            </div>
          )}

          {checkError && (
            <div className="mt-4 flex items-start gap-2.5 rounded-lg border border-danger/30 bg-danger/10 p-3">
              <XCircle size={16} className="shrink-0 mt-0.5 text-danger" />
              <div className="text-xs text-fg leading-relaxed">
                <span className="font-semibold text-danger">{t('upgrade.checkFailed')}：</span>
                {checkError}
              </div>
            </div>
          )}

          {updateAvailable && !applying && !done && (
            <div className="mt-4 space-y-3">
              <div className="flex items-start gap-2.5 rounded-lg border border-warning/30 bg-warning/10 p-3">
                <AlertTriangle size={16} className="shrink-0 mt-0.5 text-warning" />
                <div className="text-xs text-fg leading-relaxed">{t('upgrade.backupNotice')}</div>
              </div>
              <Button onClick={handleApply}>
                <ExternalLink size={14} className="mr-2" />
                {t('upgrade.startUpgrade')}
              </Button>
            </div>
          )}

          {(applying || progress || failed || done) && (
            <div className="mt-4 pt-4 border-t border-border space-y-3">
              <div className="flex items-center justify-between">
                <div className="text-xs text-fg-muted font-medium">{t('upgrade.progress')}</div>
                <div className="text-xs text-fg-muted tabular-nums">{percent}%</div>
              </div>

              <div className="h-2 rounded-full bg-bg-sunken overflow-hidden">
                <div
                  className={`h-full rounded-full transition-all duration-500 ${
                    failed ? 'bg-danger' : 'bg-accent'
                  }`}
                  style={{ width: `${Math.min(100, Math.max(0, percent))}%` }}
                />
              </div>

              <div className="space-y-1.5">
                {STEP_ORDER.map((step) => {
                  const stepState = (): 'done' | 'active' | 'pending' => {
                    if (progressStep === null || progressStep === 'error') return 'pending'
                    if (progressStep === 'done') return 'done'
                    const cur = STEP_ORDER.indexOf(progressStep)
                    const idx = STEP_ORDER.indexOf(step)
                    if (idx < cur) return 'done'
                    if (idx === cur) return 'active'
                    return 'pending'
                  }
                  const state = stepState()
                  return (
                    <div key={step} className="flex items-center gap-2 text-sm">
                      {state === 'done' && <CheckCircle2 size={15} className="text-success shrink-0" />}
                      {state === 'active' && <Spinner size="sm" className="text-accent shrink-0" />}
                      {state === 'pending' && <span className="w-[15px] shrink-0" />}
                      <span
                        className={
                          state === 'done' ? 'text-fg' : state === 'active' ? 'text-fg font-medium' : 'text-fg-subtle'
                        }
                      >
                        {t(STEP_KEYS[step])}
                      </span>
                    </div>
                  )
                })}
              </div>

              {failed && (
                <div className="flex items-start gap-2.5 rounded-lg border border-danger/30 bg-danger/10 p-3">
                  <XCircle size={16} className="shrink-0 mt-0.5 text-danger" />
                  <div className="text-xs text-fg leading-relaxed">{failed}</div>
                </div>
              )}

              {done && (
                <div className="flex items-start gap-2.5 rounded-lg border border-success/30 bg-success/10 p-3">
                  <CheckCircle2 size={16} className="shrink-0 mt-0.5 text-success" />
                  <div className="text-xs text-fg leading-relaxed">{t('upgrade.doneMessage')}</div>
                </div>
              )}

              <div className="flex gap-2">
                {done && (
                  <Button size="sm" onClick={() => window.location.reload()}>
                    <RotateCcw size={14} className="mr-2" />
                    {t('upgrade.refreshNow')}
                  </Button>
                )}
                {failed && !done && (
                  <Button size="sm" variant="secondary" onClick={handleApply} loading={applying}>
                    <RefreshCw size={14} className="mr-2" />
                    {t('common.retry')}
                  </Button>
                )}
                {applying && (
                  <Button size="sm" variant="secondary" disabled loading>
                    {t('upgrade.upgrading')}
                  </Button>
                )}
              </div>
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  )
}
