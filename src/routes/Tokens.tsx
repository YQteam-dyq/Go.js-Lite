import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2, KeyRound, Copy, Check } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Modal, Confirm } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonTable } from '@/components/ui/Skeleton'
import { toast } from '@/components/ui/Toast'
import { tokensApi, type ApiTokenV2 } from '@/api/tokens'
import { useFormat } from '@/lib/format'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'

const SCOPE_OPTIONS = ['admin', 'readonly', 'user-self', 'files.read', 'files.write', 'db.write'] as const

export default function Tokens() {
  const { t } = useI18n()
  const { formatDate } = useFormat()
  const queryClient = useQueryClient()

  const [showCreate, setShowCreate] = useState(false)
  const [createError, setCreateError] = useState('')
  const [form, setForm] = useState({
    name: '',
    scopes: [] as string[],
    rate_limit_per_min: 60,
    never_expires: true,
  })
  const [issued, setIssued] = useState<ApiTokenV2 | null>(null)
  const [copied, setCopied] = useState(false)
  const [revoking, setRevoking] = useState<ApiTokenV2 | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['tokens'],
    queryFn: () => tokensApi.list(),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tokens'] })

  const createMutation = useMutation({
    mutationFn: () => tokensApi.create({
      name: form.name.trim(),
      scopes: form.scopes,
      rate_limit_per_min: Number(form.rate_limit_per_min) || 60,
      expires_at: form.never_expires ? 0 : Math.floor(Date.now() / 1000) + 90 * 86400,
    }),
    onSuccess: (token) => {
      setShowCreate(false)
      setForm({ name: '', scopes: [], rate_limit_per_min: 60, never_expires: true })
      setCreateError('')
      setIssued(token)
      setCopied(false)
      invalidate()
    },
    onError: (err: Error) => setCreateError(resolveErrorText(err)),
  })

  const revokeMutation = useMutation({
    mutationFn: (id: string) => tokensApi.revoke(id),
    onSuccess: () => {
      toast({ type: 'success', title: t('tokens.revoked') })
      setRevoking(null)
      invalidate()
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.saveFailed'), description: resolveErrorText(err) })
    },
  })

  const rows = data?.tokens || []

  const toggleScope = (scope: string) => {
    setForm((prev) => ({
      ...prev,
      scopes: prev.scopes.includes(scope)
        ? prev.scopes.filter((s) => s !== scope)
        : [...prev.scopes, scope],
    }))
  }

  const handleCreate = () => {
    if (!form.name.trim()) {
      setCreateError(t('tokens.nameRequired'))
      return
    }
    if (form.scopes.length === 0) {
      setCreateError(t('tokens.scopesRequired'))
      return
    }
    setCreateError('')
    createMutation.mutate()
  }

  const copyToken = async () => {
    if (!issued?.token_plain_once) return
    try {
      await navigator.clipboard.writeText(issued.token_plain_once)
      setCopied(true)
      toast({ type: 'success', title: t('tokens.copied') })
    } catch {
      setCopied(false)
    }
  }

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-5xl mx-auto page-enter">
      <div className="stagger-1 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <KeyRound size={20} className="text-accent" />
            {t('tokens.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('tokens.subtitle')}</p>
        </div>
        <Button variant="primary" onClick={() => setShowCreate(true)}>
          <Plus size={16} />
          {t('tokens.create')}
        </Button>
      </div>

      <Card className="stagger-2 card-hover">
        <CardHeader>
          <div className="text-sm font-semibold text-fg">{t('tokens.listTitle')}</div>
          <div className="text-xs text-fg-subtle">{t('tokens.totalTokens', { count: data?.total ?? 0 })}</div>
        </CardHeader>
        <CardBody>
          {isLoading ? (
            <SkeletonTable rows={4} columns={5} />
          ) : rows.length === 0 ? (
            <EmptyState
              title={t('tokens.empty')}
              description={t('tokens.emptyDesc')}
              action={{ label: t('tokens.create'), onClick: () => setShowCreate(true), variant: 'primary' }}
            />
          ) : (
            <div className="overflow-x-auto -mx-2">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-fg-subtle border-b border-border">
                    <th className="px-3 py-2 font-medium">{t('tokens.name')}</th>
                    <th className="px-3 py-2 font-medium">{t('tokens.prefix')}</th>
                    <th className="px-3 py-2 font-medium">{t('tokens.scopes')}</th>
                    <th className="px-3 py-2 font-medium">{t('tokens.lastUsed')}</th>
                    <th className="px-3 py-2 font-medium text-right">{t('common.actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((tok) => (
                    <tr key={tok.id} className="border-b border-border/40 hover:bg-bg-sunken/40">
                      <td className="px-3 py-3">
                        <span className="font-medium text-fg">{tok.name}</span>
                        {tok.revoked && <Badge variant="danger" className="ml-2">{t('tokens.statusRevoked')}</Badge>}
                      </td>
                      <td className="px-3 py-3 text-fg-muted text-xs font-mono">{tok.token_prefix}…</td>
                      <td className="px-3 py-3">
                        <div className="flex flex-wrap gap-1">
                          {(tok.scopes || []).map((s) => (
                            <Badge key={s} variant="muted">{s}</Badge>
                          ))}
                        </div>
                      </td>
                      <td className="px-3 py-3 text-fg-muted text-xs">
                        {tok.last_used_at ? formatDate(tok.last_used_at) : t('tokens.never')}
                      </td>
                      <td className="px-3 py-3 text-right">
                        <Button
                          size="sm"
                          variant="ghost"
                          className="text-danger hover:text-danger"
                          disabled={tok.revoked}
                          onClick={() => setRevoking(tok)}
                        >
                          <Trash2 size={14} />
                          {t('tokens.revoke')}
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

      <Modal
        open={showCreate}
        onClose={() => { setShowCreate(false); setCreateError('') }}
        title={t('tokens.create')}
        size="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => { setShowCreate(false); setCreateError('') }}>
              {t('common.cancel')}
            </Button>
            <Button variant="primary" onClick={handleCreate} loading={createMutation.isPending}>
              {t('common.save')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('tokens.name')}</label>
            <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} autoComplete="off" />
          </div>
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('tokens.scopes')}</label>
            <div className="flex flex-wrap gap-3">
              {SCOPE_OPTIONS.map((scope) => (
                <label key={scope} className="flex items-center gap-2 text-sm text-fg">
                  <input
                    type="checkbox"
                    checked={form.scopes.includes(scope)}
                    onChange={() => toggleScope(scope)}
                  />
                  <code className="font-mono text-xs">{scope}</code>
                </label>
              ))}
            </div>
            <p className="text-xs text-fg-subtle mt-1">{t('tokens.scopesHint')}</p>
          </div>
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('tokens.rateLimit')}</label>
            <Input
              type="number"
              value={form.rate_limit_per_min}
              onChange={(e) => setForm({ ...form, rate_limit_per_min: Number(e.target.value) })}
            />
          </div>
          <label className="flex items-center gap-2 text-sm text-fg">
            <input
              type="checkbox"
              checked={form.never_expires}
              onChange={(e) => setForm({ ...form, never_expires: e.target.checked })}
            />
            {t('tokens.neverExpires')}
          </label>
          {createError && (
            <div className="text-sm text-danger bg-danger/10 border border-danger/30 rounded-lg px-3 py-2">
              {createError}
            </div>
          )}
        </div>
      </Modal>

      <Modal
        open={issued !== null}
        onClose={() => setIssued(null)}
        title={t('tokens.plainOnceTitle')}
        size="md"
        footer={
          <Button variant="primary" onClick={() => setIssued(null)}>
            {t('common.close')}
          </Button>
        }
      >
        <div className="space-y-3">
          <p className="text-sm text-warning bg-warning/10 border border-warning/30 rounded-lg px-3 py-2">
            {t('tokens.plainOnceWarning')}
          </p>
          <div className="flex items-center gap-2">
            <code className="flex-1 font-mono text-xs bg-bg-sunken rounded-lg px-3 py-2 break-all">
              {issued?.token_plain_once}
            </code>
            <Button size="sm" variant="secondary" onClick={copyToken}>
              {copied ? <Check size={14} /> : <Copy size={14} />}
              {copied ? t('tokens.copied') : t('tokens.copy')}
            </Button>
          </div>
          <p className="text-xs text-fg-subtle">{t('tokens.usageHint')}</p>
        </div>
      </Modal>

      <Confirm
        open={revoking !== null}
        title={t('tokens.confirmRevokeTitle')}
        message={revoking ? t('tokens.confirmRevokeMessage', { name: revoking.name }) : ''}
        confirmText={t('tokens.revoke')}
        variant="danger"
        onConfirm={() => { if (revoking) revokeMutation.mutate(revoking.id) }}
        onCancel={() => setRevoking(null)}
      />
    </div>
  )
}
