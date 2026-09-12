import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2, Shield, Edit2 } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Modal, Confirm } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonTable } from '@/components/ui/Skeleton'
import { AvatarBadge } from '@/components/ui/AvatarBadge'
import { toast } from '@/components/ui/Toast'
import { usersApi, type UserRecord, type UserRole } from '@/api/users'
import { useFormat } from '@/lib/format'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'

const ROLE_META: Record<UserRole, { labelKey: string; variant: 'danger' | 'warning' | 'success' }> = {
  admin:    { labelKey: 'users.roleAdmin',    variant: 'danger' },
  operator: { labelKey: 'users.roleOperator', variant: 'warning' },
  viewer:   { labelKey: 'users.roleViewer',   variant: 'success' },
}

export default function Users() {
  const { t } = useI18n()
  const { formatDate } = useFormat()
  const queryClient = useQueryClient()

  const [showCreate, setShowCreate] = useState(false)
  const [creating, setCreating] = useState(false)
  const [createError, setCreateError] = useState('')
  const [form, setForm] = useState({
    username: '',
    password: '',
    role: 'viewer' as UserRole,
    path_allowlist: '',
    permissions_boost: '',
  })

  const [editing, setEditing] = useState<UserRecord | null>(null)
  const [editForm, setEditForm] = useState({
    username: '',
    role: 'viewer' as UserRole,
    path_allowlist: '',
    permissions_boost: '',
    disabled: false,
    password: '',
  })

  const [deleting, setDeleting] = useState<UserRecord | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['users'],
    queryFn: () => usersApi.list(),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['users'] })

  const createMutation = useMutation({
    mutationFn: () => usersApi.create({
      username: form.username.trim(),
      password: form.password,
      role: form.role,
      path_allowlist: form.path_allowlist.split('\n').map(s => s.trim()).filter(Boolean),
      permissions_boost: form.permissions_boost.split('\n').map(s => s.trim()).filter(Boolean),
    }),
    onSuccess: () => {
      toast({ type: 'success', title: t('users.created') })
      setShowCreate(false)
      setForm({ username: '', password: '', role: 'viewer', path_allowlist: '', permissions_boost: '' })
      setCreateError('')
      invalidate()
    },
    onError: (err: Error) => {
      setCreateError(resolveErrorText(err))
    },
    onSettled: () => setCreating(false),
  })

  const updateMutation = useMutation({
    mutationFn: () => {
      if (!editing) return Promise.reject(new Error('no target'))
      const payload: any = {
        username: editForm.username.trim(),
        role: editForm.role,
        path_allowlist: editForm.path_allowlist.split('\n').map(s => s.trim()).filter(Boolean),
        permissions_boost: editForm.permissions_boost.split('\n').map(s => s.trim()).filter(Boolean),
        disabled: editForm.disabled,
      }
      if (editForm.password) payload.password = editForm.password
      return usersApi.update(editing.id, payload)
    },
    onSuccess: () => {
      toast({ type: 'success', title: t('users.updated') })
      setEditing(null)
      invalidate()
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.saveFailed'), description: resolveErrorText(err) })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string) => usersApi.remove(id),
    onSuccess: () => {
      toast({ type: 'success', title: t('users.deleted') })
      setDeleting(null)
      invalidate()
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.delete') + ' ' + t('common.failed'), description: resolveErrorText(err) })
    },
  })

  const rows = useMemo(() => data?.users || [], [data])

  const startEdit = (u: UserRecord) => {
    setEditing(u)
    setEditForm({
      username: u.username,
      role: u.role,
      path_allowlist: (u.path_allowlist || []).join('\n'),
      permissions_boost: (u.permissions_boost || []).join('\n'),
      disabled: !!u.disabled,
      password: '',
    })
  }

  const handleCreate = () => {
    if (!form.username.trim() || !form.password) {
      setCreateError(t('users.usernameAndPasswordRequired'))
      return
    }
    setCreating(true)
    setCreateError('')
    createMutation.mutate()
  }

  const handleDelete = () => {
    if (!deleting) return
    deleteMutation.mutate(deleting.id)
  }

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-5xl mx-auto page-enter">
      <div className="stagger-1 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <Shield size={20} className="text-accent" />
            {t('users.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('users.subtitle')}</p>
        </div>
        <Button variant="primary" onClick={() => setShowCreate(true)}>
          <Plus size={16} />
          {t('users.create')}
        </Button>
      </div>

      <Card className="stagger-2 card-hover">
        <CardHeader>
          <div className="text-sm font-semibold text-fg">{t('users.listTitle')}</div>
          <div className="text-xs text-fg-subtle">{t('users.totalUsers', { count: data?.total ?? 0 })}</div>
        </CardHeader>
        <CardBody>
          {isLoading ? (
            <SkeletonTable rows={4} columns={5} />
          ) : rows.length === 0 ? (
            <EmptyState
              title={t('users.empty')}
              description={t('users.emptyDesc')}
              action={{
                label: t('users.create'),
                onClick: () => setShowCreate(true),
                variant: 'primary',
              }}
            />
          ) : (
            <div className="overflow-x-auto -mx-2">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-fg-subtle border-b border-border">
                    <th className="px-3 py-2 font-medium" scope="col">{t('common.user')}</th>
                    <th className="px-3 py-2 font-medium" scope="col">{t('users.role')}</th>
                    <th className="px-3 py-2 font-medium" scope="col">{t('users.pathAllowlist')}</th>
                    <th className="px-3 py-2 font-medium" scope="col">{t('users.lastLogin')}</th>
                    <th className="px-3 py-2 font-medium text-right" scope="col">{t('common.actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((u) => {
                    const meta2 = ROLE_META[u.role]
                    return (
                      <tr key={u.id} className="border-b border-border/40 hover:bg-bg-sunken/40">
                        <td className="px-3 py-3">
                          <div className="flex items-center gap-3">
                            <AvatarBadge username={u.username} color={u.avatar_color} size="sm" />
                            <span className="font-medium text-fg">{u.username}</span>
                          </div>
                        </td>
                        <td className="px-3 py-3">
                          <Badge variant={meta2.variant}>{t(meta2.labelKey)}</Badge>
                          {u.disabled && (
                            <Badge variant="muted" className="ml-1">{t('users.disabled')}</Badge>
                          )}
                          {(u.permissions_boost || []).length > 0 && (
                            <Badge variant="accent" className="ml-1">
                              {t('users.permissionsBoostCount', { count: (u.permissions_boost || []).length })}
                            </Badge>
                          )}
                        </td>
                        <td className="px-3 py-3 text-fg-muted text-xs">
                          {(u.path_allowlist || []).length === 0 ? (
                            <span className="text-fg-subtle">—</span>
                          ) : (
                            (u.path_allowlist || []).map((p, i) => (
                              <code key={i} className="block font-mono">{p}</code>
                            ))
                          )}
                        </td>
                        <td className="px-3 py-3 text-fg-muted text-xs">
                          {u.last_login_at ? formatDate(u.last_login_at) : '—'}
                        </td>
                        <td className="px-3 py-3 text-right">
                          <Button size="sm" variant="ghost" onClick={() => startEdit(u)}>
                            <Edit2 size={14} />
                            {t('common.edit')}
                          </Button>
                          <Button size="sm" variant="ghost" className="ml-1 text-danger hover:text-danger" onClick={() => setDeleting(u)}>
                            <Trash2 size={14} />
                            {t('common.delete')}
                          </Button>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      <Modal
        open={showCreate}
        onClose={() => { setShowCreate(false); setCreateError('') }}
        title={t('users.create')}
        size="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => { setShowCreate(false); setCreateError('') }}>
              {t('common.cancel')}
            </Button>
            <Button variant="primary" onClick={handleCreate} loading={creating}>
              {t('common.save')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('users.username')}</label>
            <Input value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} autoComplete="off" />
          </div>
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('users.password')}</label>
            <Input type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} autoComplete="new-password" />
            <p className="text-xs text-fg-subtle mt-1">{t('users.passwordHint')}</p>
          </div>
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('users.role')}</label>
            <select
              className="w-full h-10 rounded-lg border border-border bg-bg-elevated px-3 text-sm"
              value={form.role}
              onChange={(e) => setForm({ ...form, role: e.target.value as UserRole })}
            >
              <option value="viewer">{t('users.roleViewer')}</option>
              <option value="operator">{t('users.roleOperator')}</option>
              <option value="admin">{t('users.roleAdmin')}</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('users.pathAllowlist')}</label>
            <textarea
              className="w-full min-h-[100px] rounded-lg border border-border bg-bg-elevated px-3 py-2 text-sm font-mono"
              value={form.path_allowlist}
              onChange={(e) => setForm({ ...form, path_allowlist: e.target.value })}
              placeholder={t('users.pathAllowlistPlaceholder')}
            />
            <p className="text-xs text-fg-subtle mt-1">{t('users.pathAllowlistHint')}</p>
          </div>
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('users.permissionsBoost')}</label>
            <textarea
              className="w-full min-h-[80px] rounded-lg border border-border bg-bg-elevated px-3 py-2 text-sm font-mono"
              value={form.permissions_boost}
              onChange={(e) => setForm({ ...form, permissions_boost: e.target.value })}
              placeholder={t('users.permissionsBoostPlaceholder')}
            />
            <p className="text-xs text-fg-subtle mt-1">{t('users.permissionsBoostHint')}</p>
          </div>
          {createError && (
            <div className="text-sm text-danger bg-danger/10 border border-danger/30 rounded-lg px-3 py-2">
              {createError}
            </div>
          )}
        </div>
      </Modal>

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={t('users.edit')}
        size="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setEditing(null)}>
              {t('common.cancel')}
            </Button>
            <Button variant="primary" onClick={() => updateMutation.mutate()} loading={updateMutation.isPending}>
              {t('common.save')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          {editing && (
            <>
              <div className="flex items-center gap-3 pb-2 border-b border-border/40">
                <AvatarBadge username={editing.username} color={editing.avatar_color} size="md" />
                <div>
                  <div className="font-medium text-fg">{editing.username}</div>
                  <div className="text-xs text-fg-subtle">{editing.id}</div>
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-fg mb-1">{t('users.username')}</label>
                <Input value={editForm.username} onChange={(e) => setEditForm({ ...editForm, username: e.target.value })} autoComplete="off" />
              </div>
              <div>
                <label className="block text-sm font-medium text-fg mb-1">{t('users.newPassword')}</label>
                <Input type="password" value={editForm.password} onChange={(e) => setEditForm({ ...editForm, password: e.target.value })} placeholder={t('users.leaveBlankKeepPassword')} autoComplete="new-password" />
              </div>
              <div>
                <label className="block text-sm font-medium text-fg mb-1">{t('users.role')}</label>
                <select
                  className="w-full h-10 rounded-lg border border-border bg-bg-elevated px-3 text-sm"
                  value={editForm.role}
                  onChange={(e) => setEditForm({ ...editForm, role: e.target.value as UserRole })}
                >
                  <option value="viewer">{t('users.roleViewer')}</option>
                  <option value="operator">{t('users.roleOperator')}</option>
                  <option value="admin">{t('users.roleAdmin')}</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-fg mb-1">{t('users.pathAllowlist')}</label>
                <textarea
                  className="w-full min-h-[100px] rounded-lg border border-border bg-bg-elevated px-3 py-2 text-sm font-mono"
                  value={editForm.path_allowlist}
                  onChange={(e) => setEditForm({ ...editForm, path_allowlist: e.target.value })}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-fg mb-1">{t('users.permissionsBoost')}</label>
                <textarea
                  className="w-full min-h-[80px] rounded-lg border border-border bg-bg-elevated px-3 py-2 text-sm font-mono"
                  value={editForm.permissions_boost}
                  onChange={(e) => setEditForm({ ...editForm, permissions_boost: e.target.value })}
                  placeholder={t('users.permissionsBoostPlaceholder')}
                />
                <p className="text-xs text-fg-subtle mt-1">{t('users.permissionsBoostHint')}</p>
              </div>
              <label className="flex items-center gap-2 text-sm text-fg">
                <input
                  type="checkbox"
                  checked={editForm.disabled}
                  onChange={(e) => setEditForm({ ...editForm, disabled: e.target.checked })}
                />
                {t('users.disabled')}
              </label>
            </>
          )}
        </div>
      </Modal>

      <Confirm
        open={deleting !== null}
        title={t('users.confirmDeleteTitle')}
        message={deleting ? t('users.confirmDeleteMessage', { username: deleting.username }) : ''}
        confirmText={t('common.delete')}
        variant="danger"
        onConfirm={handleDelete}
        onCancel={() => setDeleting(null)}
      />
    </div>
  )
}