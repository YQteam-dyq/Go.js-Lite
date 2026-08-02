import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Bell, Plus, Pencil, Trash2, Mail, Send, Webhook, Settings as SettingsIcon } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { EmptyState } from '@/components/ui/EmptyState'
import { useI18n } from '@/hooks/useI18n'
import { toast } from '@/components/ui/Toast'
import { ChannelModal } from '@/components/notifications/ChannelModal'
import { notificationChannelsApi } from '@/api/notifications'
import type { NotificationChannel } from '@shared/types'

const TYPE_ICONS: Record<'email' | 'smtp' | 'webhook', typeof Mail> = {
  email: Mail,
  smtp: Send,
  webhook: Webhook,
}

export function NotificationChannelsCard() {
  const { t } = useI18n()
  const qc = useQueryClient()

  const [channelEditorOpen, setChannelEditorOpen] = useState(false)
  const [editingChannel, setEditingChannel] = useState<NotificationChannel | null>(null)

  const { data: channels, isLoading } = useQuery({
    queryKey: ['notificationChannels'],
    queryFn: () => notificationChannelsApi.list(),
  })

  const removeMut = useMutation({
    mutationFn: (id: string) => notificationChannelsApi.remove(id),
    onSuccess: () => {
      toast({ type: 'success', title: t('common.deleteSuccess', { defaultValue: '已删除' }) })
      qc.invalidateQueries({ queryKey: ['notificationChannels'] })
    },
    onError: (e: Error) => toast({ type: 'error', title: t('common.saveFailed'), description: e.message }),
  })

  const typeLabel = (tp: 'email' | 'smtp' | 'webhook') =>
    tp === 'email'
      ? t('notify.channelTypeEmail', { defaultValue: t('notify.channelMail') })
      : tp === 'smtp'
        ? t('notify.channelTypeSmtp', { defaultValue: t('notify.channelSmtp') })
        : t('notify.channelTypeWebhook', { defaultValue: t('notify.channelWebhook') })

  return (
    <>
      <Card className="stagger-6b card-hover">
        <CardHeader className="flex items-center gap-3">
          <div className="w-11 h-11 rounded-xl bg-accent/10 text-accent flex items-center justify-center">
            <Bell size={20} />
          </div>
          <div className="flex-1 min-w-0">
            <div className="text-sm font-semibold text-fg">
              {t('notify.channelsCardTitle', { defaultValue: '通知通道' })}
            </div>
            <div className="text-xs text-fg-subtle">
              {t('notify.channelsCardDesc', {
                defaultValue: '配置告警与系统事件的外发通道：Email / SMTP 中继 / Webhook。',
              })}
            </div>
          </div>
          <Button
            variant="secondary"
            size="sm"
            onClick={() => {
              setEditingChannel(null)
              setChannelEditorOpen(true)
            }}
          >
            <Plus size={16} />
            {t('notify.addChannel', { defaultValue: '添加' })}
          </Button>
        </CardHeader>
        <CardBody className="space-y-3">
          {isLoading ? (
            <div className="h-20 flex items-center justify-center text-sm text-fg-muted">
              {t('common.loading')}
            </div>
          ) : (channels ?? []).length === 0 ? (
            <EmptyState
              title={t('notify.noChannels', { defaultValue: '尚无通知通道' })}
              description={t('notify.noChannelsHintSettings', {
                defaultValue: '点击右上角「添加」配置 Email / SMTP / Webhook。',
              })}
              icon={
                <div className="w-16 h-16 rounded-full bg-bg-sunken flex items-center justify-center text-fg-subtle mx-auto">
                  <SettingsIcon size={28} />
                </div>
              }
            />
          ) : (
            <div className="divide-y divide-border rounded-xl border border-border overflow-hidden">
              {(channels ?? []).map((ch) => {
                const Icon = TYPE_ICONS[ch.type]
                return (
                  <div key={ch.id} className="p-3 flex items-center gap-3">
                    <div className="w-9 h-9 rounded-lg bg-bg-sunken text-fg-muted flex items-center justify-center shrink-0">
                      <Icon size={16} />
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="text-sm font-medium text-fg truncate">{ch.name}</div>
                      <div className="text-xs text-fg-subtle flex items-center gap-1.5 mt-0.5 flex-wrap">
                        <Badge variant={ch.enabled ? 'success' : 'muted'} className="text-[10px] px-1.5">
                          {ch.enabled
                            ? t('notify.channelOn', { defaultValue: '已启用' })
                            : t('notify.channelOff', { defaultValue: '已停用' })}
                        </Badge>
                        <span>{typeLabel(ch.type)}</span>
                        {ch.type === 'webhook' && 'url' in ch && ch.url && (
                          <span className="truncate max-w-[180px] opacity-70">{ch.url}</span>
                        )}
                        {ch.type === 'smtp' && 'host' in ch && ch.host && (
                          <span className="opacity-70">
                            {ch.host}:{(ch as any).port || '25'}
                          </span>
                        )}
                        {ch.type === 'email' && (ch as any).to_addr && (
                          <span className="opacity-70 truncate max-w-[180px]">→ {(ch as any).to_addr}</span>
                        )}
                      </div>
                    </div>
                    <Button
                      variant="ghost"
                      size="icon-sm"
                      onClick={() => {
                        setEditingChannel(ch)
                        setChannelEditorOpen(true)
                      }}
                    >
                      <Pencil size={14} />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon-sm"
                      className="text-fg-muted hover:text-danger"
                      onClick={() => removeMut.mutate(ch.id)}
                    >
                      <Trash2 size={14} />
                    </Button>
                  </div>
                )
              })}
            </div>
          )}
          <p className="text-xs text-fg-subtle pt-1 flex items-start gap-1.5">
            <Badge variant="muted">{t('notify.securityHintTitle', { defaultValue: '安全说明' })}</Badge>
            <span>
              {t('notify.securityHint', {
                defaultValue:
                  '敏感字段（密码、Headers）使用机器密钥加密存储，列表中仅显示脱敏标识 ****。',
              })}
            </span>
          </p>
        </CardBody>
      </Card>
      <ChannelModal
        open={channelEditorOpen}
        onClose={() => setChannelEditorOpen(false)}
        initial={editingChannel}
      />
    </>
  )
}
