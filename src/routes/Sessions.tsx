import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { LogOut, Shield, Power } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Confirm } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonTable } from '@/components/ui/Skeleton'
import { AvatarBadge } from '@/components/ui/AvatarBadge'
import { toast } from '@/components/ui/Toast'
import { usersApi } from '@/api/users'
import { isApprovalPending } from '@/api/client'
import { useFormat } from '@/lib/format'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'

export default function Sessions() {
  const { t } = useI18n()
  const { formatDate } = useFormat()
  const queryClient = useQueryClient()

  const [kicking, setKicking] = useState<string | null>(null)
  const [logoutAllOpen, setLogoutAllOpen] = useState(false)

  const { data, isLoading } = useQuery({
    queryKey: ['sessions'],
    queryFn: () => usersApi.sessions.list(),
    refetchInterval: 15_000,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['sessions'] })

  const kickMutation = useMutation({
    mutationFn: (sid: string) => usersApi.sessions.kick(sid),
    onSuccess: () => {
      toast({ type: 'success', title: t('sessions.kicked') })
      invalidate()
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('sessions.kickFailed'), description: resolveErrorText(err) })
    },
    onSettled: () => setKicking(null),
  })

  const logoutAllMutation = useMutation({
    mutationFn: () => usersApi.logoutAll(),
    onSuccess: () => {
      toast({ type: 'success', title: t('sessions.logoutAllDone') })
      setLogoutAllOpen(false)
      setTimeout(() => { window.location.href = (import.meta.env.BASE_URL || '/') + 'login' }, 800)
    },
    onError: (err: Error) => {
      if (isApprovalPending(err)) {
        toast({ type: 'info', title: t('approvals.pendingToastTitle'), description: t('approvals.pendingToastDesc') })
        setLogoutAllOpen(false)
      } else {
        toast({ type: 'error', title: t('sessions.logoutAllFailed'), description: resolveErrorText(err) })
      }
    },
  })

  const rows = data?.sessions || []

  const handleKick = (sid: string) => {
    setKicking(sid)
    kickMutation.mutate(sid)
  }

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-4xl mx-auto page-enter">
      <div className="stagger-1 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <Shield size={20} className="text-accent" />
            {t('sessions.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('sessions.subtitle')}</p>
        </div>
        <Button
          variant="secondary"
          onClick={() => setLogoutAllOpen(true)}
          disabled={logoutAllMutation.isPending}
        >
          <Power size={16} />
          {t('sessions.signOutEverywhere')}
        </Button>
      </div>

      <Card className="stagger-2 card-hover">
        <CardHeader>
          <div className="text-sm font-semibold text-fg">{t('sessions.listTitle')}</div>
          <div className="text-xs text-fg-subtle">{t('sessions.totalSessions', { count: data?.total ?? 0 })}</div>
        </CardHeader>
        <CardBody>
          {isLoading ? (
            <SkeletonTable rows={3} columns={5} />
          ) : rows.length === 0 ? (
            <EmptyState title={t('sessions.empty')} description={t('sessions.emptyDesc')} />
          ) : (
            <div className="overflow-x-auto -mx-2">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-fg-subtle border-b border-border">
                    <th className="px-3 py-2 font-medium" scope="col">{t('common.user')}</th>
                    <th className="px-3 py-2 font-medium" scope="col">{t('sessions.sid')}</th>
                    <th className="px-3 py-2 font-medium" scope="col">{t('sessions.ip')}</th>
                    <th className="px-3 py-2 font-medium" scope="col">{t('sessions.lastActivity')}</th>
                    <th className="px-3 py-2 font-medium text-right" scope="col">{t('common.actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((s) => (
                    <tr key={s.sid} className="border-b border-border/40 hover:bg-bg-sunken/40">
                      <td className="px-3 py-3">
                        <div className="flex items-center gap-3">
                          <AvatarBadge username={s.user_id || '?'} size="sm" />
                          <span className="font-medium text-fg">{s.user_id || t('sessions.unknown')}</span>
                        </div>
                      </td>
                      <td className="px-3 py-3">
                        <Badge variant="muted" className="font-mono">{s.sid.slice(0, 8)}</Badge>
                      </td>
                      <td className="px-3 py-3 text-fg-muted text-xs">
                        {s.ip}
                        {s.ua && <div className="text-fg-subtle truncate max-w-[260px]" title={s.ua}>{s.ua}</div>}
                      </td>
                      <td className="px-3 py-3 text-fg-muted text-xs">
                        {formatDate(s.last_activity_at)}
                      </td>
                      <td className="px-3 py-3 text-right">
                        <Button
                          size="sm"
                          variant="ghost"
                          className="text-danger hover:text-danger"
                          onClick={() => handleKick(s.sid)}
                          loading={kicking === s.sid && kickMutation.isPending}
                          disabled={kicking !== null}
                        >
                          <LogOut size={14} />
                          {t('sessions.kick')}
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      <Confirm
        open={logoutAllOpen}
        title={t('sessions.logoutAllTitle')}
        message={t('sessions.logoutAllMessage')}
        confirmText={t('sessions.signOutEverywhere')}
        variant="danger"
        onConfirm={() => logoutAllMutation.mutate()}
        onCancel={() => setLogoutAllOpen(false)}
      />
    </div>
  )
}