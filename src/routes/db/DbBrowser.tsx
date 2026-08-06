import { useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Table, Database as DbIcon, ChevronRight } from 'lucide-react'
import { Spinner } from '@/components/ui/Spinner'
import { Badge } from '@/components/ui/Badge'
import { dbApi } from '@/api/db'
import { useFormat } from '@/lib/format'
import type { DbTable, DbColumn } from '@shared/types'
import { useI18n } from '@/hooks/useI18n'

export default function DbBrowser() {
  const { t } = useI18n()
  const { formatNumber, formatBytes } = useFormat()
  const { connId = '' } = useParams()
  const [database, setDatabase] = useState<string>('')
  const [selectedTable, setSelectedTable] = useState<string>('')

  const { data: databases, isLoading: loadingDbs } = useQuery<string[]>({
    queryKey: ['db-databases', connId],
    queryFn: () => dbApi.listDatabases(connId),
  })

  const { data: tables, isLoading: loadingTables } = useQuery<DbTable[]>({
    queryKey: ['db-tables', connId, database],
    queryFn: () => dbApi.listTables(connId, database),
    enabled: !!database,
  })

  const { data: columns, isLoading: loadingColumns } = useQuery<DbColumn[]>({
    queryKey: ['db-structure', connId, database, selectedTable],
    queryFn: () => dbApi.getStructure(connId, database, selectedTable),
    enabled: !!database && !!selectedTable,
  })

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
          <h1 className="text-sm font-medium text-fg truncate">{t('db.browseTitle')}</h1>
          <div className="text-xs text-fg-subtle truncate">{t('db.connection')}: {connId}</div>
        </div>
        <Link to={`/db/$$connId}/sql`} className="text-xs text-accent hover:underline">
          {t('db.sqlConsoleLink')}
        </Link>
      </div>

      <div className="flex-1 min-h-0 flex flex-col md:flex-row">
        <div className="md:w-56 shrink-0 border-b md:border-b-0 md:border-r border-border overflow-auto">
          <div className="p-3">
            <h3 className="text-xs font-medium text-fg-muted uppercase tracking-wider mb-2 px-2">
              {t('db.databases')}
            </h3>
            {loadingDbs ? (
              <div className="flex justify-center py-4">
                <Spinner size="sm" />
              </div>
            ) : databases?.length === 0 ? (
              <p className="text-xs text-fg-subtle px-2 py-2">{t('db.noDatabases')}</p>
            ) : (
              <ul className="space-y-0.5">
                {databases?.map((db) => (
                  <li key={db}>
                    <button
                      onClick={() => {
                        setDatabase(db)
                        setSelectedTable('')
                      }}
                      className={`
                        w-full flex items-center gap-2 px-2 h-9 rounded-md text-sm
                        transition-colors min-h-[36px]
                        $$
                          database === db
                            ? 'bg-accent/10 text-accent font-medium'
                            : 'text-fg-muted hover:text-fg hover:bg-fg/5'
                        }
                      `}
                    >
                      <DbIcon size={14} />
                      <span className="truncate">{db}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>

        <div className="flex-1 min-h-0 flex flex-col overflow-auto">
          {!database ? (
            <div className="flex-1 flex items-center justify-center text-fg-muted text-sm">
              {t('db.selectDatabase')}
            </div>
          ) : loadingTables ? (
            <div className="flex-1 flex items-center justify-center">
              <Spinner />
            </div>
          ) : (
            <>
              <div className="px-4 py-2.5 border-b border-border bg-bg-sunken/50 flex items-center gap-2">
                <Table size={14} className="text-fg-muted" />
                <span className="text-sm font-medium text-fg">{database}</span>
                <Badge variant="muted">{tables?.length || 0}{t('db.tablesCount')}</Badge>
              </div>

              <div className="flex-1 overflow-auto">
                {tables?.length === 0 ? (
                  <div className="p-8 text-center text-sm text-fg-muted">
                    {t('db.noTables')}
                  </div>
                ) : (
                  <ul className="divide-y divide-border">
                    {tables?.map((table) => (
                      <li key={table.name}>
                        <button
                          onClick={() => setSelectedTable(table.name)}
                          className={`
                            w-full flex items-center gap-3 px-4 py-2.5 text-sm
                            transition-colors text-left
                            $$
                              selectedTable === table.name
                                ? 'bg-accent/5'
                                : 'hover:bg-fg/5'
                            }
                          `}
                        >
                          <ChevronRight
                            size={14}
                            className={`text-fg-subtle transition-transform $$
                              selectedTable === table.name ? 'rotate-90' : ''
                            }`}
                          />
                          <span className="font-mono text-fg flex-1 truncate">{table.name}</span>
                          <span className="text-xs text-fg-subtle">
                            {formatNumber(table.rows)} {t('db.rows')}
                          </span>
                          <span className="text-xs text-fg-subtle hidden sm:inline">
                            {formatBytes(table.size)}
                          </span>
                        </button>

                        {selectedTable === table.name && columns && (
                          <div className="bg-bg-sunken/30 border-t border-border px-4 py-3 pl-11">
                            {loadingColumns ? (
                              <div className="py-2"><Spinner size="sm" /></div>
                            ) : (
                              <table className="w-full text-xs">
                                <thead>
                                  <tr className="text-fg-subtle text-left">
                                    <th className="font-medium pb-2">{t('db.field')}</th>
                                    <th className="font-medium pb-2">{t('db.type')}</th>
                                    <th className="font-medium pb-2">{t('db.nullable')}</th>
                                    <th className="font-medium pb-2">{t('db.key')}</th>
                                  </tr>
                                </thead>
                                <tbody className="text-fg">
                                  {columns.map((col) => (
                                    <tr key={col.name}>
                                      <td className="py-1 font-mono">{col.name}</td>
                                      <td className="py-1 text-fg-muted font-mono">{col.type}</td>
                                      <td className="py-1 text-fg-subtle">
                                        {col.nullable ? 'YES' : 'NO'}
                                      </td>
                                      <td className="py-1 text-fg-subtle">{col.key || '—'}</td>
                                    </tr>
                                  ))}
                                </tbody>
                              </table>
                            )}
                          </div>
                        )}
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
