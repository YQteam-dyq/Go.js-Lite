import { useCallback, useEffect, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, Play, Plus, RefreshCw, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Spinner } from '@/components/ui/Spinner'
import { toast } from '@/components/ui/Toast'
import { dbApi } from '@/api/db'
import type { DbQueryCondition, DbQueryInput, DbQueryOrder, DbQueryResult } from '@/api/db'
import type { DbColumn } from '@shared/types'

const OPERATORS = [
  { value: 'eq', label: '=' },
  { value: 'ne', label: '!=' },
  { value: 'gt', label: '>' },
  { value: 'gte', label: '>=' },
  { value: 'lt', label: '<' },
  { value: 'lte', label: '<=' },
  { value: 'like', label: 'LIKE' },
  { value: 'in', label: 'IN' },
  { value: 'null', label: 'IS NULL' },
  { value: 'notnull', label: 'IS NOT NULL' },
]

const VALUELESS_OPERATORS = ['null', 'notnull']

interface ConditionRow {
  id: number
  field: string
  operator: string
  value: string
}

interface OrderRow {
  id: number
  field: string
  direction: 'ASC' | 'DESC'
}

let rowId = 0
const nextId = () => {
  rowId += 1
  return rowId
}

export default function QueryBuilder() {
  const { connId = '' } = useParams()
  const [searchParams] = useSearchParams()
  const database = searchParams.get('database') || ''
  const table = searchParams.get('table') || ''

  const [columns, setColumns] = useState<DbColumn[]>([])
  const [selectedColumns, setSelectedColumns] = useState<string[]>([])
  const [conditions, setConditions] = useState<ConditionRow[]>([])
  const [groupBy, setGroupBy] = useState<string[]>([])
  const [orderBy, setOrderBy] = useState<OrderRow[]>([])
  const [limit, setLimit] = useState('100')

  const [loading, setLoading] = useState(false)
  const [running, setRunning] = useState(false)
  const [previewing, setPreviewing] = useState(false)
  const [result, setResult] = useState<DbQueryResult | null>(null)

  const loadStructure = useCallback(async () => {
    if (!connId || !database || !table) return
    setLoading(true)
    try {
      const structure = await dbApi.getStructure(connId, database, table)
      setColumns(structure)
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

  useEffect(() => {
    loadStructure()
  }, [loadStructure])

  const toggleColumn = (name: string) => {
    setSelectedColumns((prev) =>
      prev.includes(name) ? prev.filter((item) => item !== name) : [...prev, name],
    )
  }

  const toggleGroupBy = (name: string) => {
    setGroupBy((prev) =>
      prev.includes(name) ? prev.filter((item) => item !== name) : [...prev, name],
    )
  }

  const addCondition = () => {
    setConditions((prev) => [
      ...prev,
      { id: nextId(), field: columns[0]?.name ?? '', operator: 'eq', value: '' },
    ])
  }

  const updateCondition = (id: number, patch: Partial<ConditionRow>) => {
    setConditions((prev) => prev.map((row) => (row.id === id ? { ...row, ...patch } : row)))
  }

  const removeCondition = (id: number) => {
    setConditions((prev) => prev.filter((row) => row.id !== id))
  }

  const addOrder = () => {
    setOrderBy((prev) => [
      ...prev,
      { id: nextId(), field: columns[0]?.name ?? '', direction: 'ASC' },
    ])
  }

  const updateOrder = (id: number, patch: Partial<OrderRow>) => {
    setOrderBy((prev) => prev.map((row) => (row.id === id ? { ...row, ...patch } : row)))
  }

  const removeOrder = (id: number) => {
    setOrderBy((prev) => prev.filter((row) => row.id !== id))
  }

  const buildQueryInput = (): DbQueryInput => {
    const mappedConditions: DbQueryCondition[] = conditions
      .filter((row) => row.field && row.operator)
      .map((row) => {
        if (row.operator === 'in') {
          const items = row.value
            .split(',')
            .map((item) => item.trim())
            .filter((item) => item !== '')
          return { field: row.field, operator: row.operator, value: items }
        }
        if (VALUELESS_OPERATORS.includes(row.operator)) {
          return { field: row.field, operator: row.operator }
        }
        return { field: row.field, operator: row.operator, value: row.value }
      })

    const mappedOrder: DbQueryOrder[] = orderBy
      .filter((row) => row.field)
      .map((row) => ({ field: row.field, direction: row.direction }))

    const parsedLimit = Number.parseInt(limit, 10)

    return {
      connId,
      database,
      table,
      columns: selectedColumns,
      conditions: mappedConditions,
      groupBy,
      orderBy: mappedOrder,
      limit: Number.isFinite(parsedLimit) && parsedLimit > 0 ? parsedLimit : 100,
    }
  }

  const runQuery = async (mode: 'preview' | 'run') => {
    if (!connId || !database || !table) {
      toast({ type: 'warning', title: 'Select a database and a table first' })
      return
    }

    if (mode === 'preview') setPreviewing(true)
    else setRunning(true)

    try {
      const input = buildQueryInput()
      const response = mode === 'preview' ? await dbApi.queryPreview(input) : await dbApi.queryBuilder(input)
      setResult(response)
      toast({
        type: 'success',
        title: mode === 'preview' ? 'Preview generated' : 'Query executed',
        description: `${response.count} row(s) returned`,
      })
    } catch (error) {
      toast({
        type: 'error',
        title: 'Query failed',
        description: error instanceof Error ? error.message : 'Unknown error',
      })
    } finally {
      setPreviewing(false)
      setRunning(false)
    }
  }

  const resultColumns = result && result.data.length > 0 ? Object.keys(result.data[0]) : []

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
          <h1 className="text-lg font-semibold text-fg">Query builder</h1>
          <p className="text-xs text-fg-subtle truncate">
            {database}.{table}
          </p>
        </div>
        <Button variant="secondary" size="sm" onClick={loadStructure} loading={loading}>
          <RefreshCw size={14} />
          Reload
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Columns</CardTitle>
        </CardHeader>
        <CardBody>
          {loading ? (
            <div className="flex justify-center py-6">
              <Spinner />
            </div>
          ) : columns.length === 0 ? (
            <p className="text-sm text-fg-muted">No columns available</p>
          ) : (
            <div className="flex flex-wrap gap-4">
              {columns.map((column) => (
                <label key={column.name} className="flex items-center gap-2 text-sm text-fg">
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-border"
                    checked={selectedColumns.includes(column.name)}
                    onChange={() => toggleColumn(column.name)}
                  />
                  <span className="font-mono text-xs">{column.name}</span>
                </label>
              ))}
            </div>
          )}
          <p className="mt-2 text-xs text-fg-subtle">
            {selectedColumns.length === 0 ? 'No selection means SELECT *' : `${selectedColumns.length} column(s) selected`}
          </p>
        </CardBody>
      </Card>

      <Card>
        <CardHeader className="flex items-center justify-between gap-3">
          <CardTitle>Conditions</CardTitle>
          <Button variant="secondary" size="sm" onClick={addCondition}>
            <Plus size={14} />
            Add condition
          </Button>
        </CardHeader>
        <CardBody className="space-y-3">
          {conditions.length === 0 ? (
            <p className="text-sm text-fg-muted">No conditions. All rows will match.</p>
          ) : (
            conditions.map((row) => (
              <div key={row.id} className="flex flex-wrap items-center gap-2">
                <select
                  className="input-base w-full sm:w-44"
                  value={row.field}
                  onChange={(event) => updateCondition(row.id, { field: event.target.value })}
                >
                  {columns.map((column) => (
                    <option key={column.name} value={column.name}>
                      {column.name}
                    </option>
                  ))}
                </select>
                <select
                  className="input-base w-full sm:w-32"
                  value={row.operator}
                  onChange={(event) => updateCondition(row.id, { operator: event.target.value })}
                >
                  {OPERATORS.map((operator) => (
                    <option key={operator.value} value={operator.value}>
                      {operator.label}
                    </option>
                  ))}
                </select>
                <div className="flex-1 min-w-[10rem]">
                  <Input
                    value={row.value}
                    disabled={VALUELESS_OPERATORS.includes(row.operator)}
                    placeholder={row.operator === 'in' ? 'value1, value2' : 'value'}
                    onChange={(event) => updateCondition(row.id, { value: event.target.value })}
                  />
                </div>
                <Button
                  variant="ghost"
                  size="icon-sm"
                  aria-label="Remove condition"
                  onClick={() => removeCondition(row.id)}
                >
                  <Trash2 size={14} />
                </Button>
              </div>
            ))
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Group by</CardTitle>
        </CardHeader>
        <CardBody>
          <div className="flex flex-wrap gap-4">
            {columns.map((column) => (
              <label key={column.name} className="flex items-center gap-2 text-sm text-fg">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-border"
                  checked={groupBy.includes(column.name)}
                  onChange={() => toggleGroupBy(column.name)}
                />
                <span className="font-mono text-xs">{column.name}</span>
              </label>
            ))}
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardHeader className="flex items-center justify-between gap-3">
          <CardTitle>Order by</CardTitle>
          <Button variant="secondary" size="sm" onClick={addOrder}>
            <Plus size={14} />
            Add order
          </Button>
        </CardHeader>
        <CardBody className="space-y-3">
          {orderBy.length === 0 ? (
            <p className="text-sm text-fg-muted">No ordering applied.</p>
          ) : (
            orderBy.map((row) => (
              <div key={row.id} className="flex flex-wrap items-center gap-2">
                <select
                  className="input-base w-full sm:w-44"
                  value={row.field}
                  onChange={(event) => updateOrder(row.id, { field: event.target.value })}
                >
                  {columns.map((column) => (
                    <option key={column.name} value={column.name}>
                      {column.name}
                    </option>
                  ))}
                </select>
                <select
                  className="input-base w-full sm:w-32"
                  value={row.direction}
                  onChange={(event) =>
                    updateOrder(row.id, { direction: event.target.value as 'ASC' | 'DESC' })
                  }
                >
                  <option value="ASC">ASC</option>
                  <option value="DESC">DESC</option>
                </select>
                <Button
                  variant="ghost"
                  size="icon-sm"
                  aria-label="Remove order"
                  onClick={() => removeOrder(row.id)}
                >
                  <Trash2 size={14} />
                </Button>
              </div>
            ))
          )}
        </CardBody>
      </Card>

      <Card>
        <CardBody className="flex flex-wrap items-end gap-3">
          <label className="block space-y-1">
            <span className="text-xs font-medium text-fg-muted">Row limit</span>
            <Input
              className="w-32"
              value={limit}
              onChange={(event) => setLimit(event.target.value)}
            />
          </label>
          <Button
            variant="secondary"
            onClick={() => runQuery('preview')}
            loading={previewing}
            disabled={previewing || running}
          >
            Preview (10 rows)
          </Button>
          <Button onClick={() => runQuery('run')} loading={running} disabled={previewing || running}>
            <Play size={16} />
            Run query
          </Button>
          {result && <Badge variant="muted">{result.count} row(s)</Badge>}
        </CardBody>
      </Card>

      {result && (
        <>
          <Card>
            <CardHeader>
              <CardTitle>Generated SQL</CardTitle>
            </CardHeader>
            <CardBody>
              <pre className="overflow-x-auto rounded-md bg-bg-sunken p-3 text-xs text-fg">
                {result.sql}
              </pre>
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Result</CardTitle>
            </CardHeader>
            <CardBody>
              {result.data.length === 0 ? (
                <p className="py-6 text-center text-sm text-fg-muted">No rows returned</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-border text-left text-fg-subtle">
                        {resultColumns.map((column) => (
                          <th key={column} className="whitespace-nowrap px-3 py-2 font-medium">
                            {column}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {result.data.map((row, index) => (
                        <tr key={index} className="border-b border-border/60 hover:bg-fg/5">
                          {resultColumns.map((column) => (
                            <td
                              key={column}
                              className="max-w-xs truncate px-3 py-2 font-mono text-xs text-fg"
                            >
                              {row[column] === null || row[column] === undefined
                                ? 'NULL'
                                : String(row[column])}
                            </td>
                          ))}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </CardBody>
          </Card>
        </>
      )}
    </div>
  )
}
