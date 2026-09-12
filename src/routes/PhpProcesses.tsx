import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Cpu, RefreshCw, Camera, FileText } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/Alert'
import { EmptyState } from '@/components/ui/EmptyState'
import { Modal } from '@/components/ui/Modal'
import { SkeletonTable } from '@/components/ui/Skeleton'
import { toast } from '@/components/ui/Toast'
import { phpProcessesApi, type PhpProcess } from '@/api/phpProcesses'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'

export default function PhpProcesses() {
  const { t } = useI18n()
  const [snapshotOpen, setSnapshotOpen] = useState(false)
  const [snapshotText, setSnapshotText] = useState('')

  const { data, isLoading, refetch, isFetching } = useQuery({
    queryKey: ['php-processes'],
    queryFn: () => phpProcessesApi.list(),
  })

  const snapshotMutation = useMutation({
    mutationFn: () => phpProcessesApi.snapshot(),
    onSuccess: async (res) => {
      toast({ type: 'success', title: t('phpProcesses.snapshotDone', { bytes: res.bytes }) })
      try {
        const content = await phpProcessesApi.snapshotContent()
        setSnapshotText(content.content)
        setSnapshotOpen(true)
      } catch {
        setSnapshotOpen(false)
      }
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.failure'), description: resolveErrorText(err) })
    },
  })

  const viewSnapshot = useMutation({
    mutationFn: () => phpProcessesApi.snapshotContent(),
    onSuccess: (res) => {
      setSnapshotText(res.content)
      setSnapshotOpen(true)
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.notFound'), description: resolveErrorText(err) })
    },
  })

  const rows: PhpProcess[] = data?.processes || []

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-5xl mx-auto page-enter">
      <div className="stagger-1 flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <Cpu size={20} className="text-accent" />
            {t('phpProcesses.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">
            {t('phpProcesses.subtitle', { sapi: data?.sapi ?? '—', os: data?.os ?? '—' })}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="ghost" onClick={() => refetch()} loading={isFetching}>
            <RefreshCw size={16} />
            {t('common.refresh')}
          </Button>
          <Button
            variant="secondary"
            loading={snapshotMutation.isPending}
            onClick={() => snapshotMutation.mutate()}
          >
            <Camera size={16} />
            {t('phpProcesses.snapshot')}
          </Button>
          <Button variant="ghost" loading={viewSnapshot.isPending} onClick={() => viewSnapshot.mutate()}>
            <FileText size={16} />
            {t('phpProcesses.viewSnapshot')}
          </Button>
        </div>
      </div>

      {!isLoading && data && !data.supported && (
        <Alert variant="warning" className="stagger-2">
          <AlertTitle>{t('phpProcesses.unsupported')}</AlertTitle>
          <AlertDescription>{t('phpProcesses.unsupportedHint')}</AlertDescription>
        </Alert>
      )}

      <Card className="stagger-2">
        <CardHeader>
          <div className="text-sm font-semibold text-fg">{t('phpProcesses.listTitle')}</div>
          <div className="text-xs text-fg-subtle">
            {t('phpProcesses.count')}: {data?.count ?? 0} · {data?.php_binary}
          </div>
        </CardHeader>
        <CardBody>
          {isLoading ? (
            <SkeletonTable rows={4} columns={5} />
          ) : rows.length === 0 ? (
            <EmptyState title={t('phpProcesses.empty')} description={t('phpProcesses.emptyDesc')} />
          ) : (
            <div className="overflow-x-auto -mx-2">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-fg-subtle border-b border-border">
                    <th className="px-3 py-2 font-medium">PID</th>
                    <th className="px-3 py-2 font-medium">{t('phpProcesses.user')}</th>
                    <th className="px-3 py-2 font-medium">{t('phpProcesses.mem')}</th>
                    <th className="px-3 py-2 font-medium">{t('phpProcesses.cpu')}</th>
                    <th className="px-3 py-2 font-medium">{t('phpProcesses.elapsed')}</th>
                    <th className="px-3 py-2 font-medium">{t('phpProcesses.cmdline')}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((p) => (
                    <tr key={p.pid} className="border-b border-border/40 hover:bg-bg-sunken/40">
                      <td className="px-3 py-2 font-mono text-fg">
                        {p.pid}
                        {p.pid === data?.self_pid && <Badge variant="accent" className="ml-2">{t('phpProcesses.selfPid')}</Badge>}
                      </td>
                      <td className="px-3 py-2 text-fg-muted">{p.user || '—'}</td>
                      <td className="px-3 py-2 text-fg-muted font-mono">
                        {p.mem_kb !== null ? `${p.mem_kb} KB` : p.mem_percent !== null ? `${p.mem_percent}%` : '—'}
                      </td>
                      <td className="px-3 py-2 text-fg-muted font-mono">
                        {p.cpu_percent === null ? '—' : `${p.cpu_percent}%`}
                      </td>
                      <td className="px-3 py-2 text-fg-muted font-mono">{p.elapsed || '—'}</td>
                      <td className="px-3 py-2 text-fg-muted text-xs font-mono max-w-md truncate" title={p.cmdline}>
                        {p.cmdline}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      <Modal
        open={snapshotOpen}
        onClose={() => setSnapshotOpen(false)}
        title={t('phpProcesses.snapshotTitle')}
        size="lg"
      >
        <pre className="text-xs font-mono whitespace-pre-wrap max-h-[60vh] overflow-auto text-fg-muted">
          {snapshotText}
        </pre>
      </Modal>
    </div>
  )
}
