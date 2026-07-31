import { useState, useEffect } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  History,
  RefreshCw,
  Trash2,
  Search,
  AlertTriangle,
  ChevronLeft,
  ChevronRight,
  Filter,
} from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Confirm } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { operationLogApi } from '@/api/operationLog'
import { toast } from '@/components/ui/Toast'
import { useI18n } from '@/hooks/useI18n'
import { useIsMobile } from '@/hooks/useMediaQuery'
import type { OperationLogEntry } from '@shared/types'

const TYPE_OPTIONS = [
  'file_delete',
  'file_rename',
  'file_upload',
  'file_save',
  'file_mkdir',
  'file_chmod',
  'file_compress',
  'file_extract',
  'db_sql_exec',
  'db_import',
  'settings_update',
  'password_change',
  'token_regenerate',
  'operation_log_clear',
]

function getActionColor(action: string): {
  variant: 'danger' | 'accent' | 'success' | 'muted'
  className: string
} {
  const lower = action.toLowerCase()
  if (lower.indexOf('delete') !== -1 || lower.indexOf('drop') !== -1) {
    return { variant: 'danger', className: 'bg-danger/10 text-danger border-danger/20' }
  }
  if (
    lower.indexOf('upload') !== -1 ||
    lower.indexOf('create') !== -1 ||
    lower.indexOf('mkdir') !== -1
  ) {
    return { variant: 'accent', className: 'bg-accent/10 text-accent border-accent/20' }
  }
  if (lower.indexOf('import') !== -1 || lower.indexOf('export') !== -1) {
    return { variant: 'muted', className: 'bg-warning/10 text-warning border-warning/20' }
  }
  return { variant: 'success', className: 'bg-success/10 text-success border-success/20' }
}

export default function OperationLog() {
  const queryClient = useQueryClient()
  const { t } = useI18n()
  const isMobile = useIsMobile()

  const [page, setPage] = useState(1)
  const [type, setType] = useState('')
  const [ipInput, setIpInput] = useState('')
  const [ip, setIp] = useState('')
  const [showClear, setShowClear] = useState(false)
  const [clearing, setClearing] = useState(false)

  // IP 输入防抖
  useEffect(() => {
    const handle = setTimeout(() => {
      setIp(ipInput.trim())
      setPage(1)
    }, 350)
    return () => clearTimeout(handle)
  }, [ipInput])

  const { data, isLoading, error, refetch, isFetching } = useQuery({
    queryKey: ['operation-log', { type, ip, page }],
    queryFn: () => operationLogApi.list({ type: type || undefined, ip: ip || undefined, page }),
    placeholderData: (prev) => prev,
  })

  const handleClear = async () => {
    setClearing(true)
    try {
      await operationLogApi.clear()
      toast({ type: 'success', title: t('operationLog.cleared') })
      setShowClear(false)
      setPage(1)
      queryClient.invalidateQueries({ queryKey: ['operation-log'] })
    } catch (err) {
      toast({
        type: 'error',
        title: t('operationLog.clearFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    } finally {
      setClearing(false)
    }
  }

  const handleTypeChange = (value: string) => {
    setType(value)
    setPage(1)
  }

  const totalPages = data?.total_pages ?? 1
  const currentPage = data?.page ?? 1
  const total = data?.total ?? 0
  const logs = data?.logs ?? []

  const renderPagination = () => {
    if (total === 0) return null
    const pages: number[] = []
    const start = Math.max(1, currentPage - 2)
    const end = Math.min(totalPages, start + 4)
    for (let i = start; i <= end; i++) pages.push(i)

    return (
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div className="text-xs text-fg-muted">
          {t('operationLog.totalCount', { count: total })}
        </div>
        <div className="flex items-center gap-1">
          <Button
            variant="ghost"
            size="icon-sm"
            onClick={() => setPage(currentPage - 1)}
            disabled={currentPage <= 1 || isFetching}
            aria-label={t('operationLog.prevPage')}
          >
            <ChevronLeft size={16} />
          </Button>
          {pages.map((p) => (
            <button
              key={p}
              onClick={() => setPage(p)}
              disabled={isFetching}
              className={`
                min-w-[32px] h-8 px-2 rounded-lg text-xs font-medium transition-colors
                ${p === currentPage
                  ? 'bg-accent text-accent-fg'
                  : 'text-fg-muted hover:text-fg hover:bg-fg/5'}
              `}
            >
              {p}
            </button>
          ))}
          <Button
            variant="ghost"
            size="icon-sm"
            onClick={() => setPage(currentPage + 1)}
            disabled={currentPage >= totalPages || isFetching}
            aria-label={t('operationLog.nextPage')}
          >
            <ChevronRight size={16} />
          </Button>
        </div>
      </div>
    )
  }

  const renderEntry = (entry: OperationLogEntry, idx: number) => {
    const color = getActionColor(entry.action)
    return (
      <li key={idx} className="p-3 hover:bg-fg/5 transition-colors">
        <div className="grid grid-cols-[1fr_auto_auto] gap-4 items-start sm:items-center">
          <div className="flex items-start gap-3 min-w-0">
            <Badge
              variant={color.variant}
              className={`shrink-0 mt-0.5 border ${color.className} font-mono text-[10px]`}
            >
              {entry.action}
            </Badge>
            <div className="min-w-0 flex-1">
              <code className="text-xs text-fg break-all font-mono leading-relaxed block">
                {entry.target || '—'}
              </code>
              {entry.detail ? (
                <p className="text-[11px] text-fg-subtle mt-1 break-all">{entry.detail}</p>
              ) : null}
              {!entry.result && (
                <p className="text-[11px] text-danger mt-1">{t('operationLog.failed')}</p>
              )}
            </div>
          </div>
          <time className="font-mono text-[11px] text-fg-muted shrink-0 hidden sm:block whitespace-nowrap">
            {entry.time}
          </time>
          <span className="text-[11px] text-fg-muted shrink-0 hidden sm:block whitespace-nowrap">
            {entry.ip}
          </span>
        </div>
        <div className="sm:hidden flex items-center gap-3 mt-2 text-[11px] text-fg-muted pl-9">
          <time className="font-mono">{entry.time}</time>
          <span className="inline-flex items-center gap-1">
            <span className="inline-block w-1.5 h-1.5 rounded-full bg-fg-subtle" />
            {entry.ip}
          </span>
        </div>
      </li>
    )
  }

  return (
    <div className="p-4 md:p-6 space-y-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <History size={22} className="text-accent" />
            {t('operationLog.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('operationLog.subtitle')}</p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => refetch()}
            disabled={isLoading || isFetching}
          >
            <RefreshCw size={16} className={isFetching ? 'animate-spin' : ''} />
            {t('operationLog.refresh')}
          </Button>
          <Button
            variant="secondary"
            size="sm"
            onClick={() => setShowClear(true)}
            disabled={isLoading || total === 0}
          >
            <Trash2 size={16} />
            {t('operationLog.clear')}
          </Button>
        </div>
      </div>

      <Card className="card-hover">
        <CardHeader>
          <div className="flex items-center gap-2 flex-wrap w-full">
            <div className="relative">
              <Filter
                size={14}
                className="absolute left-3 top-1/2 -translate-y-1/2 text-fg-muted pointer-events-none"
              />
              <select
                value={type}
                onChange={(e) => handleTypeChange(e.target.value)}
                className="input-base pl-9 pr-8 h-10 appearance-none cursor-pointer text-sm min-w-[180px]"
                aria-label={t('operationLog.typeFilter')}
              >
                <option value="">{t('operationLog.allTypes')}</option>
                {TYPE_OPTIONS.map((opt) => (
                  <option key={opt} value={opt}>
                    {opt}
                  </option>
                ))}
              </select>
            </div>
            <div className="flex-1 min-w-[180px]">
              <Input
                placeholder={t('operationLog.ipPlaceholder')}
                value={ipInput}
                onChange={(e) => setIpInput(e.target.value)}
                icon={<Search size={16} />}
              />
            </div>
          </div>
        </CardHeader>
        <CardBody className="p-0">
          {isLoading ? (
            <div className="p-12 flex justify-center">
              <Spinner />
            </div>
          ) : error ? (
            <div className="p-8 text-center text-danger">
              <AlertTriangle size={24} className="mx-auto mb-2" />
              <p className="text-sm">
                {error instanceof Error ? error.message : t('common.error')}
              </p>
            </div>
          ) : logs.length === 0 ? (
            <EmptyState
              icon={
                <div className="w-16 h-16 rounded-full bg-bg-sunken flex items-center justify-center text-fg-subtle mx-auto">
                  <History size={28} />
                </div>
              }
              title={t('operationLog.empty')}
              description={
                type || ip
                  ? t('operationLog.emptyHintFiltered')
                  : t('operationLog.emptyHint')
              }
            />
          ) : isMobile ? (
            <ul className="divide-y divide-border">{logs.map(renderEntry)}</ul>
          ) : (
            <div className="max-h-[600px] overflow-auto">
              <ul className="divide-y divide-border">{logs.map(renderEntry)}</ul>
            </div>
          )}
        </CardBody>
      </Card>

      {!isLoading && !error && logs.length > 0 && (
        <div className="pb-2">{renderPagination()}</div>
      )}

      <Confirm
        open={showClear}
        onCancel={() => setShowClear(false)}
        title={t('operationLog.clearTitle')}
        message={t('operationLog.clearConfirm')}
        confirmText={t('operationLog.clearConfirmBtn')}
        variant="danger"
        loading={clearing}
        onConfirm={handleClear}
      />
    </div>
  )
}
