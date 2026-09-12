import { useCallback, useEffect, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, Download } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Spinner } from '@/components/ui/Spinner'
import { toast } from '@/components/ui/Toast'
import { dbApi } from '@/api/db'
import type { DbTable } from '@shared/types'

type ExportFormat = 'sql' | 'json' | 'csv' | 'xml'

const EXPORT_FORMATS: ExportFormat[] = ['sql', 'json', 'csv', 'xml']

export default function ExportEnhanced() {
  const { connId = '' } = useParams()
  const [searchParams] = useSearchParams()
  const database = searchParams.get('database') || ''

  const [tables, setTables] = useState<DbTable[]>([])
  const [selectedTables, setSelectedTables] = useState<string[]>([])
  const [loading, setLoading] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [exportFormat, setExportFormat] = useState<ExportFormat>('sql')
  const [includeStructure, setIncludeStructure] = useState(true)
  const [includeData, setIncludeData] = useState(true)
  const [whereClause, setWhereClause] = useState('')

  const loadTables = useCallback(async () => {
    if (!connId || !database) return
    setLoading(true)
    try {
      const result = await dbApi.listTables(connId, database)
      setTables(result)
      setSelectedTables(result.map((table) => table.name))
    } catch (error) {
      toast({
        type: 'error',
        title: 'Failed to load tables',
        description: error instanceof Error ? error.message : 'Unknown error',
      })
    } finally {
      setLoading(false)
    }
  }, [connId, database])

  useEffect(() => {
    loadTables()
  }, [loadTables])

  const toggleTable = (name: string) => {
    setSelectedTables((prev) =>
      prev.includes(name) ? prev.filter((item) => item !== name) : [...prev, name],
    )
  }

  const allSelected = tables.length > 0 && selectedTables.length === tables.length

  const toggleAll = () => {
    setSelectedTables(allSelected ? [] : tables.map((table) => table.name))
  }

  const formatFileSize = (bytes: number) => {
    if (!bytes) return '0 B'
    const units = ['B', 'KB', 'MB', 'GB']
    const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
    return `${(bytes / Math.pow(1024, index)).toFixed(2)} ${units[index]}`
  }

  const handleExport = async () => {
    if (selectedTables.length === 0) {
      toast({ type: 'warning', title: 'Select at least one table' })
      return
    }

    setExporting(true)
    try {
      const blob = await dbApi.exportEnhanced({
        connId,
        database,
        tables: selectedTables,
        format: exportFormat,
        includeStructure,
        includeData,
        whereClause: whereClause.trim() || undefined,
      })

      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')
      link.href = url
      link.download = `backup_${stamp}.${exportFormat}`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      window.URL.revokeObjectURL(url)

      toast({
        type: 'success',
        title: 'Export completed',
        description: `${selectedTables.length} table(s) exported`,
      })
    } catch (error) {
      toast({
        type: 'error',
        title: 'Export failed',
        description: error instanceof Error ? error.message : 'Unknown error',
      })
    } finally {
      setExporting(false)
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
          <h1 className="text-lg font-semibold text-fg">Enhanced export</h1>
          <p className="text-xs text-fg-subtle truncate">
            {database || 'No database selected'}
          </p>
        </div>
        <Button variant="secondary" size="sm" onClick={loadTables} loading={loading}>
          Reload
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Export options</CardTitle>
        </CardHeader>
        <CardBody className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="block space-y-1.5">
              <span className="text-sm font-medium text-fg">Format</span>
              <select
                className="input-base w-full"
                value={exportFormat}
                onChange={(event) => setExportFormat(event.target.value as ExportFormat)}
              >
                {EXPORT_FORMATS.map((format) => (
                  <option key={format} value={format}>
                    {format.toUpperCase()}
                  </option>
                ))}
              </select>
            </label>

            <label className="block space-y-1.5">
              <span className="text-sm font-medium text-fg">Row filter (optional)</span>
              <Input
                value={whereClause}
                onChange={(event) => setWhereClause(event.target.value)}
                placeholder="status = 'active'"
              />
            </label>
          </div>

          <div className="flex flex-wrap gap-5">
            <label className="flex items-center gap-2 text-sm text-fg">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-border"
                checked={includeStructure}
                onChange={(event) => setIncludeStructure(event.target.checked)}
              />
              Include table structure
            </label>
            <label className="flex items-center gap-2 text-sm text-fg">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-border"
                checked={includeData}
                onChange={(event) => setIncludeData(event.target.checked)}
              />
              Include table data
            </label>
          </div>

          <div className="flex items-center gap-2">
            <Button onClick={handleExport} loading={exporting} disabled={exporting}>
              <Download size={16} />
              Export now
            </Button>
            <span className="text-xs text-fg-subtle">
              {selectedTables.length} of {tables.length} table(s) selected
            </span>
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardHeader className="flex items-center justify-between gap-3">
          <CardTitle>Tables</CardTitle>
          <label className="flex items-center gap-2 text-xs text-fg-muted">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-border"
              checked={allSelected}
              onChange={toggleAll}
            />
            Select all
          </label>
        </CardHeader>
        <CardBody>
          {loading ? (
            <div className="flex justify-center py-8">
              <Spinner />
            </div>
          ) : tables.length === 0 ? (
            <p className="py-6 text-center text-sm text-fg-muted">No tables found</p>
          ) : (
            <ul className="divide-y divide-border">
              {tables.map((table) => (
                <li key={table.name} className="flex items-center gap-3 py-2.5">
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-border"
                    checked={selectedTables.includes(table.name)}
                    onChange={() => toggleTable(table.name)}
                  />
                  <div className="flex-1 min-w-0">
                    <div className="font-mono text-sm text-fg truncate">{table.name}</div>
                    <div className="text-xs text-fg-subtle">
                      {table.rows} row(s) | {formatFileSize(table.size)}
                    </div>
                  </div>
                  {table.engine && <Badge variant="muted">{table.engine}</Badge>}
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>
    </div>
  )
}
