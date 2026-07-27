import { useState, useEffect, useCallback, Suspense, lazy } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { ArrowLeft, Play, Download, Trash2, History, ChevronDown, Database, Table, FileCode } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { Badge } from '@/components/ui/Badge'
import { DropdownMenu, MenuItem } from '@/components/ui/DropdownMenu'
import { dbApi } from '@/api/db'
import { toast } from '@/components/ui/Toast'
import type { SqlExecResponse } from '@shared/types'
import { useI18n } from '@/hooks/useI18n'

const CodeEditor = lazy(() =>
  import('@/components/editor/CodeEditor').then((m) => ({ default: m.CodeEditor })),
)

const HISTORY_KEY = 'sql-execution-history'
const MAX_HISTORY = 20

interface HistoryItem {
  sql: string
  timestamp: number
}

const sqlTemplates = [
  { key: 'showDatabases', label: 'SHOW DATABASES', sql: 'SHOW DATABASES;', icon: Database },
  { key: 'showTables', label: 'SHOW TABLES', sql: 'SHOW TABLES;', icon: Table },
  { key: 'describeTable', label: 'DESCRIBE table', sql: 'DESCRIBE table_name;', icon: FileCode },
  { key: 'selectAll', label: 'SELECT * FROM table', sql: 'SELECT * FROM table_name LIMIT 100;', icon: FileCode },
]

export default function SqlConsole() {
  const { t } = useI18n()
  const { connId = '' } = useParams()
  const [sql, setSql] = useState('SHOW TABLES;')
  const [result, setResult] = useState<SqlExecResponse | null>(null)
  const [history, setHistory] = useState<HistoryItem[]>([])

  const execMutation = useMutation({
    mutationFn: (s: string) => dbApi.execSql(connId, '', s),
    onSuccess: (data) => {
      setResult(data)
      toast({ type: 'success', title: t('db.executionComplete'), description: `${t('db.executionTime')} ${data.executionTime}ms` })
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('db.executionFailed'), description: err.message })
    },
  })

  useEffect(() => {
    try {
      const saved = localStorage.getItem(`${HISTORY_KEY}-${connId}`)
      if (saved) {
        setHistory(JSON.parse(saved))
      }
    } catch {
      // ignore
    }
  }, [connId])

  const saveToHistory = useCallback((sqlText: string) => {
    const newItem: HistoryItem = {
      sql: sqlText,
      timestamp: Date.now(),
    }
    setHistory((prev) => {
      const filtered = prev.filter((h) => h.sql !== sqlText)
      const updated = [newItem, ...filtered].slice(0, MAX_HISTORY)
      try {
        localStorage.setItem(`${HISTORY_KEY}-${connId}`, JSON.stringify(updated))
      } catch {
        // ignore
      }
      return updated
    })
  }, [connId])

  const handleExec = useCallback(() => {
    if (!sql.trim()) return
    saveToHistory(sql)
    execMutation.mutate(sql)
  }, [sql, execMutation, saveToHistory])

  const handleTemplateClick = (templateSql: string) => {
    setSql(templateSql)
  }

  const handleHistoryClick = (historySql: string) => {
    setSql(historySql)
  }

  const clearHistory = () => {
    setHistory([])
    try {
      localStorage.removeItem(`${HISTORY_KEY}-${connId}`)
    } catch {
      // ignore
    }
  }

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault()
        handleExec()
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [handleExec])

  const formatTime = (timestamp: number) => {
    const d = new Date(timestamp)
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  }

  return (
    <div className="h-full flex flex-col">
      <div className="flex items-center gap-3 px-4 md:px-5 py-3 border-b border-border shrink-0">
        <Link
          to="/db"
          className="p-1.5 -ml-1.5 rounded-md text-fg-muted hover:text-fg hover:bg-fg/5 transition-colors"
        >
          <ArrowLeft size={18} />
        </Link>
        <div className="flex-1 min-w-0">
          <h1 className="text-sm font-medium text-fg">{t('db.sql')}</h1>
          <div className="text-xs text-fg-subtle truncate">{t('db.connection')}: {connId}</div>
        </div>
        <div className="flex items-center gap-2">
          <Badge variant="muted">{t('db.sql')}</Badge>
          <Button
            onClick={handleExec}
            size="sm"
            loading={execMutation.isPending}
          >
            <Play size={14} />
            <span className="hidden sm:inline">{t('common.execute')}</span>
          </Button>
        </div>
      </div>

      <div className="flex-1 min-h-0 flex flex-col">
        <div className="px-4 py-2 border-b border-border bg-bg-sunken/30 flex items-center gap-2 flex-wrap shrink-0">
          <span className="text-xs font-medium text-fg-muted mr-1">{t('db.templates')}:</span>
          {sqlTemplates.map((template) => {
            const Icon = template.icon
            return (
              <Button
                key={template.key}
                variant="ghost"
                size="sm"
                onClick={() => handleTemplateClick(template.sql)}
                className="h-7 px-2.5 text-xs"
              >
                <Icon size={13} />
                <span className="hidden sm:inline">{template.label}</span>
              </Button>
            )
          })}
          <div className="flex-1" />
          <DropdownMenu
            trigger={
              <Button variant="ghost" size="sm" className="h-7 px-2.5 text-xs">
                <History size={13} />
                <span className="hidden sm:inline">{t('db.history')}</span>
                <ChevronDown size={12} />
              </Button>
            }
          >
            <div className="w-72 max-h-80 overflow-auto">
              {history.length === 0 ? (
                <div className="px-4 py-6 text-center text-sm text-fg-muted">
                  {t('db.noHistory')}
                </div>
              ) : (
                <>
                  <div className="px-3 py-2 flex items-center justify-between border-b border-border">
                    <span className="text-xs font-medium text-fg-muted">{t('db.recentQueries')}</span>
                    <button
                      onClick={clearHistory}
                      className="text-xs text-danger hover:underline"
                    >
                      {t('common.clear')}
                    </button>
                  </div>
                  {history.map((item, i) => (
                    <MenuItem
                      key={i}
                      label={item.sql.length > 50 ? item.sql.slice(0, 50) + '...' : item.sql}
                      description={formatTime(item.timestamp)}
                      onClick={() => handleHistoryClick(item.sql)}
                    />
                  ))}
                </>
              )}
            </div>
          </DropdownMenu>
        </div>

        <div className="flex-1 min-h-[150px] border-b border-border">
          <Suspense
            fallback={
              <div className="h-full flex items-center justify-center">
                <Spinner />
              </div>
            }
          >
            <CodeEditor
              value={sql}
              onChange={setSql}
              language="sql"
              filename="query.sql"
            />
          </Suspense>
        </div>

        <div className="flex-1 min-h-[200px] overflow-auto">
          <div className="px-4 py-2.5 border-b border-border bg-bg-sunken/50 flex items-center justify-between">
            <span className="text-sm font-medium text-fg">{t('db.results')}</span>
            {result && (
              <div className="flex items-center gap-3">
                <Badge variant="success">{result.results.length} {t('db.statementsCount')}</Badge>
                <span className="text-xs text-fg-subtle">{result.executionTime}ms</span>
                <Button variant="ghost" size="icon-sm" aria-label={t('common.export')}>
                  <Download size={14} />
                </Button>
                <Button
                  variant="ghost"
                  size="icon-sm"
                  onClick={() => setResult(null)}
                  aria-label={t('common.clear')}
                >
                  <Trash2 size={14} />
                </Button>
              </div>
            )}
          </div>

          {execMutation.isPending ? (
            <div className="p-8 flex justify-center">
              <Spinner />
            </div>
          ) : !result ? (
            <div className="p-12 text-center text-sm text-fg-muted">
              {t('db.enterSql')}
            </div>
          ) : (
            <div className="p-4 space-y-4">
              {result.results.map((r, i) => (
                <Card key={i} className="overflow-hidden">
                  <div className="px-4 py-2 bg-bg-sunken/50 border-b border-border flex items-center justify-between">
                    <span className="text-xs font-mono text-fg-muted truncate flex-1">
                      {r.statement}
                    </span>
                    {r.success ? (
                      <Badge variant="success">{t('common.success')}</Badge>
                    ) : (
                      <Badge variant="danger">{t('common.failure')}</Badge>
                    )}
                  </div>
                  {r.rows && r.rows.length > 0 && (
                    <div className="overflow-auto max-h-64">
                      <table className="w-full text-xs">
                        <thead className="sticky top-0 bg-bg-elevated">
                          <tr className="text-fg-muted text-left">
                            {Object.keys(r.rows[0]).map((key) => (
                              <th key={key} className="font-medium px-3 py-2 border-b border-border whitespace-nowrap">
                                {key}
                              </th>
                            ))}
                          </tr>
                        </thead>
                        <tbody className="font-mono">
                          {r.rows.slice(0, 100).map((row, ri) => (
                            <tr key={ri} className="border-b border-border/50">
                              {Object.values(row).map((val, vi) => (
                                <td key={vi} className="px-3 py-1.5 text-fg whitespace-nowrap">
                                  {val === null ? (
                                    <span className="text-fg-subtle italic">NULL</span>
                                  ) : (
                                    String(val)
                                  )}
                                </td>
                              ))}
                            </tr>
                          ))}
                        </tbody>
                      </table>
                      {r.rows.length > 100 && (
                        <div className="px-3 py-2 text-xs text-fg-subtle text-center border-t border-border/50">
                          {t('db.showingRows', { total: r.rows.length })}
                        </div>
                      )}
                    </div>
                  )}
                  {r.affectedRows !== undefined && r.rows === undefined && (
                    <div className="px-4 py-3 text-sm text-fg-muted">
                      {t('db.affectedRows')}: {r.affectedRows}
                    </div>
                  )}
                  {r.error && (
                    <div className="px-4 py-3 text-sm text-danger font-mono">
                      {r.error}
                    </div>
                  )}
                </Card>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
