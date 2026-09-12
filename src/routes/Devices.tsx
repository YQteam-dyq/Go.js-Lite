import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ShieldCheck, Trash2, Laptop } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonTable } from '@/components/ui/Skeleton'
import { toast } from '@/components/ui/Toast'
import { devicesApi, type TrustedDevice } from '@/api/devices'
import { useI18n } from '@/hooks/useI18n'
import { useFormat } from '@/lib/format'
import { resolveErrorText } from '@/lib/errorMessages'

function shortUa(ua: string): string {
  if (!ua) return '—'
  const match = ua.match(/\(([^)]+)\)/)
  return match ? match[1] : ua.slice(0, 48)
}

export default function Devices() {
  const { t } = useI18n()
  const { formatDate, formatRelativeTime } = useFormat()
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: ['devices'],
    queryFn: () => devicesApi.list(),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['devices'] })

  const trustMutation = useMutation({
    mutationFn: () => devicesApi.trust(),
    onSuccess: () => {
      toast({ type: 'success', title: t('devices.trusted') })
      invalidate()
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.saveFailed'), description: resolveErrorText(err) })
    },
  })

  const revokeMutation = useMutation({
    mutationFn: (fp: string) => devicesApi.revoke(fp),
    onSuccess: () => {
      toast({ type: 'success', title: t('devices.revoked') })
      invalidate()
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.deleteFailed'), description: resolveErrorText(err) })
    },
  })

  const rows: TrustedDevice[] = data?.devices || []
  const currentTrusted = rows.some((d) => d.current)

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-4xl mx-auto page-enter">
      <div className="stagger-1 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <Laptop size={20} className="text-accent" />
            {t('devices.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">
            {t('devices.subtitle', { days: data?.ttl_days ?? 14 })}
          </p>
        </div>
        <Button
          variant="primary"
          onClick={() => trustMutation.mutate()}
          loading={trustMutation.isPending}
          disabled={currentTrusted}
        >
          <ShieldCheck size={16} />
          {currentTrusted ? t('devices.alreadyTrusted') : t('devices.trustThis')}
        </Button>
      </div>

      <Card className="stagger-2 card-hover">
        <CardHeader>
          <div className="text-sm font-semibold text-fg">{t('devices.listTitle')}</div>
          <div className="text-xs text-fg-subtle">{t('devices.totalDevices', { count: data?.total ?? 0 })}</div>
        </CardHeader>
        <CardBody>
          {isLoading ? (
            <SkeletonTable rows={3} columns={4} />
          ) : rows.length === 0 ? (
            <EmptyState
              title={t('devices.empty')}
              description={t('devices.emptyDesc')}
              action={{ label: t('devices.trustThis'), onClick: () => trustMutation.mutate(), variant: 'primary' }}
            />
          ) : (
            <div className="overflow-x-auto -mx-2">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-fg-subtle border-b border-border">
                    <th className="px-3 py-2 font-medium">{t('devices.ip')}</th>
                    <th className="px-3 py-2 font-medium">{t('devices.userAgent')}</th>
                    <th className="px-3 py-2 font-medium">{t('devices.expiresAt')}</th>
                    <th className="px-3 py-2 font-medium text-right">{t('common.actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((d) => (
                    <tr key={d.fingerprint} className="border-b border-border/40 hover:bg-bg-sunken/40">
                      <td className="px-3 py-3 font-mono text-fg">
                        {d.ip || '—'}
                        {d.current && <Badge variant="accent" className="ml-2">{t('devices.thisDevice')}</Badge>}
                      </td>
                      <td className="px-3 py-3 text-fg-muted text-xs max-w-xs truncate" title={d.ua}>
                        {shortUa(d.ua)}
                      </td>
                      <td className="px-3 py-3 text-fg-muted text-xs" title={formatDate(d.expires_at)}>
                        {formatRelativeTime(d.expires_at)}
                      </td>
                      <td className="px-3 py-3 text-right">
                        <Button
                          size="sm"
                          variant="ghost"
                          className="text-danger hover:text-danger"
                          onClick={() => revokeMutation.mutate(d.fingerprint)}
                        >
                          <Trash2 size={14} />
                          {t('devices.revoke')}
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
    </div>
  )
}
