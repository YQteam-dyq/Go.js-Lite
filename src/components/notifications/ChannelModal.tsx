import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Mail, Send, Webhook, Trash2, CheckCircle2, XCircle, Plus } from 'lucide-react'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { toast } from '@/components/ui/Toast'
import { useI18n } from '@/hooks/useI18n'
import { notificationChannelsApi, type ChannelTestResult } from '@/api/notifications'
import type { NotificationChannel, NotificationChannelType } from '@shared/types'

type ChannelFormState = Partial<{
  name: string
  type: NotificationChannelType
  enabled: boolean
  from_addr: string
  to_addr: string
  host: string
  port: number
  username: string
  password: string
  use_tls: boolean
  url: string
  method: 'POST' | 'PUT'
  headers: Record<string, string>
}>

interface ChannelModalProps {
  open: boolean
  onClose: () => void
  initial?: NotificationChannel | null
}

const TYPE_ICONS: Record<NotificationChannelType, typeof Mail> = {
  email: Mail,
  smtp: Send,
  webhook: Webhook,
}

const INITIAL_FORM: ChannelFormState = {
  name: '',
  type: 'email',
  enabled: true,
  port: 25,
  method: 'POST',
  headers: {},
}

function emptyForm(): ChannelFormState {
  return { ...INITIAL_FORM, headers: {} }
}

function channelToForm(ch: NotificationChannel): ChannelFormState {
  const base: ChannelFormState = {
    name: ch.name,
    type: ch.type,
    enabled: ch.enabled,
  }
  if (ch.type === 'email') {
    base.from_addr = ch.from_addr
  }
  if (ch.type === 'smtp') {
    base.host = ch.host
    base.port = ch.port
    base.username = ch.username
    base.from_addr = ch.from_addr
    base.use_tls = ch.use_tls
    base.password = ch.password_enc ? '****' : ''
  }
  if (ch.type === 'webhook') {
    base.url = ch.url
    base.method = ch.method || 'POST'
    base.headers = {}
  }
  return base
}

export function ChannelModal({ open, onClose, initial }: ChannelModalProps) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [form, setForm] = useState<ChannelFormState>(emptyForm())
  const [testState, setTestState] = useState<{ loading: boolean; result: ChannelTestResult | null }>({
    loading: false,
    result: null,
  })
  const isEdit = !!initial
  const title = isEdit
    ? t('notify.editChannel', { defaultValue: '编辑通知通道' }) + ` — $$initial?.name ?? ''}`
    : t('notify.addChannel', { defaultValue: '添加通知通道' })

  useEffect(() => {
    if (!open) return
    setForm(initial ? channelToForm(initial) : emptyForm())
    setTestState({ loading: false, result: null })
  }, [open, initial])

  const typeOptions: { value: NotificationChannelType; label: string }[] = useMemo(
    () => [
      { value: 'email', label: t('notify.channelTypeEmail', { defaultValue: t('notify.channelMail') }) },
      { value: 'smtp', label: t('notify.channelTypeSmtp', { defaultValue: t('notify.channelSmtp') }) },
      { value: 'webhook', label: t('notify.channelTypeWebhook', { defaultValue: t('notify.channelWebhook') }) },
    ],
    [t],
  )

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = { ...form } as Parameters<typeof notificationChannelsApi.create>[0]
      if (isEdit && initial) {
        return notificationChannelsApi.update(initial.id, payload)
      }
      return notificationChannelsApi.create(payload as never)
    },
    onSuccess: () => {
      toast({ type: 'success', title: t('common.saveSuccess', { defaultValue: '保存成功' }) })
      queryClient.invalidateQueries({ queryKey: ['notificationChannels'] })
      onClose()
    },
    onError: (e: Error) => {
      toast({ type: 'error', title: t('common.saveFailed'), description: e.message })
    },
  })

  const testMutation = useMutation({
    mutationFn: async () => {
      if (!initial) {
        const base: any = {
          name: form.name || `tmp-$$Date.now()}`,
          type: form.type!,
          enabled: true,
        }
        for (const [k, v] of Object.entries(form)) {
          if (v !== undefined) base[k] = v
        }
        const tmp = await notificationChannelsApi.create(base)
        try {
          return await notificationChannelsApi.test(tmp.id)
        } finally {
          await notificationChannelsApi.remove(tmp.id).catch(() => {})
        }
      }
      return notificationChannelsApi.test(initial.id)
    },
    onMutate: () => setTestState({ loading: true, result: null }),
    onSuccess: (res) => setTestState({ loading: false, result: res }),
    onError: (e: Error) => {
      setTestState({ loading: false, result: { ok: false, error: e.message } })
      toast({ type: 'error', title: t('notify.testChannelFailed', { defaultValue: '测试失败' }), description: e.message })
    },
  })

  const updateField = <K extends keyof ChannelFormState>(k: K, v: ChannelFormState[K]) => {
    setForm((prev) => ({ ...prev, [k]: v }))
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      size="lg"
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button
            variant="secondary"
            loading={testMutation.isPending || testState.loading}
            onClick={() => testMutation.mutate()}
          >
            {t('notify.testChannel', { defaultValue: '测试通道' })}
          </Button>
          <Button
            variant="primary"
            loading={saveMutation.isPending}
            disabled={!form.name?.trim()}
            onClick={() => saveMutation.mutate()}
          >
            {t('common.save')}
          </Button>
        </>
      }
    >
      <div className="space-y-5">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div className="space-y-2">
            <label className="block text-sm font-medium text-fg">
              {t('notify.channelName', { defaultValue: '通道名称' })}
            </label>
            <Input
              value={form.name || ''}
              onChange={(e) => updateField('name', e.target.value)}
              placeholder={t('notify.channelNamePlaceholder', { defaultValue: '例如：管理员邮件' })}
            />
          </div>
          <div className="space-y-2">
            <label className="block text-sm font-medium text-fg">
              {t('notify.channelType', { defaultValue: '通道类型' })}
            </label>
            <div className="grid grid-cols-3 gap-2">
              {typeOptions.map((opt) => {
                const Icon = TYPE_ICONS[opt.value]
                const active = form.type === opt.value
                return (
                  <button
                    key={opt.value}
                    type="button"
                    onClick={() => updateField('type', opt.value)}
                    className={`
                      flex flex-col items-center gap-1.5 py-3 px-2 rounded-xl border text-xs transition-all
                      $$active
                        ? 'border-accent bg-accent/5 text-accent font-semibold shadow-sm shadow-accent/10'
                        : 'border-border text-fg-muted hover:border-border-strong hover:text-fg hover:bg-bg-sunken/50'}
                    `}
                  >
                    <Icon size={18} />
                    <span>{opt.label}</span>
                  </button>
                )
              })}
            </div>
          </div>
        </div>

        <label className="flex items-center gap-2 text-sm text-fg select-none">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-border accent-accent"
            checked={!!form.enabled}
            onChange={(e) => updateField('enabled', e.target.checked)}
          />
          <span>{t('notify.channelEnabled', { defaultValue: '启用该通道' })}</span>
        </label>

        {form.type === 'email' && (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="space-y-2">
              <label className="block text-sm font-medium text-fg">
                {t('notify.fromAddr', { defaultValue: '发件人地址' })}
              </label>
              <Input
                value={form.from_addr || ''}
                onChange={(e) => updateField('from_addr', e.target.value)}
                placeholder="noreply@example.com"
              />
            </div>
            <div className="space-y-2">
              <label className="block text-sm font-medium text-fg">
                {t('notify.toAddr', { defaultValue: '收件人地址' })}
              </label>
              <Input
                value={form.to_addr || ''}
                onChange={(e) => updateField('to_addr', e.target.value)}
                placeholder="admin@example.com"
              />
            </div>
          </div>
        )}

        {form.type === 'smtp' && (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2 col-span-2 md:col-span-1">
                <label className="block text-sm font-medium text-fg">
                  {t('notify.smtpHost', { defaultValue: 'SMTP 服务器' })}
                </label>
                <Input
                  value={form.host || ''}
                  onChange={(e) => updateField('host', e.target.value)}
                  placeholder="smtp.example.com"
                />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-fg">
                  {t('notify.smtpPort', { defaultValue: '端口' })}
                </label>
                <Input
                  type="number"
                  value={form.port ?? 25}
                  onChange={(e) => updateField('port', Number(e.target.value))}
                  min={1}
                  max={65535}
                />
              </div>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <label className="block text-sm font-medium text-fg">
                  {t('notify.smtpUser', { defaultValue: '用户名' })}
                </label>
                <Input
                  value={form.username || ''}
                  onChange={(e) => updateField('username', e.target.value)}
                />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-fg">
                  {t('notify.smtpPassword', { defaultValue: '密码' })}
                </label>
                <Input
                  type="password"
                  value={form.password || ''}
                  onChange={(e) => updateField('password', e.target.value)}
                  placeholder={isEdit ? '****' : ''}
                />
              </div>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <label className="block text-sm font-medium text-fg">
                  {t('notify.fromAddr', { defaultValue: '发件人地址' })}
                </label>
                <Input
                  value={form.from_addr || ''}
                  onChange={(e) => updateField('from_addr', e.target.value)}
                  placeholder="noreply@example.com"
                />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-fg">
                  {t('notify.toAddr', { defaultValue: '收件人地址' })}
                </label>
                <Input
                  value={form.to_addr || ''}
                  onChange={(e) => updateField('to_addr', e.target.value)}
                  placeholder="admin@example.com"
                />
              </div>
            </div>
            <label className="flex items-center gap-2 text-sm text-fg select-none">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-border accent-accent"
                checked={!!form.use_tls}
                onChange={(e) => updateField('use_tls', e.target.checked)}
              />
              <span>{t('notify.smtpUseTls', { defaultValue: '使用 TLS/STARTTLS' })}</span>
            </label>
          </div>
        )}

        {form.type === 'webhook' && (
          <div className="space-y-4">
            <div className="grid grid-cols-3 gap-4">
              <div className="space-y-2 col-span-2">
                <label className="block text-sm font-medium text-fg">
                  {t('notify.webhookUrl', { defaultValue: 'Webhook URL' })}
                </label>
                <Input
                  value={form.url || ''}
                  onChange={(e) => updateField('url', e.target.value)}
                  placeholder="https://hooks.example.com/xxx"
                />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-fg">
                  {t('notify.webhookMethod', { defaultValue: '请求方法' })}
                </label>
                <select
                  className="input-base w-full h-10 rounded-lg"
                  value={form.method || 'POST'}
                  onChange={(e) => updateField('method', e.target.value as 'POST' | 'PUT')}
                >
                  <option value="POST">POST</option>
                  <option value="PUT">PUT</option>
                </select>
              </div>
            </div>
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <label className="block text-sm font-medium text-fg">
                  {t('notify.webhookHeaders', { defaultValue: '自定义 Headers' })}
                </label>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => {
                    const hdrs = { ...(form.headers || {}) }
                    let i = 1
                    while (hdrs[`X-Header-$$i}`] !== undefined) i++
                    hdrs[`X-Header-$$i}`] = ''
                    updateField('headers', hdrs)
                  }}
                >
                  <Plus size={14} />
                  {t('notify.webhookAddHeader', { defaultValue: '添加' })}
                </Button>
              </div>
              <div className="space-y-2">
                {Object.entries(form.headers || {}).length === 0 && (
                  <p className="text-xs text-fg-subtle px-1">
                    {t('notify.webhookNoHeaders', { defaultValue: '无自定义请求头' })}
                  </p>
                )}
                {Object.entries(form.headers || {}).map(([k, v], idx) => {
                  const keys = Object.keys(form.headers || {})
                  return (
                    <div key={idx} className="grid grid-cols-[1fr_1.4fr_auto] gap-2 items-center">
                      <Input
                        value={k}
                        placeholder="Header Name"
                        onChange={(e) => {
                          const newKey = e.target.value
                          const newHdrs: Record<string, string> = {}
                          keys.forEach((kk) => {
                            if (kk === k) newHdrs[newKey] = (form.headers || {})[kk]
                            else newHdrs[kk] = (form.headers || {})[kk]
                          })
                          updateField('headers', newHdrs)
                        }}
                        className="text-xs"
                      />
                      <Input
                        value={v}
                        placeholder="Header Value"
                        onChange={(e) => {
                          const newHdrs = { ...(form.headers || {}) }
                          newHdrs[k] = e.target.value
                          updateField('headers', newHdrs)
                        }}
                        className="text-xs"
                      />
                      <Button
                        variant="ghost"
                        size="icon-sm"
                        className="text-fg-muted hover:text-danger"
                        onClick={() => {
                          const newHdrs = { ...(form.headers || {}) }
                          delete newHdrs[k]
                          updateField('headers', newHdrs)
                        }}
                      >
                        <Trash2 size={14} />
                      </Button>
                    </div>
                  )
                })}
              </div>
            </div>
          </div>
        )}

        {testState.result && (
          <div
            className={`
              flex items-start gap-2 rounded-lg border p-3 text-sm
              $$testState.result.ok
                ? 'border-success/30 bg-success/5 text-success'
                : 'border-danger/30 bg-danger/5 text-danger'}
            `}
          >
            {testState.result.ok ? <CheckCircle2 size={18} className="shrink-0 mt-0.5" /> : <XCircle size={18} className="shrink-0 mt-0.5" />}
            <div>
              <div className="font-semibold">
                {testState.result.ok
                  ? t('notify.testChannelOk', { defaultValue: '测试发送成功' })
                  : t('notify.testChannelFail', { defaultValue: '测试发送失败' })}
              </div>
              {testState.result.error && (
                <div className="text-xs mt-1 opacity-80">{testState.result.error}</div>
              )}
            </div>
          </div>
        )}

        <div className="pt-1 text-xs text-fg-subtle space-y-1">
          <p>
            <Badge variant="muted">{t('notify.securityHintTitle', { defaultValue: '安全说明' })}</Badge>
            <span className="ml-2">
              {t('notify.securityHint', {
                defaultValue: '敏感字段（密码、密钥、Headers）将使用机器密钥加密存储，列表仅展示脱敏标识 ****。',
              })}
            </span>
          </p>
        </div>
      </div>
    </Modal>
  )
}
