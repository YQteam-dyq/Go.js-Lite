import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ShieldCheck, ShieldX, Clock, AlertTriangle, Inbox, Send } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Modal } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonTable } from '@/components/ui/Skeleton'
import { toast } from '@/components/ui/Toast'
import { approvalsApi, type ApprovalRecord } from '@/api/approvals'
import { useI18n } from '@/hooks/useI18n'
import { useFormat } from '@/lib/format'
import { resolveErrorText } from '@/lib/errorMessages'

type Tab = 'pending' | 'mine'

function statusVariant(status: string): 'warning' | 'success' | 'danger' | 'muted' {
  if (status === 'approved') return 'success'
  if (status === 'pending') return 'warning'
  if (status === 'denied') return 'danger'
  return 'muted'
}

export default function Approvals() {
  const { t } = useI18n()
  const { formatDate, formatRelativeTime } = useFormat()
  const queryClient = useQueryClient()

  const [tab, setTab] = useState<Tab>('pending')
  const [deciding, setDeciding] = useState<{ row: ApprovalRecord; decision: 'approve' | 'deny' } | null>(null)
  const [reason, setReason] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['approvals'],
    queryFn: () => approvalsApi.list(),
    refetchInterval: 20_000,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['approvals'] })

  const decideMutation = useMutation({
    mutationFn: () => {
      if (!deciding) return Promise.reject(new Error('no target'))
      return deciding.decision === 'approve'
        ? approvalsApi.approve(deciding.row.id, reason)
        : approvalsApi.deny(deciding.row.id, reason)
    },
    onSuccess: () => {
      toast({
        type: 'success',
        title: deciding?.decision === 'approve' ? t('approvals.approved') : t('approvals.denied'),
      })
      setDeciding(null)
      setReason('')
      invalidate()
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.failure'), description: resolveErrorText(err) })
    },
  })

  const rows = tab === 'pending' ? data?.pending || [] : data?.mine || []
  const noSecondAdmin = (data?.admin_count ?? 2) < 2

  const tabs: Array<{ key: Tab; label: string; icon: React.ReactNode; count: number }> = [
    { key: 'pending', label: t('approvals.tabPending'), icon: <Inbox size={16} />, count: data?.pending.length || 0 },
    { key: 'mine', label: t('approvals.tabMine'), icon: <Send size={16} />, count: data?.mine.length || 0 },
  ]

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-5xl mx-auto page-enter">
      <div className="stagger-1">
        <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
          <ShieldCheck size={20} className="text-accent" />
          {t('approvals.title')}
        </h1>
        <p className="text-sm text-fg-muted mt-0.5">
          {t('approvals.subtitle', { minutes: Math.round((data?.ttl_seconds ?? 3600) / 60) })}
        </p>
      </div>

      {noSecondAdmin && (
        <div className="stagger-2 flex items-start gap-2 text-sm text-warning bg-warning/10 border border-warning/30 rounded-lg px-3 py-2">
          <AlertTriangle size={16} className="mt-0.5 shrink-0" />
          <span>{t('approvals.singleAdminWarning')}</span>
        </div>
      )}

      <div className="stagger-2 flex items-center gap-2">
        {tabs.map((item) => (
          <button
            key={item.key}
            type="button"
            onClick={() => setTab(item.key)}
            className={`flex items-center gap-2 h-10 px-4 rounded-lg border text-sm transition-colors ${
              tab === item.key
                ? 'border-accent bg-accent/10 text-accent font-medium'
                : 'border-border text-fg-muted hover:text-fg hover:bg-bg-sunken/50'
            }`}
          >
            {item.icon}
            {item.label}
            {item.count > 0 && <Badge variant="muted">{item.count}</Badge>}
          </button>
        ))}
      </div>

      <Card className="stagger-3 card-hover">
        <CardHeader>
          <div className="text-sm font-semibold text-fg">
            {tab === 'pending' ? t('approvals.pendingTitle') : t('approvals.mineTitle')}
          </div>
          <div className="text-xs text-fg-subtle">{t('approvals.totalRows', { count: rows.length })}</div>
        </CardHeader>
        <CardBody>
          {isLoading ? (
            <SkeletonTable rows={3} columns={4} />
          ) : rows.length === 0 ? (
            <EmptyState
              title={tab === 'pending' ? t('approvals.emptyPending') : t('approvals.emptyMine')}
              description={t('approvals.emptyDesc')}
            />
          ) : (
            <div className="overflow-x-auto -mx-2">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-fg-subtle border-b border-border">
                    <th className="px-3 py-2 font-medium">{t('approvals.action')}</th>
                    <th className="px-3 py-2 font-medium">{t('approvals.requester')}</th>
                    <th className="px-3 py-2 font-medium">{t('approvals.status')}</th>
                    <th className="px-3 py-2 font-medium">{t('approvals.expiresAt')}</th>
                    <th className="px-3 py-2 font-medium text-right">{t('common.actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id} className="border-b border-border/40 hover:bg-bg-sunken/40">
                      <td className="px-3 py-3">
                        <code className="font-mono text-xs text-fg">{row.action}</code>
                        <div className="text-[10px] text-fg-subtle mt-0.5">{row.method} /{row.api}</div>
                      </td>
                      <td className="px-3 py-3 text-fg-muted text-xs">{row.requester}</td>
                      <td className="px-3 py-3">
                        <Badge variant={statusVariant(row.status)}>{t(`approvals.status_${row.status}`)}</Badge>
                        {row.reason && (
                          <div className="text-[10px] text-fg-subtle mt-1 max-w-[12rem] truncate" title={row.reason}>
                            {row.reason}
                          </div>
                        )}
                      </td>
                      <td className="px-3 py-3 text-fg-muted text-xs" title={formatDate(row.expires_at)}>
                        {row.status === 'pending' ? (
                          <span className="inline-flex items-center gap-1">
                            <Clock size={12} />
                            {formatRelativeTime(row.expires_at)}
                          </span>
                        ) : (
                          formatDate(row.decided_at || row.expires_at)
                        )}
                      </td>
                      <td className="px-3 py-3 text-right whitespace-nowrap">
                        {tab === 'pending' && row.status === 'pending' ? (
                          <>
                            <Button
                              size="sm"
                              variant="primary"
                              onClick={() => { setDeciding({ row, decision: 'approve' }); setReason('') }}
                            >
                              <ShieldCheck size={14} />
                              {t('approvals.approve')}
                            </Button>
                            <Button
                              size="sm"
                              variant="ghost"
                              className="ml-1 text-danger hover:text-danger"
                              onClick={() => { setDeciding({ row, decision: 'deny' }); setReason('') }}
                            >
                              <ShieldX size={14} />
                              {t('approvals.deny')}
                            </Button>
                          </>
                        ) : (
                          <span className="text-xs text-fg-subtle">—</span>
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

      <Modal
        open={deciding !== null}
        onClose={() => { setDeciding(null); setReason('') }}
        title={deciding?.decision === 'approve' ? t('approvals.confirmApproveTitle') : t('approvals.confirmDenyTitle')}
        size="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => { setDeciding(null); setReason('') }}>
              {t('common.cancel')}
            </Button>
            <Button
              variant={deciding?.decision === 'approve' ? 'primary' : 'danger'}
              onClick={() => decideMutation.mutate()}
              loading={decideMutation.isPending}
            >
              {deciding?.decision === 'approve' ? t('approvals.approve') : t('approvals.deny')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <div className="text-sm text-fg-muted">
            {t('approvals.confirmBody', { action: deciding?.row.action || '' })}
          </div>
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('approvals.reason')}</label>
            <Input value={reason} onChange={(e) => setReason(e.target.value)} autoComplete="off" />
          </div>
        </div>
      </Modal>
    </div>
  )
}
