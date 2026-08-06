import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Bell,
  CheckCheck,
  Trash2,
  RefreshCw,
  Settings as SettingsIcon,
  AlertTriangle,
  ShieldCheck,
  Database,
  Lock,
  Activity,
  ExternalLink,
  Plus,
  Pencil,
  Mail,
  Send,
  Webhook,
} from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/EmptyState'
import { Modal, Confirm } from '@/components/ui/Modal'
import { BottomSheet } from '@/components/ui/BottomSheet'
import { useIsMobile } from '@/hooks/useMediaQuery'
import { useI18n } from '@/hooks/useI18n'
import { toast } from '@/components/ui/Toast'
import { useNavigate } from 'react-router-dom'
import { ChannelModal } from '@/components/notifications/ChannelModal'
import { notificationsApi, notificationChannelsApi } from '@/api/notifications'
import {
  useRelativeTime,
  JsonTree,
  NOTIFY_SEVERITY_BADGE,
  renderI18nText,
} from '@/components/notifications/helpers'
import type {
  Notification,
  NotificationCategory,
  NotificationChannel,
  NotificationSeverity,
} from '@shared/types'

const CATEGORY_META: Record<NotificationCategory, { icon: typeof Bell; origin: string | null }> = {
  login_anomaly: { icon: ShieldCheck, origin: null },
  backup: { icon: Database, origin: '/backup' },
  ssl: { icon: Lock, origin: '/ssl' },
  security: { icon: AlertTriangle, origin: null },
  system: { icon: Activity, origin: null },
}

const CHANNEL_TYPE_ICONS: Record<'email' | 'smtp' | 'webhook', typeof Mail> = {
  email: Mail,
  smtp: Send,
  webhook: Webhook,
}

const CATEGORIES: (NotificationCategory | 'all')[] = ['all', 'login_anomaly', 'backup', 'ssl', 'security', 'system']
const LIMIT = 50

export default function Notifications() {
  const { t } = useI18n()
  const qc = useQueryClient()
  const nav = useNavigate()
  const isMobile = useIsMobile()
  const relTime = useRelativeTime()

  const [category, setCategory] = useState<NotificationCategory | 'all'>('all')
  const [unreadOnly, setUnreadOnly] = useState(false)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [mobileDetailOpen, setMobileDetailOpen] = useState(false)
  const [offset, setOffset] = useState(0)
  const [channelsModalOpen, setChannelsModalOpen] = useState(false)
  const [channelEditorOpen, setChannelEditorOpen] = useState(false)
  const [editingChannel, setEditingChannel] = useState<NotificationChannel | null>(null)
  const [confirmClear, setConfirmClear] = useState(false)
  const [confirmDeleteId, setConfirmDeleteId] = useState<string | null>(null)

  const { data, isLoading, refetch, isFetching } = useQuery({
    queryKey: ['notificationsList', category, unreadOnly, offset],
    queryFn: () =>
      notificationsApi.list({
        category: category === 'all' ? undefined : category,
        unread_only: unreadOnly,
        limit: LIMIT,
        offset,
      }),
  })
  const { data: summary } = useQuery({
    queryKey: ['notificationsSummary'],
    queryFn: () => notificationsApi.summary(),
    refetchInterval: 60000,
  })
  const { data: channels } = useQuery({
    queryKey: ['notificationChannels'],
    queryFn: () => notificationChannelsApi.list(),
  })

  const items = data?.items ?? []
  const total = data?.total ?? 0
  const unreadCount = data?.unread_count ?? summary?.unread ?? 0
  const countMapTotal = summary?.total ?? 0
  const selectedItem = useMemo(() => items.find((i) => i.id === selectedId) || null, [items, selectedId])

  const invalidateAll = () => {
    qc.invalidateQueries({ queryKey: ['notificationsList'] })
    qc.invalidateQueries({ queryKey: ['notificationsSummary'] })
  }

  const markReadMut = useMutation({ mutationFn: (id: string) => notificationsApi.markRead(id), onSuccess: invalidateAll })
  const markAllMut = useMutation({
    mutationFn: () => notificationsApi.readAll(),
    onSuccess: () => { toast({ type: 'success', title: t('notify.readAll') }); invalidateAll() },
    onError: (e: Error) => toast({ type: 'error', title: t('common.saveFailed'), description: e.message }),
  })
  const delMut = useMutation({
    mutationFn: (id: string) => notificationsApi.remove(id),
    onSuccess: () => { invalidateAll(); setSelectedId(null); setMobileDetailOpen(false) },
  })
  const clearMut = useMutation({
    mutationFn: () => notificationsApi.clearRead(),
    onSuccess: () => {
      toast({ type: 'success', title: t('notify.clearedRead', { defaultValue: '已清空已读通知' })
      })
      invalidateAll()
      setConfirmClear(false)
    },
  })
  const removeChMut = useMutation({
    mutationFn: (id: string) => notificationChannelsApi.remove(id),
    onSuccess: () => {
      toast({ type: 'success', title: t('common.deleteSuccess', { defaultValue: '已删除' }) })
      qc.invalidateQueries({ queryKey: ['notificationChannels'] })
    },
    onError: (e: Error) => toast({ type: 'error', title: t('common.saveFailed'), description: e.message }),
  })

  const catLabel = (c: NotificationCategory | 'all') =>
    c === 'all'
      ? t('notify.catAll', { defaultValue: '全部' })
      : t((`notify.category$$c.charAt(0).toUpperCase() + c.slice(1)}`) as never, { defaultValue: c })

  const sevBadge = (s: NotificationSeverity) => NOTIFY_SEVERITY_BADGE[s]
  const sevLabel = (s: NotificationSeverity) =>
    t((`notify.severity$$s.charAt(0).toUpperCase() + s.slice(1)}`) as never, { defaultValue: s })
  const hasMore = items.length === LIMIT && offset + LIMIT < total

  const listHeader = (
    <div className="flex items-center justify-between gap-2 mb-3">
        <div className="text-sm text-fg-muted">
          {isLoading
            ? t('common.loading')
            : t('notify.countShown', {
                shown: String(Math.min(offset + items.length, total)),
                total: String(total),
              })}
        </div>
      <Button variant="ghost" size="icon-sm" onClick={() => refetch()} disabled={isFetching}>
        <RefreshCw size={16} className={isFetching ? 'animate-spin' : ''} />
      </Button>
    </div>
  )

  const leftPanel = (
    <div className="rounded-2xl border border-border bg-bg-elevated p-3 space-y-2">
      <div className="text-xs uppercase tracking-wide text-fg-subtle font-semibold px-2 pt-1">
        {t('notify.filterCategories', { defaultValue: '分类' })}
      </div>
      <div className="flex flex-col gap-1">
        {CATEGORIES.map((c) => {
          const active = c === category
          const Icon = c === 'all' ? Bell : CATEGORY_META[c].icon
          return (
            <button
              key={c}
              onClick={() => { setCategory(c); setOffset(0) }}
              className={`w-full flex items-center justify-between gap-2 rounded-lg px-2.5 py-2 text-sm transition-all $$
                active ? 'bg-accent/10 text-accent font-semibold' : 'text-fg-muted hover:bg-bg-sunken hover:text-fg'
              }`}
            >
              <span className="flex items-center gap-2 truncate"><Icon size={16} /><span className="truncate">{catLabel(c)}</span></span>
              {c === 'all' && (
                <Badge variant={active ? 'accent' : 'muted'} className="text-[11px] px-1.5">{countMapTotal}</Badge>
              )}
            </button>
          )
        })}
      </div>
      <div className="pt-2 border-t border-border mt-2">
        <label className="flex items-center gap-2 text-sm text-fg select-none px-2 py-1.5">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-border accent-accent"
            checked={unreadOnly}
            onChange={(e) => { setUnreadOnly(e.target.checked); setOffset(0) }}
          />
          <span>{t('notify.unreadOnly', { defaultValue: '仅未读' })}</span>
        </label>
      </div>
    </div>
  )

  const listPanel = (
    <div className="flex-1 rounded-2xl border border-border bg-bg-elevated overflow-hidden flex flex-col min-h-[50vh]">
      {items.length === 0 && !isLoading ? (
        <div className="flex-1 flex items-center justify-center p-8">
          <EmptyState title={t('notify.noNotifications')} description={t('notify.noNotificationsHint')} />
        </div>
      ) : (
        <div className="divide-y divide-border/60 overflow-auto flex-1">
          {items.map((it) => {
            const active = it.id === selectedId
            const unread = !it.read_at
            return (
              <button
                type="button"
                key={it.id}
                onClick={() => { setSelectedId(it.id); if (isMobile) setMobileDetailOpen(true) }}
                className={`w-full text-left p-3 transition-all group relative $$
                  active ? 'bg-accent/5' : 'hover:bg-bg-sunken/60'
                } $$unread ? 'bg-fg/[0.015]' : ''}`}
              >
                {unread && (
                  <span className="absolute left-1.5 top-1/2 -translate-y-1/2 w-2 h-2 rounded-full bg-accent shrink-0" />
                )}
                <div className={`flex items-start gap-2 $$unread ? 'pl-3' : 'pl-1'}`}>
                  <div className="flex items-center gap-1.5 shrink-0 flex-wrap">
                    <Badge variant="muted" className="text-[10px] px-1.5">{catLabel(it.category)}</Badge>
                    <Badge variant={sevBadge(it.severity)} className="text-[10px] px-1.5">{sevLabel(it.severity)}</Badge>
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className={`text-sm truncate $$unread ? 'font-semibold text-fg' : 'text-fg'}`}>
                      {renderI18nText(t, it.title_key, it.body_params as never) ?? it.title_key}
                    </div>
                    <div className="text-xs text-fg-subtle mt-0.5">{relTime(it.created_at)}</div>
                  </div>
                </div>
              </button>
            )
          })}
        </div>
      )}
      {(hasMore || offset > 0) && (
        <div className="p-3 border-t border-border/60 flex items-center justify-between gap-2">
          <Button variant="ghost" size="sm" disabled={offset === 0} onClick={() => setOffset((o) => Math.max(0, o - LIMIT))}>
            {t('notify.prevPage', { defaultValue: '上一页' })}
          </Button>
          <span className="text-xs text-fg-subtle">
            {t('notify.pageInfo', { p: String(Math.floor(offset / LIMIT) + 1) })}
          </span>
          <Button variant="secondary" size="sm" disabled={!hasMore} onClick={() => setOffset((o) => o + LIMIT)}>
            {t('notify.nextPage', { defaultValue: '下一页' })}
          </Button>
        </div>
      )}
    </div>
  )

  const channelTypeLabel = (tp: 'email' | 'smtp' | 'webhook') =>
    tp === 'email'
      ? t('notify.channelTypeEmail', { defaultValue: t('notify.channelMail') })
      : tp === 'smtp'
        ? t('notify.channelTypeSmtp', { defaultValue: t('notify.channelSmtp') })
        : t('notify.channelTypeWebhook', { defaultValue: t('notify.channelWebhook') })

  const detailPanel = (it: Notification) => {
    const origin = CATEGORY_META[it.category]?.origin
    return (
      <div className="space-y-4">
        <div className="space-y-2">
          <div className="flex items-center gap-1.5 flex-wrap">
            <Badge variant="muted">{catLabel(it.category)}</Badge>
            <Badge variant={sevBadge(it.severity)}>{sevLabel(it.severity)}</Badge>
            <Badge variant={it.read_at ? 'muted' : 'accent'}>
              {it.read_at ? t('notify.alreadyRead', { defaultValue: '已读' }) : t('notify.unread', { defaultValue: '未读' })}
            </Badge>
          </div>
          <h2 className="text-lg font-semibold text-fg leading-snug">
            {renderI18nText(t, it.title_key, it.body_params as never) ?? it.title_key}
          </h2>
          <div className="text-xs text-fg-subtle">{new Date(it.created_at * 1000).toLocaleString()}</div>
        </div>
        {it.body_key && (
          <div className="rounded-xl border border-border bg-bg-sunken/30 p-4 text-sm text-fg leading-relaxed whitespace-pre-wrap">
            {renderI18nText(t, it.body_key, it.body_params as never)}
          </div>
        )}
        <div className="space-y-2">
          <div className="text-xs uppercase tracking-wide text-fg-subtle font-semibold">
            {t('notify.rawPayload', { defaultValue: '原始数据' })}
          </div>
          <JsonTree data={it.payload ?? { id: it.id, body_params: it.body_params }} />
        </div>
        <div className="flex flex-wrap gap-2 pt-2">
          {!it.read_at && (
            <Button variant="secondary" size="sm" onClick={() => markReadMut.mutate(it.id)} loading={markReadMut.isPending}>
              <CheckCheck size={16} />{t('notify.markRead')}
            </Button>
          )}
          {origin && (
            <Button variant="secondary" size="sm" onClick={() => nav(origin)}>
              <ExternalLink size={16} />{t('notify.goOrigin', { defaultValue: '跳转到相关页面' })}
            </Button>
          )}
          <Button variant="danger" size="sm" onClick={() => setConfirmDeleteId(it.id)} loading={delMut.isPending}>
            <Trash2 size={16} />{t('notify.deleteNotif', { defaultValue: '删除' })}
          </Button>
        </div>
      </div>
    )
  }

  const detailPanelEmpty = (
    <div className="h-full flex items-center justify-center">
      <EmptyState
        title={t('notify.selectNotif', { defaultValue: '选择一条通知查看详情' })}
        description={t('notify.selectNotifHint', { defaultValue: '点击左侧列表项即可查看完整内容与操作。' })}
      />
    </div>
  )

  const channelsCard = (
    <Modal
      open={channelsModalOpen}
      onClose={() => setChannelsModalOpen(false)}
      title={t('notify.channelSettings', { defaultValue: '通知通道设置' })}
      size="lg"
      footer={
        <>
          <Button variant="ghost" onClick={() => setChannelsModalOpen(false)}>{t('common.close')}</Button>
          <Button
            variant="primary"
            onClick={() => { setEditingChannel(null); setChannelEditorOpen(true) }}
          >
            <Plus size={16} />{t('notify.addChannel', { defaultValue: '添加通知通道' })}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {(channels ?? []).length === 0 ? (
          <EmptyState
            title={t('notify.noChannels', { defaultValue: '尚无通知通道' })}
            description={t('notify.noChannelsHint', {
              defaultValue: '点击右上角「添加通知通道」开始配置。'
            })}
          />
        ) : (
          <div className="divide-y divide-border rounded-xl border border-border overflow-hidden">
            {(channels ?? []).map((ch) => {
              const Icon = CHANNEL_TYPE_ICONS[ch.type]
              return (
                <div key={ch.id} className="p-3 flex items-center gap-3">
                  <div className="w-9 h-9 rounded-lg bg-bg-sunken text-fg-muted flex items-center justify-center shrink-0">
                    <Icon size={16} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-medium text-fg truncate">{ch.name}</div>
                    <div className="text-xs text-fg-subtle flex items-center gap-1.5 mt-0.5">
                      <Badge variant={ch.enabled ? 'success' : 'muted'} className="text-[10px] px-1.5">
                        {ch.enabled ? t('notify.channelOn', { defaultValue: '已启用' }) : t('notify.channelOff', { defaultValue: '已停用' })}
                      </Badge>
                      <span>{channelTypeLabel(ch.type)}</span>
                    </div>
                  </div>
                  <Button variant="ghost" size="icon-sm" onClick={() => { setEditingChannel(ch); setChannelEditorOpen(true) }}>
                    <Pencil size={14} />
                  </Button>
                  <Button
                    variant="ghost" size="icon-sm" className="text-fg-muted hover:text-danger"
                    onClick={() => removeChMut.mutate(ch.id)}
                  >
                    <Trash2 size={14} />
                  </Button>
                </div>
              )
            })}
          </div>
        )}
      </div>
    </Modal>
  )

  return (
    <div className="p-4 md:p-6 space-y-4 page-enter">
      <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-fg">{t('notify.inbox')}</h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('notify.totalUnread', { count: String(unreadCount) })}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" size="sm" onClick={() => markAllMut.mutate()} loading={markAllMut.isPending}>
            <CheckCheck size={16} />{t('notify.readAll')}
          </Button>
          <Button variant="secondary" size="sm" onClick={() => setConfirmClear(true)} loading={clearMut.isPending}>
            <Trash2 size={16} />{t('notify.clearRead', { defaultValue: '清空已读' })}
          </Button>
          <Button variant="ghost" size="sm" onClick={() => refetch()} disabled={isFetching}>
            <RefreshCw size={16} className={isFetching ? 'animate-spin' : ''} />{t('common.refresh')}
          </Button>
          <Button variant="primary" size="sm" onClick={() => setChannelsModalOpen(true)}>
            <SettingsIcon size={16} />{t('notify.channelSettings', { defaultValue: '通知通道设置' })}
          </Button>
        </div>
      </div>

      {isMobile ? (
        <div className="space-y-3">
          {leftPanel}
          {listHeader}
          {listPanel}
        </div>
      ) : (
        <div className="grid grid-cols-12 gap-4 min-h-[calc(100vh-12rem)]">
          <div className="col-span-3 space-y-3">{leftPanel}</div>
          <div className="col-span-5 flex flex-col min-h-0">
            {listHeader}
            {listPanel}
          </div>
          <div className="col-span-4 rounded-2xl border border-border bg-bg-elevated p-4 overflow-auto">
            {selectedItem ? detailPanel(selectedItem) : detailPanelEmpty}
          </div>
        </div>
      )}

      {isMobile && (
        <BottomSheet open={mobileDetailOpen} onClose={() => setMobileDetailOpen(false)} title={t('notify.inbox')}>
          <div className="px-4 pb-6">{selectedItem ? detailPanel(selectedItem) : null}</div>
        </BottomSheet>
      )}

      {channelsCard}
      <ChannelModal open={channelEditorOpen} onClose={() => setChannelEditorOpen(false)} initial={editingChannel} />
      <Confirm
        open={!!confirmDeleteId}
        title={t('notify.deleteConfirmTitle', { defaultValue: '删除通知' })}
        message={t('notify.deleteConfirmMsg', { defaultValue: '确认删除该条通知吗？此操作无法撤销。' })}
        variant="danger"
        onConfirm={() => { if (confirmDeleteId) delMut.mutate(confirmDeleteId); setConfirmDeleteId(null) }}
        onCancel={() => setConfirmDeleteId(null)}
        loading={delMut.isPending}
      />
      <Confirm
        open={confirmClear}
        title={t('notify.clearConfirmTitle', { defaultValue: '清空已读通知' })}
        message={t('notify.clearConfirmMsg', { defaultValue: '确认删除所有已读通知？此操作无法撤销。' })}
        variant="danger"
        onConfirm={() => clearMut.mutate()}
        onCancel={() => setConfirmClear(false)}
        loading={clearMut.isPending}
      />
    </div>
  )
}
