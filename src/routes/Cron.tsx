import { useMemo, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  CalendarClock,
  Plus,
  Pencil,
  Trash2,
  RefreshCw,
  AlertTriangle,
  CheckCircle2,
  XCircle,
  Clock,
  ChevronDown,
  Terminal,
} from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input, Textarea } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Spinner } from '@/components/ui/Spinner'
import { Modal, Confirm } from '@/components/ui/Modal'
import { cronApi } from '@/api/cron'
import { toast } from '@/components/ui/Toast'
import { useI18n } from '@/hooks/useI18n'
import type { CronJob } from '@shared/types'

interface Template {
  key: string
  expression: string
  command: string
}

const TEMPLATES: Template[] = [
  { key: 'dailyBackup', expression: '0 3 * * *', command: 'tar -czf /backup/www.tar.gz /var/www/html' },
  { key: 'clearCache', expression: '0 */6 * * *', command: 'rm -rf /tmp/cache/*' },
  { key: 'phpScript', expression: '0 4 * * *', command: 'php /path/to/script.php' },
]

function matchCronField(pattern: string, value: number): boolean {
  if (pattern === '*') return true
  if (pattern.includes(',')) {
    return pattern.split(',').some((p) => matchCronField(p, value))
  }
  if (pattern.includes('/')) {
    const [base, step] = pattern.split('/')
    const stepNum = parseInt(step, 10)
    if (Number.isNaN(stepNum) || stepNum <= 0) return false
    if (base === '*') return value % stepNum === 0
    const baseNum = parseInt(base, 10)
    if (Number.isNaN(baseNum)) return false
    return value >= baseNum && (value - baseNum) % stepNum === 0
  }
  if (pattern.includes('-')) {
    const [start, end] = pattern.split('-').map((n) => parseInt(n, 10))
    if (Number.isNaN(start) || Number.isNaN(end)) return false
    return value >= start && value <= end
  }
  const v = parseInt(pattern, 10)
  return !Number.isNaN(v) && v === value
}

function getNextRunTime(expression: string): Date | null {
  const parts = expression.split(/\s+/)
  if (parts.length !== 5) return null
  const [min, hour, day, month, weekday] = parts
  const next = new Date()
  next.setSeconds(0, 0)
  next.setMinutes(next.getMinutes() + 1)
  // 最多检查 525600 分钟（一年）
  for (let i = 0; i < 525600; i++) {
    if (
      matchCronField(min, next.getMinutes()) &&
      matchCronField(hour, next.getHours()) &&
      matchCronField(day, next.getDate()) &&
      matchCronField(month, next.getMonth() + 1) &&
      matchCronField(weekday, next.getDay())
    ) {
      return next
    }
    next.setMinutes(next.getMinutes() + 1)
  }
  return null
}

function formatDateTime(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function isValidExpression(expr: string): boolean {
  const fields = expr.trim().split(/\s+/)
  return fields.length === 5
}

interface EditingJob {
  expression: string
  command: string
}

export default function Cron() {
  const { t, hasKey } = useI18n()
  const queryClient = useQueryClient()

  const [editing, setEditing] = useState(false)
  const [editIndex, setEditIndex] = useState<number | null>(null)
  const [form, setForm] = useState<EditingJob>({ expression: '', command: '' })
  const [deleteIndex, setDeleteIndex] = useState<number | null>(null)
  const [saving, setSaving] = useState(false)

  const { data: caps, isLoading: loadingCaps } = useQuery({
    queryKey: ['cron-capabilities'],
    queryFn: () => cronApi.capabilities(),
  })

  const { data: jobs, isLoading: loadingJobs, error, refetch } = useQuery({
    queryKey: ['cron-jobs'],
    queryFn: () => cronApi.list(),
    enabled: !!caps?.available,
  })

  const saveMutation = useMutation({
    mutationFn: (nextJobs: CronJob[]) => cronApi.save(nextJobs),
    onSuccess: () => {
      toast({ type: 'success', title: t('cron.saveSuccess') })
      queryClient.invalidateQueries({ queryKey: ['cron-jobs'] })
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('cron.saveFailed'), description: err.message })
    },
  })

  const openAdd = () => {
    setEditIndex(null)
    setForm({ expression: '', command: '' })
    setEditing(true)
  }

  const openEdit = (index: number) => {
    if (!jobs) return
    const job = jobs[index]
    setEditIndex(index)
    setForm({ expression: job.expression, command: job.command })
    setEditing(true)
  }

  const applyTemplate = (tpl: Template) => {
    setForm({ expression: tpl.expression, command: tpl.command })
  }

  const handleSave = async () => {
    if (!isValidExpression(form.expression)) {
      toast({ type: 'warning', title: t('cron.invalidExpression') })
      return
    }
    if (!form.command.trim()) {
      toast({ type: 'warning', title: t('cron.commandRequired') })
      return
    }

    const base = jobs ? [...jobs] : []
    const job: CronJob = { expression: form.expression.trim(), command: form.command.trim() }
    if (editIndex !== null) {
      base[editIndex] = job
    } else {
      base.push(job)
    }

    setSaving(true)
    try {
      await saveMutation.mutateAsync(base)
      setEditing(false)
    } finally {
      setSaving(false)
    }
  }

  const handleConfirmDelete = async () => {
    if (deleteIndex === null || !jobs) return
    const next = jobs.filter((_, i) => i !== deleteIndex)
    setSaving(true)
    try {
      await saveMutation.mutateAsync(next)
      setDeleteIndex(null)
    } finally {
      setSaving(false)
    }
  }

  const nextRunMap = useMemo(() => {
    const map: Record<number, Date | null> = {}
    if (jobs) {
      jobs.forEach((job, i) => {
        map[i] = getNextRunTime(job.expression)
      })
    }
    return map
  }, [jobs])

  const available = caps?.available ?? false
  const method = caps?.method ?? 'none'

  return (
    <div className="p-4 md:p-6 space-y-5">
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div className="min-w-0">
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <CalendarClock size={22} className="text-accent" />
            {t('cron.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('cron.subtitle')}</p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => refetch()}
            disabled={loadingJobs || !available}
          >
            <RefreshCw size={16} />
            <span className="hidden sm:inline">{t('common.refresh')}</span>
          </Button>
          <Button
            variant="primary"
            size="sm"
            onClick={openAdd}
            disabled={!available}
          >
            <Plus size={16} />
            <span className="hidden sm:inline">{t('cron.add')}</span>
          </Button>
        </div>
      </div>

      {/* 能力检测卡片 */}
      {loadingCaps ? (
        <Card>
          <CardBody className="flex items-center justify-center py-8">
            <Spinner />
          </CardBody>
        </Card>
      ) : !available ? (
        <Card className="border-warning/30">
          <CardBody className="flex items-start gap-3">
            <div className="w-10 h-10 rounded-lg bg-warning/10 text-warning flex items-center justify-center shrink-0">
              <AlertTriangle size={20} />
            </div>
            <div className="min-w-0">
              <div className="text-sm font-medium text-fg">
                {caps?.message_key && hasKey(`cron.${caps.message_key}`)
                  ? t(`cron.${caps.message_key}`)
                  : hasKey('cron.notAvailable')
                    ? t('cron.notAvailable')
                    : (caps?.message || t('cron.unavailable'))}
              </div>
              <p className="text-xs text-fg-muted mt-1 leading-relaxed">
                {caps?.message_key && hasKey(`cron.${caps.message_key}_detail`)
                  ? t(`cron.${caps.message_key}_detail`, caps.message_params as Record<string, string | number> | undefined)
                  : hasKey('cron.notAvailableDesc')
                    ? t('cron.notAvailableDesc')
                    : (caps?.message || t('cron.unavailableDetail'))}
              </p>
            </div>
          </CardBody>
        </Card>
      ) : (
        <Card>
          <CardBody className="flex items-start gap-3">
            <div className="w-10 h-10 rounded-lg bg-success/10 text-success flex items-center justify-center shrink-0">
              <CheckCircle2 size={20} />
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-2 flex-wrap">
                <span className="text-sm font-medium text-fg">{t('cron.available')}</span>
                <Badge variant={method === 'exec' ? 'success' : 'accent'}>
                  {method === 'exec' ? t('cron.methodExec') : t('cron.methodFile')}
                </Badge>
              </div>
              <p className="text-xs text-fg-muted mt-1 leading-relaxed">
                {method === 'exec'
                  ? t('cron.methodExecDesc')
                  : t('cron.methodFileDesc')}
              </p>
              {method === 'file' && caps?.cron_file && (
                <p className="text-xs text-fg-subtle mt-1.5 font-mono break-all">
                  {t('cron.cronFile')}：{caps.cron_file}
                </p>
              )}
            </div>
          </CardBody>
        </Card>
      )}

      {/* 任务列表 */}
      {available && (
        <Card>
          <CardHeader className="flex items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              <CalendarClock size={16} className="text-fg-muted" />
              <div>
                <div className="text-sm font-medium text-fg">{t('cron.jobList')}</div>
                <div className="text-xs text-fg-subtle">{t('cron.jobListSubtitle')}</div>
              </div>
            </div>
            {jobs && <Badge variant="muted">{jobs.length}{t('cron.jobCount')}</Badge>}
          </CardHeader>
          <CardBody className="p-0">
            {loadingJobs ? (
              <div className="p-8 flex justify-center">
                <Spinner />
              </div>
            ) : error ? (
              <div className="p-6 text-center text-sm text-danger">
                <XCircle size={24} className="mx-auto mb-2" />
                <p>
                  {error instanceof Error ? error.message : t('common.error')}
                </p>
                <Button
                  variant="secondary"
                  size="sm"
                  className="mt-3"
                  onClick={() => refetch()}
                >
                  {t('common.retry')}
                </Button>
              </div>
            ) : !jobs || jobs.length === 0 ? (
              <div className="p-8 text-center text-sm text-fg-muted">
                <CalendarClock size={28} className="mx-auto mb-2 opacity-50" />
                <p>{t('cron.noJobs')}</p>
                <p className="text-xs text-fg-subtle mt-1">{t('cron.noJobsHint')}</p>
              </div>
            ) : (
              <ul className="divide-y divide-border">
                {jobs.map((job, i) => {
                  const next = nextRunMap[i]
                  return (
                    <li
                      key={`${job.expression}-${i}`}
                      className="px-4 py-3 hover:bg-fg/5 transition-colors"
                    >
                      <div className="flex items-start justify-between gap-3 flex-wrap">
                        <div className="min-w-0 flex-1">
                          <div className="flex items-center gap-2 text-xs font-mono text-fg-muted flex-wrap">
                            <Clock size={12} className="shrink-0" />
                            <span className="text-accent">{job.expression}</span>
                            {next && (
                              <span className="text-fg-subtle">
                                · {t('cron.nextRun')}：{formatDateTime(next)}
                              </span>
                            )}
                          </div>
                          <div className="font-mono text-sm text-fg mt-1.5 break-all">
                            {job.command}
                          </div>
                        </div>
                        <div className="flex items-center gap-1 shrink-0">
                          <Button
                            variant="ghost"
                            size="icon-sm"
                            onClick={() => openEdit(i)}
                            aria-label={t('common.edit')}
                            title={t('common.edit')}
                          >
                            <Pencil size={14} />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon-sm"
                            onClick={() => setDeleteIndex(i)}
                            aria-label={t('common.delete')}
                            title={t('common.delete')}
                            className="text-danger hover:text-danger"
                          >
                            <Trash2 size={14} />
                          </Button>
                        </div>
                      </div>
                    </li>
                  )
                })}
              </ul>
            )}
          </CardBody>
        </Card>
      )}

      {/* 添加 / 编辑模态框 */}
      <Modal
        open={editing}
        onClose={() => !saving && setEditing(false)}
        title={editIndex !== null ? t('cron.editTitle') : t('cron.addTitle')}
        size="lg"
        footer={
          <>
            <Button
              variant="secondary"
              onClick={() => setEditing(false)}
              disabled={saving}
            >
              {t('common.cancel')}
            </Button>
            <Button onClick={handleSave} loading={saving}>
              {t('common.save')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          {/* 模板 */}
          <div>
            <label className="block text-xs font-medium text-fg-muted mb-1.5">
              {t('cron.templates')}
            </label>
            <div className="flex items-center gap-2 flex-wrap">
              {TEMPLATES.map((tpl) => (
                <button
                  key={tpl.key}
                  type="button"
                  onClick={() => applyTemplate(tpl)}
                  className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs bg-bg-sunken hover:bg-fg/10 text-fg-muted hover:text-fg transition-colors border border-border"
                >
                  <ChevronDown size={12} />
                  {t(`cron.template_${tpl.key}`)}
                </button>
              ))}
            </div>
          </div>

          {/* 表达式 */}
          <div>
            <label className="block text-xs font-medium text-fg-muted mb-1.5">
              {t('cron.expression')}
            </label>
            <Input
              value={form.expression}
              onChange={(e) => setForm({ ...form, expression: e.target.value })}
              placeholder={t('cron.expressionPlaceholder')}
              invalid={form.expression.length > 0 && !isValidExpression(form.expression)}
              className="font-mono"
            />
            <p className="text-xs text-fg-subtle mt-1">
              {t('cron.expressionHint')}
            </p>
            {form.expression && isValidExpression(form.expression) && (
              <NextRunPreview expression={form.expression} t={t} />
            )}
          </div>

          {/* 命令 */}
          <div>
            <label className="block text-xs font-medium text-fg-muted mb-1.5">
              {t('cron.command')}
            </label>
            <Textarea
              value={form.command}
              onChange={(e) => setForm({ ...form, command: e.target.value })}
              placeholder={t('cron.commandPlaceholder')}
              rows={3}
              invalid={form.command.length > 0 && !form.command.trim()}
            />
            <p className="text-xs text-fg-subtle mt-1 flex items-center gap-1">
              <Terminal size={12} />
              {t('cron.commandHint')}
            </p>
          </div>
        </div>
      </Modal>

      <Confirm
        open={deleteIndex !== null}
        title={t('cron.deleteTitle')}
        message={t('cron.deleteConfirm')}
        confirmText={t('common.delete')}
        variant="danger"
        loading={saving}
        onConfirm={handleConfirmDelete}
        onCancel={() => !saving && setDeleteIndex(null)}
      />
    </div>
  )
}

function NextRunPreview({
  expression,
  t,
}: {
  expression: string
  t: (key: string, params?: Record<string, string | number>) => string
}) {
  const next = useMemo(() => getNextRunTime(expression), [expression])
  if (!next) return null
  return (
    <p className="text-xs text-accent mt-1 flex items-center gap-1">
      <Clock size={12} />
      {t('cron.nextRun')}：{formatDateTime(next)}
    </p>
  )
}
