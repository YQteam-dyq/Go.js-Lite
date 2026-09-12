import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileCog, Save, RefreshCw, AlertTriangle } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/Alert'
import { Input, Textarea } from '@/components/ui/Input'
import { SkeletonTable } from '@/components/ui/Skeleton'
import { toast } from '@/components/ui/Toast'
import { phpIniApi, type JitMode, type IniSeverity } from '@/api/phpIni'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'

const SEVERITY_VARIANT: Record<IniSeverity, 'muted' | 'warning' | 'danger'> = {
  info: 'muted',
  warning: 'warning',
  danger: 'danger',
}

type TabKey = 'diff' | 'jit' | 'include'

export default function PhpIni() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [tab, setTab] = useState<TabKey>('diff')
  const [mismatchOnly, setMismatchOnly] = useState(false)
  const [jitMode, setJitMode] = useState<JitMode>('tracing')
  const [jitBuffer, setJitBuffer] = useState('128')
  const [pathsText, setPathsText] = useState('')
  const [runtimeWarning, setRuntimeWarning] = useState<string | null>(null)

  const diffQuery = useQuery({ queryKey: ['php-ini-diff'], queryFn: () => phpIniApi.diff() })
  const jitQuery = useQuery({ queryKey: ['php-jit'], queryFn: () => phpIniApi.jit() })
  const includeQuery = useQuery({ queryKey: ['php-include-path'], queryFn: () => phpIniApi.includePath() })

  useEffect(() => {
    if (jitQuery.data) {
      if (jitQuery.data.mode === 'tracing' || jitQuery.data.mode === 'function' || jitQuery.data.mode === 'none') {
        setJitMode(jitQuery.data.mode)
      }
      if (jitQuery.data.buffer_size_mb !== null) {
        setJitBuffer(String(jitQuery.data.buffer_size_mb))
      }
    }
  }, [jitQuery.data])

  useEffect(() => {
    if (!includeQuery.data) return
    const value = includeQuery.data.user_ini_include_path || includeQuery.data.current || ''
    const parts = String(value)
      .split(/[;:]/)
      .map((p) => p.trim())
      .filter((p) => p && p !== '.')
    setPathsText(parts.join('\n'))
  }, [includeQuery.data])

  const jitMutation = useMutation({
    mutationFn: () => phpIniApi.setJit(jitMode, Number(jitBuffer) || 0),
    onSuccess: (res) => {
      setRuntimeWarning(null)
      toast({ type: 'success', title: t('phpIni.jitSaved') })
      if (res.reload_required) setRuntimeWarning(t('phpIni.jitReload'))
      queryClient.invalidateQueries({ queryKey: ['php-jit'] })
    },
    onError: (err: Error) => {
      const message = resolveErrorText(err)
      setRuntimeWarning(message)
      toast({ type: 'error', title: t('phpIni.jitReload'), description: message })
    },
  })

  const includeMutation = useMutation({
    mutationFn: () =>
      phpIniApi.setIncludePath(
        pathsText
          .split('\n')
          .map((p) => p.trim())
          .filter(Boolean),
      ),
    onSuccess: (res) => {
      setRuntimeWarning(res.reload_required ? t('phpIni.includePathHint') : null)
      toast({ type: 'success', title: t('phpIni.includePathSaved') })
      queryClient.invalidateQueries({ queryKey: ['php-include-path'] })
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.saveFailed'), description: resolveErrorText(err) })
    },
  })

  const rows = (diffQuery.data?.rows || []).filter((r) => (mismatchOnly ? !r.match : true))

  const tabButton = (key: TabKey, label: string) => (
    <button
      key={key}
      type="button"
      onClick={() => setTab(key)}
      className={`px-3 py-1.5 rounded-lg text-sm border transition-colors ${
        tab === key ? 'border-accent bg-accent/10 text-accent' : 'border-border text-fg-muted hover:text-fg'
      }`}
    >
      {label}
    </button>
  )

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-5xl mx-auto page-enter">
      <div className="stagger-1 flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <FileCog size={20} className="text-accent" />
            {t('phpIni.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('phpIni.subtitle')}</p>
        </div>
        <Button variant="ghost" onClick={() => { diffQuery.refetch(); jitQuery.refetch(); includeQuery.refetch() }}>
          <RefreshCw size={16} />
          {t('common.refresh')}
        </Button>
      </div>

      <div className="stagger-2 flex flex-wrap gap-2">
        {tabButton('diff', t('phpIni.tabDiff'))}
        {tabButton('jit', t('phpIni.tabJit'))}
        {tabButton('include', t('phpIni.tabIncludePath'))}
      </div>

      {runtimeWarning && (
        <Alert variant="warning" className="stagger-2">
          <AlertTitle className="flex items-center gap-2">
            <AlertTriangle size={16} />
            {t('phpIni.reloadNeeded')}
          </AlertTitle>
          <AlertDescription>{runtimeWarning}</AlertDescription>
        </Alert>
      )}

      {tab === 'diff' && (
        <Card className="stagger-3">
          <CardHeader className="flex flex-wrap items-center gap-3">
            <div className="text-sm font-semibold text-fg">{t('phpIni.diffTitle')}</div>
            <label className="flex items-center gap-2 text-sm text-fg-muted">
              <input
                type="checkbox"
                checked={mismatchOnly}
                onChange={(e) => setMismatchOnly(e.target.checked)}
              />
              {t('phpIni.mismatchOnly')}
            </label>
            <div className="ml-auto text-xs text-fg-subtle">
              {t('phpIni.mismatch')}: {diffQuery.data?.mismatch ?? 0} / {diffQuery.data?.total ?? 0}
            </div>
          </CardHeader>
          <CardBody>
            {diffQuery.isLoading ? (
              <SkeletonTable rows={6} columns={4} />
            ) : (
              <div className="overflow-x-auto -mx-2">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-fg-subtle border-b border-border">
                      <th className="px-3 py-2 font-medium">{t('phpIni.directive')}</th>
                      <th className="px-3 py-2 font-medium">{t('phpIni.current')}</th>
                      <th className="px-3 py-2 font-medium">{t('phpIni.recommended')}</th>
                      <th className="px-3 py-2 font-medium">{t('phpIni.severity')}</th>
                      <th className="px-3 py-2 font-medium">{t('phpIni.note')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((r) => (
                      <tr key={r.directive} className="border-b border-border/40 hover:bg-bg-sunken/40">
                        <td className="px-3 py-2 font-mono text-fg">{r.directive}</td>
                        <td className="px-3 py-2 font-mono text-fg-muted">{r.current ?? '—'}</td>
                        <td className="px-3 py-2 font-mono text-fg-muted">{r.recommended}</td>
                        <td className="px-3 py-2">
                          {r.match ? (
                            <Badge variant="success">{t('phpIni.ok')}</Badge>
                          ) : (
                            <Badge variant={SEVERITY_VARIANT[r.severity]}>{r.severity}</Badge>
                          )}
                        </td>
                        <td className="px-3 py-2 text-xs text-fg-subtle max-w-md">{r.note}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <div className="mt-3 text-[11px] text-fg-subtle font-mono">
              loaded: {String(diffQuery.data?.loaded_file || '—')}
              <br />
              baseline: {String(diffQuery.data?.baseline_file || '—')}
            </div>
          </CardBody>
        </Card>
      )}

      {tab === 'jit' && (
        <Card className="stagger-3">
          <CardHeader>
            <div className="text-sm font-semibold text-fg">{t('phpIni.jitTitle')}</div>
            <div className="text-xs text-fg-subtle font-mono">
              {t('phpIni.current')}: {jitQuery.data?.raw_mode ?? '—'} / {jitQuery.data?.buffer_size ?? '—'}
            </div>
          </CardHeader>
          <CardBody className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3 max-w-xl">
              <div>
                <label className="text-xs text-fg-muted block mb-1">{t('phpIni.jitMode')}</label>
                <select
                  className="w-full h-10 rounded-lg border border-border bg-bg-elevated px-3 text-sm"
                  value={jitMode}
                  onChange={(e) => setJitMode(e.target.value as JitMode)}
                >
                  <option value="tracing">{t('phpIni.modeTracing')}</option>
                  <option value="function">{t('phpIni.modeFunction')}</option>
                  <option value="none">{t('phpIni.modeNone')}</option>
                </select>
              </div>
              <div>
                <label className="text-xs text-fg-muted block mb-1">{t('phpIni.jitBuffer')}</label>
                <Input
                  type="number"
                  min={0}
                  max={4096}
                  value={jitBuffer}
                  onChange={(e) => setJitBuffer(e.target.value)}
                />
              </div>
            </div>
            <div className="flex items-center gap-3">
              <Button variant="primary" loading={jitMutation.isPending} onClick={() => jitMutation.mutate()}>
                <Save size={16} />
                {t('common.save')}
              </Button>
              <span className="text-[11px] text-fg-subtle font-mono">{jitQuery.data?.user_ini_path}</span>
            </div>
          </CardBody>
        </Card>
      )}

      {tab === 'include' && (
        <Card className="stagger-3">
          <CardHeader>
            <div className="text-sm font-semibold text-fg">{t('phpIni.includePathTitle')}</div>
            <div className="text-xs text-fg-subtle font-mono">
              {t('phpIni.current')}: {String(includeQuery.data?.current ?? '—')}
            </div>
          </CardHeader>
          <CardBody className="space-y-3">
            {includeQuery.data && !includeQuery.data.writable && (
              <Alert variant="warning">
                <AlertTitle>{t('phpIni.notWritable')}</AlertTitle>
                <AlertDescription>{t('phpIni.notWritableHint')}</AlertDescription>
              </Alert>
            )}
            <Textarea
              rows={5}
              value={pathsText}
              onChange={(e) => setPathsText(e.target.value)}
              placeholder={t('phpIni.includePathPlaceholder')}
            />
            <div className="flex items-center gap-3">
              <Button
                variant="primary"
                loading={includeMutation.isPending}
                onClick={() => includeMutation.mutate()}
              >
                <Save size={16} />
                {t('common.save')}
              </Button>
              <span className="text-[11px] text-fg-subtle">{t('phpIni.includePathHint')}</span>
            </div>
          </CardBody>
        </Card>
      )}
    </div>
  )
}
