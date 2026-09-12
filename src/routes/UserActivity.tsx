import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Activity, Users as UsersIcon, RefreshCw } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { AvatarBadge } from '@/components/ui/AvatarBadge'
import { Badge } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonTable } from '@/components/ui/Skeleton'
import { userActivityApi, type ActivityAggregateRow } from '@/api/userActivity'
import { usersApi } from '@/api/users'
import { useFormat } from '@/lib/format'
import { useI18n } from '@/hooks/useI18n'

const WINDOWS = ['1h', '6h', '24h', '7d'] as const

export default function UserActivity() {
  const { t } = useI18n()
  const { formatDate } = useFormat()
  const [since, setSince] = useState<string>('24h')
  const [selected, setSelected] = useState<string | null>(null)

  const recentQuery = useQuery({
    queryKey: ['user-activity-recent', since],
    queryFn: () => userActivityApi.recent(since),
    refetchInterval: 30_000,
  })

  const usersQuery = useQuery({
    queryKey: ['users-for-activity'],
    queryFn: () => usersApi.list(),
  })

  const feedQuery = useQuery({
    queryKey: ['user-activity-feed', selected, since],
    queryFn: () => userActivityApi.userFeed(selected as string, since, 100),
    enabled: !!selected,
  })

  const rows: ActivityAggregateRow[] = recentQuery.data?.rows || []

  const resolveUsername = (userId: string): string => {
    const u = usersQuery.data?.users.find((x) => x.id === userId)
    return u?.username || userId
  }

  const errorRate = (r: ActivityAggregateRow): number =>
    r.total > 0 ? Math.round(r.error_rate * 100) : 0

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-5xl mx-auto page-enter">
      <div className="stagger-1 flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <Activity size={20} className="text-accent" />
            {t('userActivity.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('userActivity.subtitle')}</p>
        </div>
        <div className="flex items-center gap-3">
          <label className="text-xs text-fg-muted" htmlFor="user-activity-window">
            {t('userActivity.window')}
          </label>
          <select
            id="user-activity-window"
            className="h-9 rounded-lg border border-border bg-bg-elevated px-3 text-sm"
            value={since}
            onChange={(e) => setSince(e.target.value)}
            aria-label={t('userActivity.window')}
          >
            {WINDOWS.map((w) => (
              <option key={w} value={w}>
                {t(`userActivity.window_${w}` as never)}
              </option>
            ))}
          </select>
          <Button variant="ghost" onClick={() => recentQuery.refetch()} loading={recentQuery.isFetching}
            aria-label={t('common.refresh')}>
            <RefreshCw size={16} />
            <span className="hidden md:inline">{t('common.refresh')}</span>
          </Button>
        </div>
      </div>

      <Card className="stagger-2 card-hover">
        <CardHeader>
          <div className="text-sm font-semibold text-fg">{t('userActivity.recent')}</div>
          <div className="text-xs text-fg-subtle">
            {t('userActivity.total24h', { count: recentQuery.data?.total ?? 0 })}
          </div>
        </CardHeader>
        <CardBody>
          {recentQuery.isLoading ? (
            <SkeletonTable rows={5} columns={4} />
          ) : rows.length === 0 ? (
            <EmptyState title={t('userActivity.empty')} description={t('userActivity.emptyDesc')} />
          ) : (
            <div className="overflow-x-auto -mx-2">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-fg-subtle border-b border-border">
                    <th className="px-3 py-2 font-medium" scope="col">{t('common.user')}</th>
                    <th className="px-3 py-2 font-medium" scope="col">{t('userActivity.ops')}</th>
                    <th className="px-3 py-2 font-medium" scope="col">{t('userActivity.failures')}</th>
                    <th className="px-3 py-2 font-medium" scope="col">{t('userActivity.errorRate')}</th>
                    <th className="px-3 py-2 font-medium" scope="col">{t('userActivity.lastActivity')}</th>
                    <th className="px-3 py-2 font-medium text-right" scope="col">
                      <span className="sr-only">{t('common.actions')}</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.key} className="border-b border-border/30 hover:bg-bg-sunken/40">
                      <td className="px-3 py-2">
                        <div className="flex items-center gap-2">
                          <AvatarBadge username={resolveUsername(r.key)} size="sm" />
                          <span className="font-medium text-fg">{resolveUsername(r.key)}</span>
                        </div>
                      </td>
                      <td className="px-3 py-2 font-mono">{r.total}</td>
                      <td className="px-3 py-2 font-mono">
                        {r.failed > 0 ? <span className="text-danger">{r.failed}</span> : '—'}
                      </td>
                      <td className="px-3 py-2 font-mono">
                        {r.failed > 0 ? (
                          <span className={errorRate(r) >= 20 ? 'text-danger' : 'text-warning'}>
                            {errorRate(r)}%
                          </span>
                        ) : '—'}
                      </td>
                      <td className="px-3 py-2 text-fg-muted text-xs">{r.last_at > 0 ? formatDate(r.last_at) : '—'}</td>
                      <td className="px-3 py-2 text-right">
                        <Button
                          size="sm"
                          variant={selected === r.key ? 'primary' : 'ghost'}
                          onClick={() => setSelected(r.key)}
                          aria-label={t('userActivity.viewFeed', { user: resolveUsername(r.key) })}
                        >
                          {t('userActivity.viewFeedShort')}
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

      <Card className="stagger-3 card-hover">
        <CardHeader className="flex flex-wrap items-center gap-3">
          <div className="text-sm font-semibold text-fg flex items-center gap-2">
            <UsersIcon size={16} />
            {t('userActivity.feedTitle')}
          </div>
          <div className="ml-auto">
            <select
              className="h-9 rounded-lg border border-border bg-bg-elevated px-3 text-sm min-w-[180px]"
              value={selected ?? ''}
              onChange={(e) => setSelected(e.target.value || null)}
              aria-label={t('userActivity.selectUser')}
            >
              <option value="">{t('userActivity.selectUser')}</option>
              {rows.map((r) => (
                <option key={r.key} value={r.key}>
                  {resolveUsername(r.key)}
                </option>
              ))}
              {(usersQuery.data?.users || [])
                .filter((u) => !rows.some((r) => r.key === u.id))
                .map((u) => (
                  <option key={u.id} value={u.id}>
                    {u.username}
                  </option>
                ))}
            </select>
          </div>
        </CardHeader>
        <CardBody>
          {!selected ? (
            <EmptyState title={t('userActivity.selectUserHint')} description={t('userActivity.selectUserDesc')} />
          ) : feedQuery.isLoading ? (
            <SkeletonTable rows={4} columns={3} />
          ) : !feedQuery.data || feedQuery.data.count === 0 ? (
            <EmptyState title={t('userActivity.feedEmpty')} description={t('userActivity.feedEmptyDesc')} />
          ) : (
            <>
              <div className="text-xs text-fg-subtle mb-2">
                {t('userActivity.entries', { count: feedQuery.data.count })}
              </div>
              <div className="overflow-x-auto -mx-2 max-h-96 overflow-y-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-fg-subtle border-b border-border">
                      <th className="px-3 py-2 font-medium" scope="col">{t('userActivity.colTime')}</th>
                      <th className="px-3 py-2 font-medium" scope="col">{t('userActivity.colAction')}</th>
                      <th className="px-3 py-2 font-medium" scope="col">{t('userActivity.colTarget')}</th>
                      <th className="px-3 py-2 font-medium" scope="col">{t('userActivity.colResult')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {feedQuery.data.entries.map((e, idx) => (
                      <tr key={`${e.timestamp}-${idx}`} className="border-b border-border/30">
                        <td className="px-3 py-2 text-fg-muted text-xs whitespace-nowrap">{formatDate(e.timestamp)}</td>
                        <td className="px-3 py-2 font-mono text-fg">{e.action}</td>
                        <td className="px-3 py-2 text-fg-muted text-xs max-w-md truncate" title={e.target}>
                          {e.target || '—'}
                        </td>
                        <td className="px-3 py-2">
                          {e.result === false ? (
                            <Badge variant="danger">{t('userActivity.resultFail')}</Badge>
                          ) : (
                            <Badge variant="success">{t('userActivity.resultOk')}</Badge>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </CardBody>
      </Card>
    </div>
  )
}
