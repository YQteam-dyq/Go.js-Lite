import { useState, useEffect, useMemo } from 'react'
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
  Download,
  ShieldAlert,
  ChevronDown,
  Check,
} from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Confirm, Modal } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import {
  operationLogApi,
  alertRulesApi,
  type ExportParams,
} from '@/api/operationLog'
import { notificationChannelsApi } from '@/api/notifications'
import { toast } from '@/components/ui/Toast'
import { useI18n } from '@/hooks/useI18n'
import { useIsMobile } from '@/hooks/useMediaQuery'
import type {
  OperationLogEntry,
  OperationLogAlertRule,
  NotificationChannel,
} from '@shared/types'

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

const emptyRule = (): Omit<OperationLogAlertRule, 'id'> => ({
  name: '',
  enabled: true,
  when: {},
  then: { channel_ids: [], severity: 'warning' },
})

function formatExportFilename(format: 'csv' | 'jsonl' | 'json'): string {
  const d = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  const stamp =
    d.getFullYear().toString() +
    pad(d.getMonth() + 1) +
    pad(d.getDate()) +
    '_' +
    pad(d.getHours()) +
    pad(d.getMinutes()) +
    pad(d.getSeconds())
  return `operation_log_${stamp}.${format}`
}

function dateToTs(value: string, endOfDay: boolean): number | undefined {
  if (!value) return undefined
  const [y, m, d] = value.split('-').map(Number)
  if (!y || !m || !d) return undefined
  const dt = new Date(y, m - 1, d, endOfDay ? 23 : 0, endOfDay ? 59 : 0, endOfDay ? 59 : 0)
  return dt.getTime()
}

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
  const [userInput, setUserInput] = useState('')
  const [user, setUser] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [showClear, setShowClear] = useState(false)
  const [clearing, setClearing] = useState(false)
  const [exportOpen, setExportOpen] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [alertModalOpen, setAlertModalOpen] = useState(false)
  const [savingRule, setSavingRule] = useState(false)
  const [editingRule, setEditingRule] = useState<OperationLogAlertRule | null>(null)
  const [ruleForm, setRuleForm] = useState<Omit<OperationLogAlertRule, 'id'>>(emptyRule())
  const [actionInOpen, setActionInOpen] = useState(false)
  const [actionNotInOpen, setActionNotInOpen] = useState(false)
  const [alertTab, setAlertTab] = useState<'conditions' | 'channels'>('conditions')

  const filterActive = useMemo(
    () => Boolean(type || ip || user || dateFrom || dateTo),
    [type, ip, user, dateFrom, dateTo],
  )

  const { data: channels = [] } = useQuery({
    queryKey: ['notification-channels'],
    queryFn: () => notificationChannelsApi.list(),
    staleTime: 60_000,
  })

  useEffect(() => {
    const handle = setTimeout(() => {
      setIp(ipInput.trim())
      setPage(1)
    }, 350)
    return () => clearTimeout(handle)
  }, [ipInput])

  useEffect(() => {
    const handle = setTimeout(() => {
      setUser(userInput.trim())
      setPage(1)
    }, 350)
    return () => clearTimeout(handle)
  }, [userInput])

  const { data, isLoading, error, refetch, isFetching } = useQuery({
    queryKey: ['operation-log', { type, ip, user, dateFrom, dateTo, page }],
    queryFn: () =>
      operationLogApi.list({
        type: type || undefined,
        ip: ip || undefined,
        user: user || undefined,
        dateFrom: dateToTs(dateFrom, false),
        dateTo: dateToTs(dateTo, true),
        page,
      }),
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

  const handleExport = async (
    format: 'csv' | 'jsonl' | 'json',
    scope: 'current_filter' | 'all',
  ) => {
    setExportOpen(false)
    setExporting(true)
    try {
      const params: ExportParams = { format, scope }
      if (scope === 'current_filter') {
        if (type) params.action = [type]
        if (ip) params.ip_like = ip
        if (user) params.user = user
        const fromTs = dateToTs(dateFrom, false)
        const toTs = dateToTs(dateTo, true)
        if (fromTs !== undefined) params.date_from = fromTs
        if (toTs !== undefined) params.date_to = toTs
      }
      const blob = await operationLogApi.exportBlob(params)
      const filename = formatExportFilename(format)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = filename
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      setTimeout(() => URL.revokeObjectURL(url), 5000)
      toast({ type: 'success', title: t('oplog.exportDownloaded', { filename }) })
    } catch (err) {
      toast({
        type: 'error',
        title: t('oplog.exportFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    } finally {
      setExporting(false)
    }
  }

  const openNewRule = () => {
    setEditingRule(null)
    setRuleForm(emptyRule())
    setAlertTab('conditions')
    setAlertModalOpen(true)
  }

  const toggleConditionAction = (list: 'in' | 'notin', action: string) => {
    setRuleForm((prev) => {
      const key = list === 'in' ? 'action_in' : 'action_not_in'
      const current = new Set(prev.when[key] ?? [])
      if (current.has(action)) current.delete(action)
      else current.add(action)
      const arr = Array.from(current)
      return {
        ...prev,
        when: {
          ...prev.when,
          [key]: arr.length > 0 ? arr : undefined,
        },
      }
    })
  }

  const handleSaveRule = async () => {
    setSavingRule(true)
    try {
      if (editingRule) {
        await alertRulesApi.update(editingRule.id, ruleForm)
      } else {
        await alertRulesApi.create(ruleForm)
      }
      toast({ type: 'success', title: t('oplog.alertRuleSaved') })
      setAlertModalOpen(false)
      queryClient.invalidateQueries({ queryKey: ['alert-rules'] })
    } catch (err) {
      toast({
        type: 'error',
        title: t('common.saveFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    } finally {
      setSavingRule(false)
    }
  }

  const handleTestRule = async () => {
    if (!editingRule) {
      try {
        const created = await alertRulesApi.create({
          ...ruleForm,
          enabled: true,
        })
        try {
          await alertRulesApi.test(created.id)
          toast({ type: 'success', title: t('oplog.alertRuleTest') + ': OK' })
        } finally {
          await alertRulesApi.remove(created.id).catch(() => {})
        }
      } catch (err) {
        toast({
          type: 'error',
          title: t('oplog.alertRuleTest') + ' ' + t('common.failure'),
          description: err instanceof Error ? err.message : t('common.unknownError'),
        })
      }
      return
    }
    try {
      await alertRulesApi.test(editingRule.id)
      toast({ type: 'success', title: t('oplog.alertRuleTest') + ': OK' })
    } catch (err) {
      toast({
        type: 'error',
        title: t('oplog.alertRuleTest') + ' ' + t('common.failure'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    }
  }

  const toggleChannel = (channelId: string) => {
    setRuleForm((prev) => {
      const ids = new Set(prev.then.channel_ids ?? [])
      if (ids.has(channelId)) ids.delete(channelId)
      else ids.add(channelId)
      return {
        ...prev,
        then: { ...prev.then, channel_ids: Array.from(ids) },
      }
    })
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

  const hours = ruleForm.when.outside_hours_range ?? ''
  const [hoursFrom, hoursTo] = hours.includes('-') ? hours.split('-', 2) : ['', '']
  const setHours = (from: string, to: string) => {
    setRuleForm((prev) => ({
      ...prev,
      when: {
        ...prev.when,
        outside_hours_range: from && to ? `${from}-${to}` : undefined,
      },
    }))
  }

  const toggleInline = (checked: boolean, onChange: (v: boolean) => void, label: string) => (
    <button
      type="button"
      onClick={() => onChange(!checked)}
      className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none ${
        checked ? 'bg-accent' : 'bg-fg/15'
      }`}
      role="switch"
      aria-checked={checked}
      aria-label={label}
    >
      <span
        className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition-transform ${
          checked ? 'translate-x-5' : 'translate-x-0'
        }`}
      />
    </button>
  )

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
        <div className="flex items-center gap-2 flex-wrap">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => refetch()}
            disabled={isLoading || isFetching}
          >
            <RefreshCw size={16} className={isFetching ? 'animate-spin' : ''} />
            {!isMobile && t('operationLog.refresh')}
          </Button>

          <div className="relative">
            <Button
              variant="secondary"
              size="sm"
              onClick={() => setExportOpen((v) => !v)}
              disabled={exporting}
              className="relative"
            >
              <Download size={16} />
              {!isMobile && t('oplog.exportMenu')}
              <ChevronDown size={14} />
            </Button>
            {exportOpen && (
              <>
                <div className="fixed inset-0 z-40" onClick={() => setExportOpen(false)} />
                <div className="absolute right-0 mt-1 z-50 min-w-[200px] rounded-xl border border-border bg-bg-elevated shadow-lg p-1">
                  <button
                    onClick={() => handleExport('csv', 'current_filter')}
                    disabled={!filterActive}
                    className={`w-full text-left px-3 py-2 rounded-lg text-sm transition-colors ${
                      filterActive
                        ? 'hover:bg-fg/5 text-fg'
                        : 'text-fg-muted opacity-60 cursor-not-allowed'
                    }`}
                  >
                    {t('oplog.exportCsvCurrent')}
                  </button>
                  <button
                    onClick={() => handleExport('csv', 'all')}
                    className="w-full text-left px-3 py-2 rounded-lg text-sm hover:bg-fg/5 text-fg transition-colors"
                  >
                    {t('oplog.exportCsvAll')}
                  </button>
                  <button
                    onClick={() => handleExport('jsonl', 'current_filter')}
                    disabled={!filterActive}
                    className={`w-full text-left px-3 py-2 rounded-lg text-sm transition-colors ${
                      filterActive
                        ? 'hover:bg-fg/5 text-fg'
                        : 'text-fg-muted opacity-60 cursor-not-allowed'
                    }`}
                  >
                    {t('oplog.exportJsonlCurrent')}
                  </button>
                  <button
                    onClick={() => handleExport('jsonl', 'all')}
                    className="w-full text-left px-3 py-2 rounded-lg text-sm hover:bg-fg/5 text-fg transition-colors"
                  >
                    {t('oplog.exportJsonlAll')}
                  </button>
                  <button
                    onClick={() => handleExport('json', 'current_filter')}
                    disabled={!filterActive}
                    className={`w-full text-left px-3 py-2 rounded-lg text-sm transition-colors ${
                      filterActive
                        ? 'hover:bg-fg/5 text-fg'
                        : 'text-fg-muted opacity-60 cursor-not-allowed'
                    }`}
                  >
                    {t('oplog.exportJsonCurrent')}
                  </button>
                  <button
                    onClick={() => handleExport('json', 'all')}
                    className="w-full text-left px-3 py-2 rounded-lg text-sm hover:bg-fg/5 text-fg transition-colors"
                  >
                    {t('oplog.exportJsonAll')}
                  </button>
                </div>
              </>
            )}
          </div>

          <Button variant="secondary" size="sm" onClick={openNewRule}>
            <ShieldAlert size={16} />
            {!isMobile && t('oplog.alertRules')}
          </Button>

          <Button
            variant="secondary"
            size="sm"
            onClick={() => setShowClear(true)}
            disabled={isLoading || total === 0}
          >
            <Trash2 size={16} />
            {!isMobile && t('operationLog.clear')}
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
            <div className="flex-1 min-w-[180px]">
              <Input
                placeholder={t('operationLog.userFilter')}
                value={userInput}
                onChange={(e) => setUserInput(e.target.value)}
                icon={<Search size={16} />}
              />
            </div>
            <div className="flex items-center gap-2">
              <span className="text-xs text-fg-muted shrink-0 whitespace-nowrap">
                {t('operationLog.dateFrom')}
              </span>
              <input
                type="date"
                value={dateFrom}
                onChange={(e) => {
                  setDateFrom(e.target.value)
                  setPage(1)
                }}
                className="input-base h-10 px-3 rounded-lg text-sm"
              />
            </div>
            <div className="flex items-center gap-2">
              <span className="text-xs text-fg-muted shrink-0 whitespace-nowrap">
                {t('operationLog.dateTo')}
              </span>
              <input
                type="date"
                value={dateTo}
                onChange={(e) => {
                  setDateTo(e.target.value)
                  setPage(1)
                }}
                className="input-base h-10 px-3 rounded-lg text-sm"
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
                type || ip || user || dateFrom || dateTo
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

      <Modal
        open={alertModalOpen}
        onClose={() => !savingRule && setAlertModalOpen(false)}
        size="lg"
        title={
          <span className="flex items-center gap-2">
            <ShieldAlert size={18} className="text-accent" />
            {editingRule ? t('oplog.enableRule') : t('oplog.newAlertRule')}
          </span>
        }
        footer={
          <div className="flex items-center justify-end gap-2">
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setAlertModalOpen(false)}
              disabled={savingRule}
            >
              {t('common.cancel')}
            </Button>
            <Button variant="secondary" size="sm" onClick={handleTestRule} disabled={savingRule}>
              {t('oplog.alertRuleTest')}
            </Button>
            <Button variant="primary" size="sm" onClick={handleSaveRule} loading={savingRule}>
              {t('common.save')}
            </Button>
          </div>
        }
      >
        <div className="space-y-4 py-2">
          <div className="flex items-center gap-3">
            <Input
              placeholder={t('oplog.alertRuleName')}
              value={ruleForm.name}
              onChange={(e) => setRuleForm((p) => ({ ...p, name: e.target.value }))}
              className="flex-1"
            />
            <div className="flex items-center gap-2 shrink-0">
              <span className="text-sm text-fg-muted">{t('oplog.alertRuleEnabled')}</span>
              {toggleInline(
                ruleForm.enabled,
                (v) => setRuleForm((p) => ({ ...p, enabled: v })),
                t('oplog.alertRuleEnabled'),
              )}
            </div>
          </div>

          <div className="flex items-center gap-1 bg-bg-sunken p-1 rounded-lg">
            <button
              type="button"
              onClick={() => setAlertTab('conditions')}
              className={`flex-1 px-3 py-2 rounded-md text-sm font-medium transition-colors ${
                alertTab === 'conditions'
                  ? 'bg-bg-elevated text-fg shadow-sm'
                  : 'text-fg-muted hover:text-fg'
              }`}
            >
              {t('oplog.alertRuleConditionsTab')}
            </button>
            <button
              type="button"
              onClick={() => setAlertTab('channels')}
              className={`flex-1 px-3 py-2 rounded-md text-sm font-medium transition-colors ${
                alertTab === 'channels'
                  ? 'bg-bg-elevated text-fg shadow-sm'
                  : 'text-fg-muted hover:text-fg'
              }`}
            >
              {t('oplog.alertRuleChannelsTab')}
            </button>
          </div>

          {alertTab === 'conditions' ? (
            <div className="space-y-3">
              <div className="rounded-xl border border-border p-3">
                <div className="flex items-center justify-between mb-2">
                  <label className="text-sm font-medium text-fg">
                    {t('oplog.alertRuleActionIn')}
                  </label>
                  <button
                    type="button"
                    onClick={() => {
                      setActionInOpen((v) => !v)
                      setActionNotInOpen(false)
                    }}
                    className="text-xs text-accent hover:underline"
                  >
                    {(ruleForm.when.action_in?.length ?? 0) > 0
                      ? `${ruleForm.when.action_in?.length} ${t('common.selected')}`
                      : t('common.add')}
                  </button>
                </div>
                {actionInOpen && (
                  <div className="grid grid-cols-2 gap-1 max-h-48 overflow-auto">
                    {TYPE_OPTIONS.map((opt) => {
                      const sel = (ruleForm.when.action_in ?? []).includes(opt)
                      return (
                        <button
                          key={opt}
                          type="button"
                          onClick={() => toggleConditionAction('in', opt)}
                          className={`flex items-center gap-2 px-2 py-1.5 rounded-lg text-xs text-left transition-colors ${
                            sel ? 'bg-accent/10 text-accent' : 'hover:bg-fg/5 text-fg-muted'
                          }`}
                        >
                          {sel && <Check size={12} />}
                          <span className="font-mono">{opt}</span>
                        </button>
                      )
                    })}
                  </div>
                )}
              </div>

              <div className="rounded-xl border border-border p-3">
                <div className="flex items-center justify-between mb-2">
                  <label className="text-sm font-medium text-fg">
                    {t('oplog.alertRuleActionNotIn')}
                  </label>
                  <button
                    type="button"
                    onClick={() => {
                      setActionNotInOpen((v) => !v)
                      setActionInOpen(false)
                    }}
                    className="text-xs text-accent hover:underline"
                  >
                    {(ruleForm.when.action_not_in?.length ?? 0) > 0
                      ? `${ruleForm.when.action_not_in?.length} ${t('common.selected')}`
                      : t('common.add')}
                  </button>
                </div>
                {actionNotInOpen && (
                  <div className="grid grid-cols-2 gap-1 max-h-48 overflow-auto">
                    {TYPE_OPTIONS.map((opt) => {
                      const sel = (ruleForm.when.action_not_in ?? []).includes(opt)
                      return (
                        <button
                          key={opt}
                          type="button"
                          onClick={() => toggleConditionAction('notin', opt)}
                          className={`flex items-center gap-2 px-2 py-1.5 rounded-lg text-xs text-left transition-colors ${
                            sel ? 'bg-accent/10 text-accent' : 'hover:bg-fg/5 text-fg-muted'
                          }`}
                        >
                          {sel && <Check size={12} />}
                          <span className="font-mono">{opt}</span>
                        </button>
                      )
                    })}
                  </div>
                )}
              </div>

              <div className="rounded-xl border border-border p-3">
                <div className="flex items-center justify-between">
                  <label className="text-sm font-medium text-fg">
                    {t('oplog.alertRuleIpNotWhitelisted')}
                  </label>
                  {toggleInline(
                    Boolean(ruleForm.when.ip_not_in_whitelist),
                    (v) =>
                      setRuleForm((p) => ({
                        ...p,
                        when: { ...p.when, ip_not_in_whitelist: v ? true : undefined },
                      })),
                    t('oplog.alertRuleIpNotWhitelisted'),
                  )}
                </div>
              </div>

              <div className="rounded-xl border border-border p-3">
                <label className="block text-sm font-medium text-fg mb-2">
                  {t('oplog.alertRuleOutsideHours')}
                </label>
                <div className="flex items-center gap-2">
                  <input
                    type="time"
                    value={hoursFrom}
                    onChange={(e) => setHours(e.target.value, hoursTo)}
                    className="input-base h-9 px-3 rounded-lg text-sm"
                  />
                  <span className="text-fg-muted">—</span>
                  <input
                    type="time"
                    value={hoursTo}
                    onChange={(e) => setHours(hoursFrom, e.target.value)}
                    className="input-base h-9 px-3 rounded-lg text-sm"
                  />
                </div>
              </div>

              <div className="rounded-xl border border-border p-3">
                <label className="block text-sm font-medium text-fg mb-2">
                  {t('oplog.alertRuleConsecFailN')}
                </label>
                <input
                  type="number"
                  min={1}
                  value={ruleForm.when.consecutive_fail_login_gt_N ?? ''}
                  onChange={(e) => {
                    const v = e.target.value === '' ? undefined : Number(e.target.value)
                    setRuleForm((p) => ({
                      ...p,
                      when: {
                        ...p.when,
                        consecutive_fail_login_gt_N: v && v > 0 ? v : undefined,
                      },
                    }))
                  }}
                  placeholder="5"
                  className="input-base h-9 px-3 rounded-lg text-sm w-32"
                />
              </div>
            </div>
          ) : (
            <div className="space-y-3">
              <div className="flex items-center gap-3">
                <span className="text-sm font-medium text-fg">
                  {t('oplog.alertRuleSeverity')}
                </span>
                <div className="flex items-center gap-1">
                  {(['info', 'warning', 'critical'] as const).map((sev) => (
                    <button
                      key={sev}
                      type="button"
                      onClick={() =>
                        setRuleForm((p) => ({ ...p, then: { ...p.then, severity: sev } }))
                      }
                      className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
                        ruleForm.then.severity === sev
                          ? sev === 'critical'
                            ? 'bg-danger/15 text-danger border border-danger/30'
                            : sev === 'warning'
                              ? 'bg-warning/15 text-warning border border-warning/30'
                              : 'bg-info/15 text-info border border-info/30'
                          : 'bg-fg/5 text-fg-muted hover:text-fg border border-transparent'
                      }`}
                    >
                      {t(`oplog.severity${sev.charAt(0).toUpperCase() + sev.slice(1)}`)}
                    </button>
                  ))}
                </div>
              </div>

              <div className="rounded-xl border border-border">
                <div className="px-3 py-2 border-b border-border">
                  <span className="text-sm font-medium text-fg">
                    {t('oplog.alertRuleChannelSelect')}
                  </span>
                </div>
                {channels.length === 0 ? (
                  <div className="p-6 text-center text-sm text-fg-muted">
                    {t('notify.noChannelsHint')}
                  </div>
                ) : (
                  <div className="max-h-60 overflow-auto">
                    {channels.map((ch: NotificationChannel) => {
                      const sel = (ruleForm.then.channel_ids ?? []).includes(ch.id)
                      return (
                        <label
                          key={ch.id}
                          className={`flex items-center gap-3 px-3 py-2 border-b last:border-b-0 border-border cursor-pointer transition-colors ${
                            sel ? 'bg-accent/5' : 'hover:bg-fg/5'
                          }`}
                        >
                          <input
                            type="checkbox"
                            checked={sel}
                            onChange={() => toggleChannel(ch.id)}
                            className="w-4 h-4 rounded border-border text-accent"
                          />
                          <div className="flex-1 min-w-0">
                            <div className="text-sm font-medium text-fg">{ch.name}</div>
                            <div className="text-xs text-fg-muted">
                              {ch.type} ·{' '}
                              {ch.type === 'email'
                                ? ch.from_addr ?? t('common.notSet')
                                : ch.type === 'smtp'
                                  ? `${ch.host}:${ch.port}`
                                  : ch.type === 'webhook'
                                    ? ch.url
                                    : t('common.notSet')}
                            </div>
                          </div>
                          {ch.enabled === false && (
                            <Badge variant="muted" className="text-[10px]">
                              {t('common.disabled')}
                            </Badge>
                          )}
                        </label>
                      )
                    })}
                  </div>
                )}
              </div>
            </div>
          )}
        </div>
      </Modal>
    </div>
  )
}
