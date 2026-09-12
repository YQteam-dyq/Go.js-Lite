import { useCallback, useEffect, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, KeyRound, Plus, RefreshCw, Trash2, Wrench } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Modal, Confirm } from '@/components/ui/Modal'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs'
import { Spinner } from '@/components/ui/Spinner'
import { toast } from '@/components/ui/Toast'
import { dbApi } from '@/api/db'
import type { DbColumnDefinition } from '@/api/db'
import type { DbColumn } from '@shared/types'

const IDENTIFIER_PATTERN = /^[A-Za-z0-9_$]+$/

const COLUMN_TYPES = [
  'INT',
  'BIGINT',
  'SMALLINT',
  'TINYINT',
  'DECIMAL',
  'FLOAT',
  'DOUBLE',
  'VARCHAR',
  'CHAR',
  'TEXT',
  'MEDIUMTEXT',
  'LONGTEXT',
  'DATE',
  'DATETIME',
  'TIMESTAMP',
  'TIME',
  'JSON',
  'ENUM',
]

const INDEX_TYPES = ['INDEX', 'UNIQUE', 'FULLTEXT', 'SPATIAL']

interface ColumnForm {
  name: string
  type: string
  length: string
  unsigned: boolean
  nullable: boolean
  defaultValue: string
  comment: string
  autoIncrement: boolean
  primaryKey: boolean
}

interface IndexEntry {
  name: string
  unique: boolean
  type: string
  columns: string[]
}

const emptyColumnForm = (): ColumnForm => ({
  name: '',
  type: 'VARCHAR',
  length: '255',
  unsigned: false,
  nullable: true,
  defaultValue: '',
  comment: '',
  autoIncrement: false,
  primaryKey: false,
})

export default function TableStructureManager() {
  const { connId = '' } = useParams()
  const [searchParams] = useSearchParams()
  const database = searchParams.get('database') || ''
  const table = searchParams.get('table') || ''

  const [tab, setTab] = useState('columns')
  const [columns, setColumns] = useState<DbColumn[]>([])
  const [indexes, setIndexes] = useState<IndexEntry[]>([])
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)

  const [formOpen, setFormOpen] = useState(false)
  const [formMode, setFormMode] = useState<'ADD' | 'MODIFY'>('ADD')
  const [form, setForm] = useState<ColumnForm>(emptyColumnForm())
  const [dropTarget, setDropTarget] = useState<DbColumn | null>(null)

  const [indexOpen, setIndexOpen] = useState(false)
  const [indexName, setIndexName] = useState('')
  const [indexType, setIndexType] = useState('INDEX')
  const [indexColumns, setIndexColumns] = useState<string[]>([])
  const [dropIndexTarget, setDropIndexTarget] = useState<IndexEntry | null>(null)

  const tableIsSafe = IDENTIFIER_PATTERN.test(table)

  const loadStructure = useCallback(async () => {
    if (!connId || !database || !table) return
    setLoading(true)
    try {
      const result = await dbApi.getStructure(connId, database, table)
      setColumns(result)
    } catch (error) {
      toast({
        type: 'error',
        title: 'Failed to load table structure',
        description: error instanceof Error ? error.message : 'Unknown error',
      })
    } finally {
      setLoading(false)
    }
  }, [connId, database, table])

  const loadIndexes = useCallback(async () => {
    if (!connId || !database || !tableIsSafe) {
      setIndexes([])
      return
    }
    try {
      const result = await dbApi.execSql(connId, database, `SHOW INDEX FROM \`${table}\``)
      const rows = result.results[0]?.rows || []
      const grouped = new Map<string, IndexEntry>()

      rows.forEach((row) => {
        const name = String(row.Key_name ?? '')
        if (!name) return
        const entry = grouped.get(name) || {
          name,
          unique: Number(row.Non_unique ?? 1) === 0,
          type: String(row.Index_type ?? ''),
          columns: [],
        }
        entry.columns.push(String(row.Column_name ?? ''))
        grouped.set(name, entry)
      })

      setIndexes(Array.from(grouped.values()))
    } catch (error) {
      toast({
        type: 'error',
        title: 'Failed to load indexes',
        description: error instanceof Error ? error.message : 'Unknown error',
      })
    }
  }, [connId, database, table, tableIsSafe])

  const refresh = useCallback(() => {
    loadStructure()
    loadIndexes()
  }, [loadStructure, loadIndexes])

  useEffect(() => {
    refresh()
  }, [refresh])

  const openAddColumn = () => {
    setFormMode('ADD')
    setForm(emptyColumnForm())
    setFormOpen(true)
  }

  const openModifyColumn = (column: DbColumn) => {
    const match = column.type.match(/^([A-Za-z]+)(?:\(([^)]*)\))?(.*)$/)
    setFormMode('MODIFY')
    setForm({
      name: column.name,
      type: match ? match[1].toUpperCase() : column.type.toUpperCase(),
      length: match && match[2] ? match[2] : '',
      unsigned: match ? /unsigned/i.test(match[3]) : false,
      nullable: column.nullable,
      defaultValue: column.default === null ? '' : String(column.default),
      comment: '',
      autoIncrement: /auto_increment/i.test(column.extra),
      primaryKey: column.key === 'PRI',
    })
    setFormOpen(true)
  }

  const buildColumnDefinition = (): DbColumnDefinition | null => {
    if (!IDENTIFIER_PATTERN.test(form.name)) {
      toast({ type: 'warning', title: 'Column name must use letters, digits, _ or $' })
      return null
    }
    if (!form.type) {
      toast({ type: 'warning', title: 'Column type is required' })
      return null
    }

    const definition: DbColumnDefinition = {
      name: form.name,
      type: form.type,
      nullable: form.nullable,
      unsigned: form.unsigned,
      auto_increment: form.autoIncrement,
      primary_key: form.primaryKey,
    }

    if (form.length.trim()) definition.length = form.length.trim()
    if (form.defaultValue !== '') definition.default = form.defaultValue
    if (form.comment.trim()) definition.comment = form.comment.trim()

    return definition
  }

  const handleSubmitColumn = async () => {
    const column = buildColumnDefinition()
    if (!column) return

    setSaving(true)
    try {
      await dbApi.alterTable({
        connId,
        database,
        tableName: table,
        action: formMode,
        column,
      })
      toast({
        type: 'success',
        title: formMode === 'ADD' ? 'Column added' : 'Column modified',
        description: `${form.name}`,
      })
      setFormOpen(false)
      refresh()
    } catch (error) {
      toast({
        type: 'error',
        title: 'Alter table failed',
        description: error instanceof Error ? error.message : 'Unknown error',
      })
    } finally {
      setSaving(false)
    }
  }

  const handleDropColumn = async () => {
    if (!dropTarget) return

    setSaving(true)
    try {
      await dbApi.alterTable({
        connId,
        database,
        tableName: table,
        action: 'DROP',
        column: { name: dropTarget.name, type: dropTarget.type },
      })
      toast({ type: 'success', title: 'Column dropped', description: dropTarget.name })
      setDropTarget(null)
      refresh()
    } catch (error) {
      toast({
        type: 'error',
        title: 'Drop column failed',
        description: error instanceof Error ? error.message : 'Unknown error',
      })
    } finally {
      setSaving(false)
    }
  }

  const openCreateIndex = () => {
    setIndexName('')
    setIndexType('INDEX')
    setIndexColumns([])
    setIndexOpen(true)
  }

  const toggleIndexColumn = (name: string) => {
    setIndexColumns((prev) =>
      prev.includes(name) ? prev.filter((item) => item !== name) : [...prev, name],
    )
  }

  const handleCreateIndex = async () => {
    if (!IDENTIFIER_PATTERN.test(indexName)) {
      toast({ type: 'warning', title: 'Index name must use letters, digits, _ or $' })
      return
    }
    if (indexColumns.length === 0) {
      toast({ type: 'warning', title: 'Select at least one column' })
      return
    }

    setSaving(true)
    try {
      await dbApi.createIndex({
        connId,
        database,
        tableName: table,
        indexName,
        indexType,
        columns: indexColumns,
      })
      toast({ type: 'success', title: 'Index created', description: indexName })
      setIndexOpen(false)
      refresh()
    } catch (error) {
      toast({
        type: 'error',
        title: 'Create index failed',
        description: error instanceof Error ? error.message : 'Unknown error',
      })
    } finally {
      setSaving(false)
    }
  }

  const handleDropIndex = async () => {
    if (!dropIndexTarget) return

    setSaving(true)
    try {
      await dbApi.dropIndex({
        connId,
        database,
        tableName: table,
        indexName: dropIndexTarget.name,
      })
      toast({ type: 'success', title: 'Index dropped', description: dropIndexTarget.name })
      setDropIndexTarget(null)
      refresh()
    } catch (error) {
      toast({
        type: 'error',
        title: 'Drop index failed',
        description: error instanceof Error ? error.message : 'Unknown error',
      })
    } finally {
      setSaving(false)
    }
  }

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
          <h1 className="text-lg font-semibold text-fg">Table structure</h1>
          <p className="text-xs text-fg-subtle truncate">
            {database}.{table}
          </p>
        </div>
        <Button variant="secondary" size="sm" onClick={refresh} loading={loading}>
          <RefreshCw size={14} />
          Refresh
        </Button>
      </div>

      <Tabs value={tab} onValueChange={setTab}>
        <TabsList>
          <TabsTrigger value="columns">Columns</TabsTrigger>
          <TabsTrigger value="indexes">Indexes</TabsTrigger>
        </TabsList>

        <TabsContent value="columns">
          <Card>
            <CardHeader className="flex items-center justify-between gap-3">
              <CardTitle>Columns</CardTitle>
              <Button size="sm" onClick={openAddColumn}>
                <Plus size={16} />
                Add column
              </Button>
            </CardHeader>
            <CardBody>
              {loading ? (
                <div className="flex justify-center py-10">
                  <Spinner />
                </div>
              ) : columns.length === 0 ? (
                <p className="py-8 text-center text-sm text-fg-muted">No columns found</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-border text-left text-fg-subtle">
                        <th className="px-3 py-2 font-medium">Name</th>
                        <th className="px-3 py-2 font-medium">Type</th>
                        <th className="px-3 py-2 font-medium">Nullable</th>
                        <th className="px-3 py-2 font-medium">Key</th>
                        <th className="px-3 py-2 font-medium">Default</th>
                        <th className="px-3 py-2 font-medium">Extra</th>
                        <th className="px-3 py-2 font-medium">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {columns.map((column) => (
                        <tr key={column.name} className="border-b border-border/60 hover:bg-fg/5">
                          <td className="px-3 py-2 font-mono text-xs text-fg">{column.name}</td>
                          <td className="px-3 py-2 font-mono text-xs text-fg-muted">
                            {column.type}
                          </td>
                          <td className="px-3 py-2 text-xs text-fg-muted">
                            {column.nullable ? 'YES' : 'NO'}
                          </td>
                          <td className="px-3 py-2">
                            {column.key ? <Badge variant="accent">{column.key}</Badge> : null}
                          </td>
                          <td className="px-3 py-2 font-mono text-xs text-fg-muted">
                            {column.default === null ? 'NULL' : String(column.default)}
                          </td>
                          <td className="px-3 py-2 text-xs text-fg-muted">{column.extra}</td>
                          <td className="px-3 py-2">
                            <div className="flex items-center gap-1">
                              <Button
                                variant="ghost"
                                size="icon-sm"
                                aria-label="Modify column"
                                onClick={() => openModifyColumn(column)}
                              >
                                <Wrench size={14} />
                              </Button>
                              <Button
                                variant="ghost"
                                size="icon-sm"
                                aria-label="Drop column"
                                onClick={() => setDropTarget(column)}
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
            </CardBody>
          </Card>
        </TabsContent>

        <TabsContent value="indexes">
          <Card>
            <CardHeader className="flex items-center justify-between gap-3">
              <CardTitle>Indexes</CardTitle>
              <Button size="sm" onClick={openCreateIndex}>
                <KeyRound size={16} />
                Create index
              </Button>
            </CardHeader>
            <CardBody>
              {indexes.length === 0 ? (
                <p className="py-8 text-center text-sm text-fg-muted">No indexes found</p>
              ) : (
                <ul className="divide-y divide-border">
                  {indexes.map((index) => (
                    <li key={index.name} className="flex items-center gap-3 py-3">
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                          <span className="font-mono text-sm text-fg">{index.name}</span>
                          {index.unique && <Badge variant="success">UNIQUE</Badge>}
                          {index.type && <Badge variant="muted">{index.type}</Badge>}
                        </div>
                        <div className="text-xs text-fg-subtle truncate">
                          {index.columns.join(', ')}
                        </div>
                      </div>
                      {index.name !== 'PRIMARY' && (
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          aria-label="Drop index"
                          onClick={() => setDropIndexTarget(index)}
                        >
                          <Trash2 size={14} />
                        </Button>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>
        </TabsContent>
      </Tabs>

      <Modal
        open={formOpen}
        onClose={() => setFormOpen(false)}
        title={formMode === 'ADD' ? 'Add column' : 'Modify column'}
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setFormOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleSubmitColumn} loading={saving}>
              {formMode === 'ADD' ? 'Add' : 'Save'}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <label className="block space-y-1">
            <span className="text-xs font-medium text-fg-muted">Name</span>
            <Input
              value={form.name}
              disabled={formMode === 'MODIFY'}
              onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))}
            />
          </label>

          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block space-y-1">
              <span className="text-xs font-medium text-fg-muted">Type</span>
              <select
                className="input-base w-full"
                value={form.type}
                onChange={(event) => setForm((prev) => ({ ...prev, type: event.target.value }))}
              >
                {COLUMN_TYPES.map((type) => (
                  <option key={type} value={type}>
                    {type}
                  </option>
                ))}
              </select>
            </label>
            <label className="block space-y-1">
              <span className="text-xs font-medium text-fg-muted">Length</span>
              <Input
                value={form.length}
                placeholder="e.g. 255"
                onChange={(event) => setForm((prev) => ({ ...prev, length: event.target.value }))}
              />
            </label>
          </div>

          <label className="block space-y-1">
            <span className="text-xs font-medium text-fg-muted">Default value</span>
            <Input
              value={form.defaultValue}
              onChange={(event) =>
                setForm((prev) => ({ ...prev, defaultValue: event.target.value }))
              }
            />
          </label>

          <label className="block space-y-1">
            <span className="text-xs font-medium text-fg-muted">Comment</span>
            <Input
              value={form.comment}
              onChange={(event) => setForm((prev) => ({ ...prev, comment: event.target.value }))}
            />
          </label>

          <div className="flex flex-wrap gap-5 pt-1">
            <label className="flex items-center gap-2 text-sm text-fg">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-border"
                checked={form.nullable}
                onChange={(event) =>
                  setForm((prev) => ({ ...prev, nullable: event.target.checked }))
                }
              />
              Nullable
            </label>
            <label className="flex items-center gap-2 text-sm text-fg">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-border"
                checked={form.unsigned}
                onChange={(event) =>
                  setForm((prev) => ({ ...prev, unsigned: event.target.checked }))
                }
              />
              Unsigned
            </label>
            <label className="flex items-center gap-2 text-sm text-fg">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-border"
                checked={form.autoIncrement}
                onChange={(event) =>
                  setForm((prev) => ({ ...prev, autoIncrement: event.target.checked }))
                }
              />
              Auto increment
            </label>
            <label className="flex items-center gap-2 text-sm text-fg">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-border"
                checked={form.primaryKey}
                onChange={(event) =>
                  setForm((prev) => ({ ...prev, primaryKey: event.target.checked }))
                }
              />
              Primary key
            </label>
          </div>
        </div>
      </Modal>

      <Modal
        open={indexOpen}
        onClose={() => setIndexOpen(false)}
        title="Create index"
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setIndexOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleCreateIndex} loading={saving}>
              Create
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block space-y-1">
              <span className="text-xs font-medium text-fg-muted">Index name</span>
              <Input
                value={indexName}
                onChange={(event) => setIndexName(event.target.value)}
                placeholder="idx_example"
              />
            </label>
            <label className="block space-y-1">
              <span className="text-xs font-medium text-fg-muted">Index type</span>
              <select
                className="input-base w-full"
                value={indexType}
                onChange={(event) => setIndexType(event.target.value)}
              >
                {INDEX_TYPES.map((type) => (
                  <option key={type} value={type}>
                    {type}
                  </option>
                ))}
              </select>
            </label>
          </div>

          <div className="space-y-2">
            <span className="text-xs font-medium text-fg-muted">Columns</span>
            <div className="max-h-56 space-y-2 overflow-y-auto rounded-md border border-border p-3">
              {columns.map((column) => (
                <label key={column.name} className="flex items-center gap-2 text-sm text-fg">
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-border"
                    checked={indexColumns.includes(column.name)}
                    onChange={() => toggleIndexColumn(column.name)}
                  />
                  <span className="font-mono text-xs">{column.name}</span>
                  <span className="text-xs text-fg-subtle">{column.type}</span>
                </label>
              ))}
              {columns.length === 0 && (
                <p className="text-xs text-fg-subtle">Load the structure first</p>
              )}
            </div>
          </div>
        </div>
      </Modal>

      <Confirm
        open={dropTarget !== null}
        title="Drop column"
        message={`Drop column "${dropTarget?.name ?? ''}"? This cannot be undone.`}
        variant="danger"
        loading={saving}
        onCancel={() => setDropTarget(null)}
        onConfirm={handleDropColumn}
      />

      <Confirm
        open={dropIndexTarget !== null}
        title="Drop index"
        message={`Drop index "${dropIndexTarget?.name ?? ''}"? This cannot be undone.`}
        variant="danger"
        loading={saving}
        onCancel={() => setDropIndexTarget(null)}
        onConfirm={handleDropIndex}
      />
    </div>
  )
}
