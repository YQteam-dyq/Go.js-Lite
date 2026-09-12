import { useCallback, useEffect, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, ArrowUpDown, Pencil, Plus, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Modal, Confirm } from '@/components/ui/Modal'
import { Spinner } from '@/components/ui/Spinner'
import { toast } from '@/components/ui/Toast'
import { dbApi } from '@/api/db'
import type { DbColumn } from '@shared/types'
import type { DbRow } from '@/api/db'

const PAGE_SIZE = 50

export default function TableDataEditor() {
  const { connId = '' } = useParams()
  const [searchParams] = useSearchParams()
  const database = searchParams.get('database') || ''
  const table = searchParams.get('table') || ''

  const [columns, setColumns] = useState<DbColumn[]>([])
  const [rows, setRows] = useState<DbRow[]>([])
  const [loading, setLoading] = useState(false)
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [totalPages, setTotalPages] = useState(1)
  const [sortField, setSortField] = useState('')
  const [sortOrder, setSortOrder] = useState<'ASC' | 'DESC'>('ASC')

  const [insertOpen, setInsertOpen] = useState(false)
  const [editOpen, setEditOpen] = useState(false)
  const [newRow, setNewRow] = useState<Record<string, string>>({})
  const [editingRow, setEditingRow] = useState<DbRow | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<DbRow | null>(null)
  const [saving, setSaving] = useState(false)

  const primaryKey = columns.find((column) => column.key === 'PRI')

  const loadStructure = useCallback(async () => {
    if (!connId || !database || !table) return
    try {
      const result = await dbApi.getStructure(connId, database, table)
      setColumns(result)
    } catch (error) {
      toast({
        type: 'error',
        title: 'Failed to load table structure',
        description: error instanceof Error ? error.message : 'Unknown error',
      })
    }
  }, [connId, database, table])

  const loadRows = useCallback(async () => {
    if (!connId || !database || !table) return
    setLoading(true)
    try {
      const result = await dbApi.tableData({
        connId,
        database,
        table,
        page,
        limit: PAGE_SIZE,
        sortField: sortField || undefined,
        sortOrder,
      })
      setRows(result.data)
      setTotal(result.pagination.total)
      setTotalPages(Math.max(1, result.pagination.totalPages))
    } catch (error) {
      toast({
        type: 'error',
        title: 'Failed to load table data',
        description: error instanceof Error ? error.message : 'Unknown error',
      })
    } finally {
      setLoading(false)
    }
  }, [connId, database, table, page, sortField, sortOrder])

  useEffect(() => {
    loadStructure()
  }, [loadStructure])

  useEffect(() => {
    loadRows()
  }, [loadRows])

  const handleSort = (field: string) => {
    if (sortField === field) {
      setSortOrder((prev) => (prev === 'ASC' ? 'DESC' : 'ASC'))
    } else {
      setSortField(field)
      setSortOrder('ASC')
    }
    setPage(1)
  }

  const openInsert = () => {
    setNewRow({})
    setInsertOpen(true)
  }

  const openEdit = (row: DbRow) => {
    setEditingRow({ ...row })
    setEditOpen(true)
  }

  const handleInsert = async () => {
    const payload: DbRow = {}
    Object.entries(newRow).forEach(([key, value]) => {
      if (value !== '') payload[key] = value
    })

    if (Object.keys(payload).length === 0) {
      toast({ type: 'warning', title: 'Provide at least one value' })
      return
    }

    setSaving(true)
    try {
      const result = await dbApi.insertRow({ connId, database, table, data: payload })
      toast({
        type: 'success',
        title: 'Row inserted',
        description: `Insert id: ${result.insertId === null ? 'n/a' : result.insertId}`,
      })
      setInsertOpen(false)
      setNewRow({})
      loadRows()
    } catch (error) {
      toast({
        type: 'error',
        title: 'Insert failed',
        description: error instanceof Error ? error.message : 'Unknown error',
      })
    } finally {
      setSaving(false)
    }
  }

  const handleUpdate = async () => {
    if (!editingRow || !primaryKey) return

    setSaving(true)
    try {
      const result = await dbApi.updateRow({
        connId,
        database,
        table,
        primaryKey: primaryKey.name,
        primaryKeyValue: editingRow[primaryKey.name] as string | number,
        data: editingRow,
      })
      toast({
        type: 'success',
        title: 'Row updated',
        description: `${result.affectedRows} row(s) affected`,
      })
      setEditOpen(false)
      setEditingRow(null)
      loadRows()
    } catch (error) {
      toast({
        type: 'error',
        title: 'Update failed',
        description: error instanceof Error ? error.message : 'Unknown error',
      })
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteTarget || !primaryKey) return

    setSaving(true)
    try {
      const result = await dbApi.deleteRow({
        connId,
        database,
        table,
        primaryKey: primaryKey.name,
        primaryKeyValue: deleteTarget[primaryKey.name] as string | number,
      })
      toast({
        type: 'success',
        title: 'Row deleted',
        description: `${result.affectedRows} row(s) affected`,
      })
      setDeleteTarget(null)
      loadRows()
    } catch (error) {
      toast({
        type: 'error',
        title: 'Delete failed',
        description: error instanceof Error ? error.message : 'Unknown error',
      })
    } finally {
      setSaving(false)
    }
  }

  const renderValue = (value: unknown) => {
    if (value === null || value === undefined) {
      return <span className="text-fg-subtle italic">NULL</span>
    }
    return <span className="font-mono text-xs">{String(value)}</span>
  }

  const rangeStart = total === 0 ? 0 : (page - 1) * PAGE_SIZE + 1
  const rangeEnd = Math.min(page * PAGE_SIZE, total)

  return (
    <div className="p-4 md:p-6 space-y-5">
      <div className="flex items-center gap-3">
        <Link
          to={`/db/${connId}/browse`}
          className="p-1.5 -ml-1.5 rounded-md text-fg-muted hover:text-fg hover:bg-fg/5 transition-colors"
        >
          <ArrowLeft size={18} />
        </Link>
        <div className="flex-1 min-w-0">
          <h1 className="text-lg font-semibold text-fg">Table data editor</h1>
          <p className="text-xs text-fg-subtle truncate">
            {database}.{table}
          </p>
        </div>
        <Button size="sm" onClick={openInsert} disabled={columns.length === 0}>
          <Plus size={16} />
          Add row
        </Button>
      </div>

      <Card>
        <CardHeader className="flex items-center justify-between gap-3">
          <CardTitle>Rows</CardTitle>
          <div className="flex items-center gap-2">
            <Badge variant="muted">{total} row(s)</Badge>
            <Button variant="secondary" size="sm" onClick={loadRows} loading={loading}>
              Refresh
            </Button>
          </div>
        </CardHeader>
        <CardBody>
          {loading ? (
            <div className="flex justify-center py-10">
              <Spinner />
            </div>
          ) : rows.length === 0 ? (
            <p className="py-8 text-center text-sm text-fg-muted">No rows in this table</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-fg-subtle">
                    {columns.map((column) => (
                      <th key={column.name} className="whitespace-nowrap px-3 py-2 font-medium">
                        <button
                          type="button"
                          className="inline-flex items-center gap-1 hover:text-fg"
                          onClick={() => handleSort(column.name)}
                        >
                          {column.name}
                          {column.key === 'PRI' && <Badge variant="accent">PK</Badge>}
                          {sortField === column.name && (
                            <span className="text-xs">{sortOrder === 'ASC' ? 'ASC' : 'DESC'}</span>
                          )}
                          <ArrowUpDown size={12} />
                        </button>
                      </th>
                    ))}
                    <th className="px-3 py-2 font-medium">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row, index) => (
                    <tr key={index} className="border-b border-border/60 hover:bg-fg/5">
                      {columns.map((column) => (
                        <td key={column.name} className="max-w-xs truncate px-3 py-2 text-fg">
                          {renderValue(row[column.name])}
                        </td>
                      ))}
                      <td className="px-3 py-2">
                        <div className="flex items-center gap-1">
                          <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Edit row"
                            onClick={() => openEdit(row)}
                          >
                            <Pencil size={14} />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Delete row"
                            onClick={() => setDeleteTarget(row)}
                          >
                            <Trash2 size={14} />
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <div className="mt-4 flex items-center justify-between gap-3">
            <span className="text-xs text-fg-subtle">
              Showing {rangeStart}-{rangeEnd} of {total}
            </span>
            <div className="flex items-center gap-2">
              <Button
                variant="secondary"
                size="sm"
                disabled={page <= 1}
                onClick={() => setPage((prev) => Math.max(1, prev - 1))}
              >
                Previous
              </Button>
              <span className="text-xs text-fg-muted">
                Page {page} of {totalPages}
              </span>
              <Button
                variant="secondary"
                size="sm"
                disabled={page >= totalPages}
                onClick={() => setPage((prev) => prev + 1)}
              >
                Next
              </Button>
            </div>
          </div>
        </CardBody>
      </Card>

      <Modal
        open={insertOpen}
        onClose={() => setInsertOpen(false)}
        title="Add row"
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setInsertOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleInsert} loading={saving}>
              Insert
            </Button>
          </>
        }
      >
        <div className="max-h-[60vh] space-y-3 overflow-y-auto">
          {columns.map((column) => (
            <label key={column.name} className="block space-y-1">
              <span className="text-xs font-medium text-fg-muted">
                {column.name}
                <span className="ml-1 text-fg-subtle">{column.type}</span>
              </span>
              <Input
                value={newRow[column.name] ?? ''}
                placeholder={column.default ? `Default: ${column.default}` : 'Leave empty to skip'}
                onChange={(event) =>
                  setNewRow((prev) => ({ ...prev, [column.name]: event.target.value }))
                }
              />
            </label>
          ))}
        </div>
      </Modal>

      <Modal
        open={editOpen}
        onClose={() => setEditOpen(false)}
        title="Edit row"
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setEditOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleUpdate} loading={saving}>
              Save
            </Button>
          </>
        }
      >
        <div className="max-h-[60vh] space-y-3 overflow-y-auto">
          {columns.map((column) => (
            <label key={column.name} className="block space-y-1">
              <span className="text-xs font-medium text-fg-muted">
                {column.name}
                <span className="ml-1 text-fg-subtle">{column.type}</span>
                {column.name === primaryKey?.name && <span className="ml-1">(primary key)</span>}
              </span>
              <Input
                value={editingRow ? String(editingRow[column.name] ?? '') : ''}
                onChange={(event) =>
                  setEditingRow((prev) =>
                    prev ? { ...prev, [column.name]: event.target.value } : prev,
                  )
                }
              />
            </label>
          ))}
        </div>
      </Modal>

      <Confirm
        open={deleteTarget !== null}
        title="Delete row"
        message="This action cannot be undone. Continue?"
        variant="danger"
        loading={saving}
        onCancel={() => setDeleteTarget(null)}
        onConfirm={handleDelete}
      />
    </div>
  )
}
