import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2, Edit2, Users as UsersIcon, FolderTree } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Modal, Confirm } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonTable } from '@/components/ui/Skeleton'
import { toast } from '@/components/ui/Toast'
import { groupsApi, type GroupRecord } from '@/api/groups'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'

function splitLines(value: string): string[] {
  return value.split('\n').map((s) => s.trim()).filter(Boolean)
}

export default function Groups() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const [showCreate, setShowCreate] = useState(false)
  const [createError, setCreateError] = useState('')
  const [form, setForm] = useState({ name: '', path_allowlist: '', member_ids: '' })

  const [editing, setEditing] = useState<GroupRecord | null>(null)
  const [editForm, setEditForm] = useState({ name: '', path_allowlist: '', member_ids: '' })

  const [deleting, setDeleting] = useState<GroupRecord | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['groups'],
    queryFn: () => groupsApi.list(),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['groups'] })

  const createMutation = useMutation({
    mutationFn: () => groupsApi.create({
      name: form.name.trim(),
      path_allowlist: splitLines(form.path_allowlist),
      member_ids: splitLines(form.member_ids),
    }),
    onSuccess: () => {
      toast({ type: 'success', title: t('groups.created') })
      setShowCreate(false)
      setForm({ name: '', path_allowlist: '', member_ids: '' })
      setCreateError('')
      invalidate()
    },
    onError: (err: Error) => setCreateError(resolveErrorText(err)),
  })

  const updateMutation = useMutation({
    mutationFn: () => {
      if (!editing) return Promise.reject(new Error('no target'))
      return groupsApi.update(editing.id, {
        name: editForm.name.trim(),
        path_allowlist: splitLines(editForm.path_allowlist),
        member_ids: splitLines(editForm.member_ids),
      })
    },
    onSuccess: () => {
      toast({ type: 'success', title: t('groups.updated') })
      setEditing(null)
      invalidate()
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.saveFailed'), description: resolveErrorText(err) })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string) => groupsApi.remove(id),
    onSuccess: () => {
      toast({ type: 'success', title: t('groups.deleted') })
      setDeleting(null)
      invalidate()
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.saveFailed'), description: resolveErrorText(err) })
    },
  })

  const rows = data?.groups || []

  const startEdit = (g: GroupRecord) => {
    setEditing(g)
    setEditForm({
      name: g.name,
      path_allowlist: (g.path_allowlist || []).join('\n'),
      member_ids: (g.member_ids || []).join('\n'),
    })
  }

  const handleCreate = () => {
    if (!form.name.trim()) {
      setCreateError(t('groups.nameRequired'))
      return
    }
    setCreateError('')
    createMutation.mutate()
  }

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-5xl mx-auto page-enter">
      <div className="stagger-1 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <FolderTree size={20} className="text-accent" />
            {t('groups.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('groups.subtitle')}</p>
        </div>
        <Button variant="primary" onClick={() => setShowCreate(true)}>
          <Plus size={16} />
          {t('groups.create')}
        </Button>
      </div>

      <Card className="stagger-2 card-hover">
        <CardHeader>
          <div className="text-sm font-semibold text-fg">{t('groups.listTitle')}</div>
          <div className="text-xs text-fg-subtle">{t('groups.totalGroups', { count: data?.total ?? 0 })}</div>
        </CardHeader>
        <CardBody>
          {isLoading ? (
            <SkeletonTable rows={4} columns={4} />
          ) : rows.length === 0 ? (
            <EmptyState
              title={t('groups.empty')}
              description={t('groups.emptyDesc')}
              action={{ label: t('groups.create'), onClick: () => setShowCreate(true), variant: 'primary' }}
            />
          ) : (
            <div className="overflow-x-auto -mx-2">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-fg-subtle border-b border-border">
                    <th className="px-3 py-2 font-medium">{t('groups.name')}</th>
                    <th className="px-3 py-2 font-medium">{t('groups.pathAllowlist')}</th>
                    <th className="px-3 py-2 font-medium">{t('groups.members')}</th>
                    <th className="px-3 py-2 font-medium text-right">{t('common.actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((g) => (
                    <tr key={g.id} className="border-b border-border/40 hover:bg-bg-sunken/40">
                      <td className="px-3 py-3 font-medium text-fg">{g.name}</td>
                      <td className="px-3 py-3 text-fg-muted text-xs">
                        {(g.path_allowlist || []).length === 0 ? (
                          <span className="text-fg-subtle">—</span>
                        ) : (
                          (g.path_allowlist || []).map((p, i) => (
                            <code key={i} className="block font-mono">{p}</code>
                          ))
                        )}
                      </td>
                      <td className="px-3 py-3">
                        <Badge variant="muted">
                          <UsersIcon size={12} className="inline mr-1" />
                          {(g.member_ids || []).length}
                        </Badge>
                      </td>
                      <td className="px-3 py-3 text-right">
                        <Button size="sm" variant="ghost" onClick={() => startEdit(g)}>
                          <Edit2 size={14} />
                          {t('common.edit')}
                        </Button>
                        <Button size="sm" variant="ghost" className="ml-1 text-danger hover:text-danger" onClick={() => setDeleting(g)}>
                          <Trash2 size={14} />
                          {t('common.delete')}
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
        title={t('groups.create')}
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
            <label className="block text-sm font-medium text-fg mb-1">{t('groups.name')}</label>
            <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} autoComplete="off" />
          </div>
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('groups.pathAllowlist')}</label>
            <textarea
              className="w-full min-h-[100px] rounded-lg border border-border bg-bg-elevated px-3 py-2 text-sm font-mono"
              value={form.path_allowlist}
              onChange={(e) => setForm({ ...form, path_allowlist: e.target.value })}
              placeholder={t('groups.pathAllowlistPlaceholder')}
            />
            <p className="text-xs text-fg-subtle mt-1">{t('groups.pathAllowlistHint')}</p>
          </div>
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('groups.members')}</label>
            <textarea
              className="w-full min-h-[80px] rounded-lg border border-border bg-bg-elevated px-3 py-2 text-sm font-mono"
              value={form.member_ids}
              onChange={(e) => setForm({ ...form, member_ids: e.target.value })}
              placeholder={t('groups.membersPlaceholder')}
            />
            <p className="text-xs text-fg-subtle mt-1">{t('groups.membersHint')}</p>
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
        title={t('groups.edit')}
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
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('groups.name')}</label>
            <Input value={editForm.name} onChange={(e) => setEditForm({ ...editForm, name: e.target.value })} autoComplete="off" />
          </div>
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('groups.pathAllowlist')}</label>
            <textarea
              className="w-full min-h-[100px] rounded-lg border border-border bg-bg-elevated px-3 py-2 text-sm font-mono"
              value={editForm.path_allowlist}
              onChange={(e) => setEditForm({ ...editForm, path_allowlist: e.target.value })}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('groups.members')}</label>
            <textarea
              className="w-full min-h-[80px] rounded-lg border border-border bg-bg-elevated px-3 py-2 text-sm font-mono"
              value={editForm.member_ids}
              onChange={(e) => setEditForm({ ...editForm, member_ids: e.target.value })}
            />
          </div>
        </div>
      </Modal>

      <Confirm
        open={deleting !== null}
        title={t('groups.confirmDeleteTitle')}
        message={deleting ? t('groups.confirmDeleteMessage', { name: deleting.name }) : ''}
        confirmText={t('common.delete')}
        variant="danger"
        onConfirm={() => { if (deleting) deleteMutation.mutate(deleting.id) }}
        onCancel={() => setDeleting(null)}
      />
    </div>
  )
}
