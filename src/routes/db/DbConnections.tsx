import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Plus, Database, Server, Trash2, Edit2, Eye, AlertCircle, RefreshCw } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { Badge } from '@/components/ui/Badge'
import { dbApi } from '@/api/db'
import { toast } from '@/components/ui/Toast'
import { Modal, Confirm } from '@/components/ui/Modal'
import { Link } from 'react-router-dom'
import type { DbConnection, DbConnectionInput } from '@shared/types'
import { useI18n } from '@/hooks/useI18n'

export default function DbConnections() {
  const { t } = useI18n()
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['db-connections'],
    queryFn: () => dbApi.listConnections(),
  })

  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<DbConnection | null>(null)
  const [form, setForm] = useState<DbConnectionInput>({
    name: '',
    host: 'localhost',
    port: 3306,
    username: '',
    password: '',
    database: '',
  })
  const [deleteId, setDeleteId] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const handleAdd = () => {
    setEditing(null)
    setForm({
      name: '',
      host: 'localhost',
      port: 3306,
      username: '',
      password: '',
      database: '',
    })
    setModalOpen(true)
  }

  const handleEdit = (conn: DbConnection) => {
    setEditing(conn)
    setForm({
      name: conn.name,
      host: conn.host,
      port: conn.port,
      username: conn.username,
      password: '',
      database: conn.database,
    })
    setModalOpen(true)
  }

  const handleSave = async () => {
    if (!form.name || !form.host || !form.username) {
      toast({ type: 'error', title: t('common.requiredFields') })
      return
    }
    setSaving(true)
    try {
      if (editing) {
        await dbApi.updateConnection(editing.id, form)
        toast({ type: 'success', title: t('common.updated') })
      } else {
        await dbApi.addConnection(form)
        toast({ type: 'success', title: t('common.added') })
      }
      setModalOpen(false)
      refetch()
    } catch (err: unknown) {
      toast({
        type: 'error',
        title: t('common.saveFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteId) return
    try {
      await dbApi.deleteConnection(deleteId)
      toast({ type: 'success', title: t('common.deleted') })
      setDeleteId(null)
      refetch()
    } catch (err: unknown) {
      toast({
        type: 'error',
        title: t('common.deleteFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    }
  }

  return (
    <div className="p-4 md:p-6 space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg">{t('db.title')}</h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('db.manageMysql')}</p>
        </div>
        <Button onClick={handleAdd} size="sm">
          <Plus size={16} />
          {t('db.newConnection')}
        </Button>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <Spinner size="lg" />
        </div>
      ) : error ? (
        <Card className="p-8 text-center">
          <div className="w-14 h-14 mx-auto mb-4 rounded-full bg-danger/10 text-danger flex items-center justify-center">
            <AlertCircle size={28} />
          </div>
          <p className="text-sm font-medium text-fg mb-1">{t('db.loadFailed')}</p>
          <p className="text-xs text-fg-muted mb-5">
            {error instanceof Error ? error.message : t('common.unknownError')}
          </p>
          <Button variant="secondary" size="sm" onClick={() => refetch()}>
            <RefreshCw size={16} />
            {t('common.retry')}
          </Button>
        </Card>
      ) : !data || data.length === 0 ? (
        <Card className="p-12 text-center">
          <div className="w-16 h-16 mx-auto mb-4 rounded-full bg-bg-sunken flex items-center justify-center text-fg-subtle">
            <Database size={28} />
          </div>
          <p className="text-sm text-fg-muted mb-4">{t('db.noConnections')}</p>
          <Button onClick={handleAdd} size="sm">
            <Plus size={16} />
            {t('db.addFirst')}
          </Button>
        </Card>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {data.map((conn) => (
            <Card key={conn.id} className="hover:border-border-strong transition-colors">
              <CardHeader className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-lg bg-accent/10 text-accent flex items-center justify-center">
                  <Database size={20} />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="font-medium text-fg truncate">{conn.name}</div>
                  <div className="text-xs text-fg-subtle truncate">
                    {conn.username}@{conn.host}:{conn.port}
                  </div>
                </div>
                <Badge variant="muted">{conn.database}</Badge>
              </CardHeader>
              <CardBody className="flex gap-2">
                <Link to={`${conn.id}/browse`} className="flex-1">
                  <Button variant="secondary" size="sm" className="w-full">
                    <Eye size={14} />
                    {t('db.browse')}
                  </Button>
                </Link>
                <Link to={`${conn.id}/sql`} className="flex-1">
                  <Button variant="secondary" size="sm" className="w-full">
                    <Server size={14} />
                    {t('db.sql')}
                  </Button>
                </Link>
                <Button
                  variant="ghost"
                  size="icon-sm"
                  onClick={() => handleEdit(conn)}
                  aria-label={t('common.edit')}
                >
                  <Edit2 size={16} />
                </Button>
                <Button
                  variant="ghost"
                  size="icon-sm"
                  onClick={() => setDeleteId(conn.id)}
                  aria-label={t('common.delete')}
                  className="text-danger hover:text-danger"
                >
                  <Trash2 size={16} />
                </Button>
              </CardBody>
            </Card>
          ))}
        </div>
      )}

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={editing ? t('db.editConnection') : t('db.newConnection')}
        size="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)}>
              {t('common.cancel')}
            </Button>
            <Button onClick={handleSave} loading={saving}>
              {t('common.save')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-fg mb-1.5">{t('db.connectionName')}</label>
            <Input
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              placeholder={t('db.connectionNamePlaceholder')}
            />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-sm font-medium text-fg mb-1.5">{t('db.host')}</label>
              <Input
                value={form.host}
                onChange={(e) => setForm({ ...form, host: e.target.value })}
                placeholder="localhost"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-fg mb-1.5">{t('db.port')}</label>
              <Input
                type="number"
                value={form.port}
                onChange={(e) => setForm({ ...form, port: Number(e.target.value) })}
                inputMode="numeric"
              />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-sm font-medium text-fg mb-1.5">{t('db.username')}</label>
              <Input
                value={form.username}
                onChange={(e) => setForm({ ...form, username: e.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-fg mb-1.5">{t('db.password')}</label>
              <Input
                type="password"
                value={form.password}
                onChange={(e) => setForm({ ...form, password: e.target.value })}
                placeholder={editing ? t('db.leaveBlank') : ''}
              />
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-fg mb-1.5">{t('db.defaultDatabase')}</label>
            <Input
              value={form.database}
              onChange={(e) => setForm({ ...form, database: e.target.value })}
            />
          </div>
        </div>
      </Modal>

      <Confirm
        open={!!deleteId}
        title={t('db.deleteConnection')}
        message={t('db.deleteConfirmMessage')}
        confirmText={t('common.delete')}
        variant="danger"
        onConfirm={handleDelete}
        onCancel={() => setDeleteId(null)}
      />
    </div>
  )
}
