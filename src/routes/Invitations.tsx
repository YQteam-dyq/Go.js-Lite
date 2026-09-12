import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2, MailPlus, Link2 } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Modal, Confirm } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonTable } from '@/components/ui/Skeleton'
import { toast } from '@/components/ui/Toast'
import { invitationsApi, type InvitationRecord, type InvitationRole } from '@/api/invitations'
import { useI18n } from '@/hooks/useI18n'
import { useFormat } from '@/lib/format'
import { resolveErrorText } from '@/lib/errorMessages'

const ROLES: InvitationRole[] = ['viewer', 'operator', 'admin']

function statusVariant(status: string): 'warning' | 'success' | 'muted' | 'danger' {
  if (status === 'accepted') return 'success'
  if (status === 'pending') return 'warning'
  if (status === 'revoked') return 'danger'
  return 'muted'
}

function splitLines(value: string): string[] {
  return value.split('\n').map((s) => s.trim()).filter(Boolean)
}

export default function Invitations() {
  const { t } = useI18n()
  const { formatRelativeTime, formatDate } = useFormat()
  const queryClient = useQueryClient()

  const [showCreate, setShowCreate] = useState(false)
  const [createError, setCreateError] = useState('')
  const [form, setForm] = useState({
    email: '',
    role: 'viewer' as InvitationRole,
    path_allowlist: '',
    groups: '',
    message: '',
  })
  const [created, setCreated] = useState<InvitationRecord | null>(null)
  const [revoking, setRevoking] = useState<InvitationRecord | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['invitations'],
    queryFn: () => invitationsApi.list(),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['invitations'] })

  const createMutation = useMutation({
    mutationFn: () => invitationsApi.create({
      email: form.email.trim(),
      role: form.role,
      path_allowlist: splitLines(form.path_allowlist),
      groups: splitLines(form.groups),
      message: form.message.trim(),
    }),
    onSuccess: (row) => {
      toast({ type: 'success', title: t('invitations.created') })
      setShowCreate(false)
      setForm({ email: '', role: 'viewer', path_allowlist: '', groups: '', message: '' })
      setCreateError('')
      setCreated(row)
      invalidate()
    },
    onError: (err: Error) => setCreateError(resolveErrorText(err)),
  })

  const revokeMutation = useMutation({
    mutationFn: (id: string) => invitationsApi.revoke(id),
    onSuccess: () => {
      toast({ type: 'success', title: t('invitations.revoked') })
      setRevoking(null)
      invalidate()
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.deleteFailed'), description: resolveErrorText(err) })
    },
  })

  const rows = data?.invitations || []

  const handleCreate = () => {
    if (!form.email.trim()) {
      setCreateError(t('invitations.emailRequired'))
      return
    }
    setCreateError('')
    createMutation.mutate()
  }

  const copyLink = async (link: string) => {
    try {
      await navigator.clipboard.writeText(link)
      toast({ type: 'success', title: t('common.copied') })
    } catch {
      toast({ type: 'error', title: t('common.failure') })
    }
  }

  const inviteLink = created?.invite_url
    ? `${window.location.origin}${created.invite_url}`
    : ''

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-5xl mx-auto page-enter">
      <div className="stagger-1 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <MailPlus size={20} className="text-accent" />
            {t('invitations.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('invitations.subtitle')}</p>
        </div>
        <Button variant="primary" onClick={() => setShowCreate(true)}>
          <Plus size={16} />
          {t('invitations.create')}
        </Button>
      </div>

      <Card className="stagger-2 card-hover">
        <CardHeader>
          <div className="text-sm font-semibold text-fg">{t('invitations.listTitle')}</div>
          <div className="text-xs text-fg-subtle">{t('invitations.totalInvites', { count: data?.total ?? 0 })}</div>
        </CardHeader>
        <CardBody>
          {isLoading ? (
            <SkeletonTable rows={4} columns={5} />
          ) : rows.length === 0 ? (
            <EmptyState
              title={t('invitations.empty')}
              description={t('invitations.emptyDesc')}
              action={{ label: t('invitations.create'), onClick: () => setShowCreate(true), variant: 'primary' }}
            />
          ) : (
            <div className="overflow-x-auto -mx-2">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-fg-subtle border-b border-border">
                    <th className="px-3 py-2 font-medium">{t('invitations.email')}</th>
                    <th className="px-3 py-2 font-medium">{t('invitations.role')}</th>
                    <th className="px-3 py-2 font-medium">{t('invitations.status')}</th>
                    <th className="px-3 py-2 font-medium">{t('invitations.expiresAt')}</th>
                    <th className="px-3 py-2 font-medium text-right">{t('common.actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id} className="border-b border-border/40 hover:bg-bg-sunken/40">
                      <td className="px-3 py-3 font-medium text-fg">{row.email}</td>
                      <td className="px-3 py-3"><Badge variant="muted">{row.role}</Badge></td>
                      <td className="px-3 py-3">
                        <Badge variant={statusVariant(row.status)}>{t(`invitations.status_${row.status}`)}</Badge>
                      </td>
                      <td className="px-3 py-3 text-fg-muted text-xs" title={formatDate(row.expires_at)}>
                        {row.status === 'pending' ? formatRelativeTime(row.expires_at) : '—'}
                      </td>
                      <td className="px-3 py-3 text-right">
                        {row.status === 'pending' && (
                          <Button size="sm" variant="ghost" className="text-danger hover:text-danger" onClick={() => setRevoking(row)}>
                            <Trash2 size={14} />
                            {t('invitations.revoke')}
                          </Button>
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
        open={showCreate}
        onClose={() => { setShowCreate(false); setCreateError('') }}
        title={t('invitations.create')}
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
            <label className="block text-sm font-medium text-fg mb-1">{t('invitations.email')}</label>
            <Input value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} autoComplete="off" />
          </div>
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('invitations.role')}</label>
            <select
              className="w-full h-10 rounded-lg border border-border bg-bg-elevated px-3 text-sm"
              value={form.role}
              onChange={(e) => setForm({ ...form, role: e.target.value as InvitationRole })}
            >
              {ROLES.map((r) => (
                <option key={r} value={r}>{r}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('invitations.pathAllowlist')}</label>
            <textarea
              className="w-full min-h-[80px] rounded-lg border border-border bg-bg-elevated px-3 py-2 text-sm font-mono"
              value={form.path_allowlist}
              onChange={(e) => setForm({ ...form, path_allowlist: e.target.value })}
              placeholder={t('invitations.pathAllowlistPlaceholder')}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('invitations.groups')}</label>
            <textarea
              className="w-full min-h-[60px] rounded-lg border border-border bg-bg-elevated px-3 py-2 text-sm font-mono"
              value={form.groups}
              onChange={(e) => setForm({ ...form, groups: e.target.value })}
              placeholder={t('invitations.groupsPlaceholder')}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('invitations.message')}</label>
            <Input value={form.message} onChange={(e) => setForm({ ...form, message: e.target.value })} autoComplete="off" />
          </div>
          {createError && (
            <div className="text-sm text-danger bg-danger/10 border border-danger/30 rounded-lg px-3 py-2">
              {createError}
            </div>
          )}
        </div>
      </Modal>

      <Modal
        open={created !== null}
        onClose={() => setCreated(null)}
        title={t('invitations.linkTitle')}
        size="md"
        footer={
          <Button variant="secondary" onClick={() => setCreated(null)}>{t('common.close')}</Button>
        }
      >
        <div className="space-y-3">
          <div className="text-sm text-warning bg-warning/10 border border-warning/30 rounded-lg px-3 py-2">
            {t('invitations.linkWarning')}
          </div>
          <div className="text-xs text-fg-muted">{t('invitations.expiresIn', { hours: 24 })}</div>
          <div className="flex items-center gap-2">
            <code className="flex-1 min-w-0 truncate rounded-lg bg-bg-sunken px-3 py-2 text-xs font-mono" title={inviteLink}>
              {inviteLink}
            </code>
            <Button variant="primary" size="sm" onClick={() => copyLink(inviteLink)}>
              <Link2 size={14} />
              {t('common.copy')}
            </Button>
          </div>
        </div>
      </Modal>

      <Confirm
        open={revoking !== null}
        title={t('invitations.confirmRevokeTitle')}
        message={revoking ? t('invitations.confirmRevokeMessage', { email: revoking.email }) : ''}
        confirmText={t('invitations.revoke')}
        variant="danger"
        onConfirm={() => { if (revoking) revokeMutation.mutate(revoking.id) }}
        onCancel={() => setRevoking(null)}
      />
    </div>
  )
}
