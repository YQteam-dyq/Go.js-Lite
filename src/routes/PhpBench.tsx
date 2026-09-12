import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Timer, Play, GitCompare } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Input } from '@/components/ui/Input'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/Alert'
import { EmptyState } from '@/components/ui/EmptyState'
import { toast } from '@/components/ui/Toast'
import { phpBenchApi, type BenchRunResponse, type BenchCompareResponse } from '@/api/phpBench'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'

export default function PhpBench() {
  const { t } = useI18n()
  const [iterations, setIterations] = useState('1000')
  const [run, setRun] = useState<BenchRunResponse | null>(null)
  const [compare, setCompare] = useState<BenchCompareResponse | null>(null)
  const [compareError, setCompareError] = useState<string | null>(null)

  const runMutation = useMutation({
    mutationFn: () => phpBenchApi.run(Number(iterations) || undefined),
    onSuccess: (res) => {
      setRun(res)
      setCompare(null)
      setCompareError(null)
      toast({ type: 'success', title: t('phpBench.runDone', { ms: res.duration_ms }) })
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.failure'), description: resolveErrorText(err) })
    },
  })

  const compareMutation = useMutation({
    mutationFn: () => phpBenchApi.compare(),
    onSuccess: (res) => {
      setCompare(res)
      setCompareError(null)
    },
    onError: (err: Error) => {
      setCompare(null)
      setCompareError(resolveErrorText(err))
    },
  })

  const maxAvg = Math.max(1, ...(run?.items || []).filter((i) => i.available).map((i) => i.avg_us || 0))

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-4xl mx-auto page-enter">
      <div className="stagger-1">
        <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
          <Timer size={20} className="text-accent" />
          {t('phpBench.title')}
        </h1>
        <p className="text-sm text-fg-muted mt-0.5">{t('phpBench.subtitle')}</p>
      </div>

      <Card className="stagger-2">
        <CardHeader className="flex flex-wrap items-end gap-3">
          <div className="w-40">
            <label className="text-xs text-fg-muted block mb-1">{t('phpBench.iterations')}</label>
            <Input
              type="number"
              min={1}
              max={100000}
              value={iterations}
              onChange={(e) => setIterations(e.target.value)}
            />
          </div>
          <Button variant="primary" loading={runMutation.isPending} onClick={() => runMutation.mutate()}>
            <Play size={16} />
            {t('phpBench.run')}
          </Button>
          <Button
            variant="secondary"
            loading={compareMutation.isPending}
            onClick={() => compareMutation.mutate()}
          >
            <GitCompare size={16} />
            {t('phpBench.compare')}
          </Button>
          {run && (
            <div className="text-xs text-fg-subtle ml-auto">
              {t('phpBench.duration')}: {run.duration_ms} ms · id {run.id}
            </div>
          )}
        </CardHeader>
        <CardBody>
          {!run ? (
            <EmptyState title={t('phpBench.empty')} description={t('phpBench.emptyDesc')} />
          ) : (
            <div className="space-y-2">
              {run.items.map((item) => (
                <div key={item.name} className="flex items-center gap-3">
                  <div className="w-32 shrink-0 text-xs font-mono text-fg-muted">{item.name}</div>
                  {item.available ? (
                    <>
                      <div className="flex-1 h-3 rounded bg-bg-sunken overflow-hidden">
                        <div
                          className="h-full bg-accent rounded"
                          style={{ width: `${((item.avg_us || 0) / maxAvg) * 100}%` }}
                        />
                      </div>
                      <div className="w-32 shrink-0 text-right text-xs font-mono text-fg">
                        {item.avg_us?.toFixed(2)} µs
                      </div>
                    </>
                  ) : (
                    <>
                      <div className="flex-1 text-xs text-fg-subtle truncate" title={item.reason || ''}>
                        {t('phpBench.unavailable')} — {item.reason}
                      </div>
                      <div className="w-32 shrink-0 text-right">
                        <Badge variant="muted">{t('phpBench.skipped')}</Badge>
                      </div>
                    </>
                  )}
                </div>
              ))}
            </div>
          )}
        </CardBody>
      </Card>

      {(compare || compareError) && (
        <Card className="stagger-3">
          <CardHeader>
            <div className="text-sm font-semibold text-fg">{t('phpBench.compareTitle')}</div>
            {compare && (
              <div className="text-xs text-fg-subtle font-mono">
                {compare.a.id} → {compare.b.id}
              </div>
            )}
          </CardHeader>
          <CardBody>
            {compareError ? (
              <Alert variant="warning">
                <AlertTitle>{t('phpBench.compareUnavailable')}</AlertTitle>
                <AlertDescription>{compareError}</AlertDescription>
              </Alert>
            ) : (
              <div className="overflow-x-auto -mx-2">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-fg-subtle border-b border-border">
                      <th className="px-3 py-2 font-medium">{t('phpBench.item')}</th>
                      <th className="px-3 py-2 font-medium text-right">A µs</th>
                      <th className="px-3 py-2 font-medium text-right">B µs</th>
                      <th className="px-3 py-2 font-medium text-right">{t('phpBench.diffPct')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(compare?.rows || []).map((row) => (
                      <tr key={row.name} className="border-b border-border/40">
                        <td className="px-3 py-2 font-mono text-fg">{row.name}</td>
                        <td className="px-3 py-2 text-right text-fg-muted">
                          {row.a_avg_us === null ? '—' : row.a_avg_us.toFixed(2)}
                        </td>
                        <td className="px-3 py-2 text-right text-fg-muted">
                          {row.b_avg_us === null ? '—' : row.b_avg_us.toFixed(2)}
                        </td>
                        <td className="px-3 py-2 text-right">
                          {row.diff_pct === null ? (
                            '—'
                          ) : (
                            <span className={row.diff_pct < 0 ? 'text-success' : row.diff_pct > 0 ? 'text-danger' : 'text-fg-muted'}>
                              {row.diff_pct > 0 ? '+' : ''}
                              {row.diff_pct.toFixed(2)}%
                            </span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardBody>
        </Card>
      )}
    </div>
  )
}
