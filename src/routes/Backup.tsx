import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  HardDriveDownload,
  Plus,
  RefreshCw,
  Download,
  RotateCcw,
  Trash2,
  AlertTriangle,
  FileArchive,
  Database,
  Settings as SettingsIcon,
  ChevronRight,
} from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Spinner } from '@/components/ui/Spinner'
import { Modal, Confirm } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { backupApi } from '@/api/backup'
import { toast } from '@/components/ui/Toast'
import { useI18n } from '@/hooks/useI18n'
import { useIsMobile } from '@/hooks/useMediaQuery'
import { useFormat } from '@/lib/format'
import type { BackupCreateRequest, BackupRecord } from '@shared/types'

const DEFAULT_EXCLUDE_DIRS = 'cache,node_modules,.git,.gojs'

export default function Backup() {
  const queryClient = useQueryClient()
  const { t } = useI18n()
  const isMobile = useIsMobile()
  const { formatBytes, formatDate, formatRelativeTime } = useFormat()

  const [showCreate, setShowCreate] = useState(false)
  const [showDelete, setShowDelete] = useState<BackupRecord | null>(null)
  const [showRestore, setShowRestore] = useState<BackupRecord | null>(null)

  // 创建表单状态
  const [includeFiles, setIncludeFiles] = useState(true)
  const [includeDb, setIncludeDb] = useState(true)
  const [includeConfig, setIncludeConfig] = useState(true)
  const [excludeDirsInput, setExcludeDirsInput] = useState(DEFAULT_EXCLUDE_DIRS)

  const { data, isLoading, error, refetch, isFetching } = useQuery({
    queryKey: ['backups'],
    queryFn: () => backupApi.list(),
  })

  const createMutation = useMutation({
    mutationFn: (req: BackupCreateRequest) => backupApi.create(req),
    onSuccess: (res) => {
      toast({
        type: 'success',
        title: t('backup.createSuccess'),
        description: `${res.filename} · ${formatBytes(res.size)}`,
      })
      setShowCreate(false)
      queryClient.invalidateQueries({ queryKey: ['backups'] })
    },
    onError: (err) => {
      toast({
        type: 'error',
        title: t('backup.createFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (filename: string) => backupApi.delete(filename),
    onSuccess: () => {
      toast({ type: 'success', title: t('backup.deleted') })
      setShowDelete(null)
      queryClient.invalidateQueries({ queryKey: ['backups'] })
    },
    onError: (err) => {
      toast({
        type: 'error',
        title: t('backup.deleteFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    },
  })

  const restoreMutation = useMutation({
    mutationFn: (filename: string) => backupApi.restore(filename),
    onSuccess: (res) => {
      const parts: string[] = []
      if (res.restored_files > 0) {
        parts.push(t('backup.restoredFilesCount', { count: res.restored_files }))
      }
      if (res.restored_db > 0) {
        parts.push(t('backup.restoredDbCount', { count: res.restored_db }))
      }
      if (res.db_errors && res.db_errors.length > 0) {
        toast({
          type: 'warning',
          title: t('backup.restorePartial'),
          description: res.db_errors.join('; ').slice(0, 200),
          duration: 8000,
        })
      } else {
        toast({
          type: 'success',
          title: t('backup.restoreSuccess'),
          description: parts.length > 0 ? parts.join(' · ') : undefined,
        })
      }
      setShowRestore(null)
    },
    onError: (err) => {
      toast({
        type: 'error',
        title: t('backup.restoreFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    },
  })

  const handleCreate = () => {
    const exclude_dirs = excludeDirsInput
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean)
    createMutation.mutate({
      include_files: includeFiles,
      include_db: includeDb,
      include_config: includeConfig,
      exclude_dirs,
    })
  }

  const handleDownload = (record: BackupRecord) => {
    backupApi.download(record.filename)
    toast({ type: 'info', title: t('backup.downloadStarted') })
  }

  const backups = data?.backups ?? []

  const renderBadges = (record: BackupRecord) => {
    const meta = record.metadata
    const badges: React.ReactNode[] = []
    if (meta) {
      if (meta.files) {
        badges.push(
          <Badge key="files" variant="accent" className="gap-1">
            <FileArchive size={11} />
            {t('backup.scopeFiles')}
          </Badge>,
        )
      }
      if (meta.databases && meta.databases.length > 0) {
        badges.push(
          <Badge key="db" variant="success" className="gap-1">
            <Database size={11} />
            {t('backup.scopeDb')} · {meta.databases.length}
          </Badge>,
        )
      }
      if (meta.config) {
        badges.push(
          <Badge key="cfg" variant="muted" className="gap-1">
            <SettingsIcon size={11} />
            {t('backup.scopeConfig')}
          </Badge>,
        )
      }
    } else {
      badges.push(
        <Badge key="unknown" variant="muted">
          {t('backup.unknownScope')}
        </Badge>,
      )
    }
    return <div className="flex items-center gap-1.5 flex-wrap">{badges}</div>
  }

  const renderRow = (record: BackupRecord) => {
    return (
      <li key={record.filename} className="p-4 hover:bg-fg/5 transition-colors">
        <div className="flex items-start gap-3 flex-wrap sm:flex-nowrap">
          <div className="shrink-0 mt-0.5">
            <div className="w-9 h-9 rounded-lg bg-accent/10 text-accent flex items-center justify-center">
              <HardDriveDownload size={18} />
            </div>
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2 flex-wrap">
              <code className="text-xs font-mono text-fg break-all">{record.filename}</code>
            </div>
            <div className="mt-1.5">{renderBadges(record)}</div>
            <div className="flex items-center gap-3 mt-2 text-[11px] text-fg-muted flex-wrap">
              <time className="font-mono" title={formatDate(record.created)}>
                {formatRelativeTime(record.created)}
              </time>
              <span className="inline-flex items-center gap-1">
                <span className="inline-block w-1.5 h-1.5 rounded-full bg-fg-subtle" />
                {formatBytes(record.size)}
              </span>
              {record.metadata?.version && (
                <span className="inline-flex items-center gap-1">
                  <span className="inline-block w-1.5 h-1.5 rounded-full bg-fg-subtle" />
                  v{record.metadata.version}
                </span>
              )}
            </div>
          </div>
          <div className="flex items-center gap-1 shrink-0">
            <Button
              variant="ghost"
              size="icon-sm"
              onClick={() => handleDownload(record)}
              aria-label={t('common.download')}
              title={t('common.download')}
            >
              <Download size={15} />
            </Button>
            <Button
              variant="ghost"
              size="icon-sm"
              onClick={() => setShowRestore(record)}
              aria-label={t('backup.restore')}
              title={t('backup.restore')}
            >
              <RotateCcw size={15} />
            </Button>
            <Button
              variant="ghost"
              size="icon-sm"
              onClick={() => setShowDelete(record)}
              aria-label={t('common.delete')}
              title={t('common.delete')}
              className="text-danger hover:text-danger"
            >
              <Trash2 size={15} />
            </Button>
          </div>
        </div>
      </li>
    )
  }

  return (
    <div className="p-4 md:p-6 space-y-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <HardDriveDownload size={22} className="text-accent" />
            {t('backup.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('backup.subtitle')}</p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => refetch()}
            disabled={isLoading || isFetching}
          >
            <RefreshCw size={16} className={isFetching ? 'animate-spin' : ''} />
            {t('common.refresh')}
          </Button>
          <Button size="sm" onClick={() => setShowCreate(true)}>
            <Plus size={16} />
            {t('backup.createNow')}
          </Button>
        </div>
      </div>

      <div className="rounded-lg bg-warning/5 border border-warning/20 px-4 py-3 flex items-start gap-2.5">
        <AlertTriangle size={16} className="text-warning shrink-0 mt-0.5" />
        <p className="text-xs text-fg-muted leading-relaxed">{t('backup.notice')}</p>
      </div>

      <Card className="card-hover">
        <CardHeader>
          <div className="flex items-center justify-between w-full">
            <span className="text-sm font-medium text-fg">{t('backup.listTitle')}</span>
            {backups.length > 0 && (
              <span className="text-xs text-fg-muted">
                {t('backup.totalCount', { count: backups.length })}
              </span>
            )}
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
          ) : backups.length === 0 ? (
            <EmptyState
              icon={
                <div className="w-16 h-16 rounded-full bg-bg-sunken flex items-center justify-center text-fg-subtle mx-auto">
                  <HardDriveDownload size={28} />
                </div>
              }
              title={t('backup.empty')}
              description={t('backup.emptyHint')}
              action={{
                label: t('backup.createNow'),
                onClick: () => setShowCreate(true),
                variant: 'primary',
                icon: <Plus size={14} />,
              }}
            />
          ) : isMobile ? (
            <ul className="divide-y divide-border">{backups.map(renderRow)}</ul>
          ) : (
            <div className="max-h-[600px] overflow-auto">
              <ul className="divide-y divide-border">{backups.map(renderRow)}</ul>
            </div>
          )}
        </CardBody>
      </Card>

      {/* 创建备份模态框 */}
      <Modal
        open={showCreate}
        onClose={() => setShowCreate(false)}
        title={t('backup.createTitle')}
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowCreate(false)}>
              {t('common.cancel')}
            </Button>
            <Button
              onClick={handleCreate}
              loading={createMutation.isPending}
              disabled={!includeFiles && !includeDb && !includeConfig}
            >
              {t('backup.createConfirm')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <div>
            <p className="text-xs font-medium text-fg mb-2.5">{t('backup.scopeLabel')}</p>
            <div className="space-y-2">
              <ScopeCheckbox
                checked={includeFiles}
                onChange={setIncludeFiles}
                icon={<FileArchive size={16} />}
                title={t('backup.scopeFiles')}
                desc={t('backup.scopeFilesDesc')}
              />
              <ScopeCheckbox
                checked={includeDb}
                onChange={setIncludeDb}
                icon={<Database size={16} />}
                title={t('backup.scopeDb')}
                desc={t('backup.scopeDbDesc')}
              />
              <ScopeCheckbox
                checked={includeConfig}
                onChange={setIncludeConfig}
                icon={<SettingsIcon size={16} />}
                title={t('backup.scopeConfig')}
                desc={t('backup.scopeConfigDesc')}
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-medium text-fg mb-1.5">
              {t('backup.excludeDirsLabel')}
            </label>
            <Input
              value={excludeDirsInput}
              onChange={(e) => setExcludeDirsInput(e.target.value)}
              placeholder={DEFAULT_EXCLUDE_DIRS}
            />
            <p className="text-[11px] text-fg-subtle mt-1.5 leading-relaxed">
              {t('backup.excludeDirsHint')}
            </p>
          </div>

          {!includeFiles && !includeDb && !includeConfig && (
            <div className="flex items-start gap-2 text-xs text-danger bg-danger/5 border border-danger/20 rounded-lg px-3 py-2">
              <AlertTriangle size={14} className="shrink-0 mt-0.5" />
              <span>{t('backup.noScopeSelected')}</span>
            </div>
          )}
        </div>
      </Modal>

      {/* 删除确认 */}
      <Confirm
        open={!!showDelete}
        title={t('backup.deleteTitle')}
        message={
          <>
            {t('backup.deleteConfirm')}
            {showDelete && (
              <code className="block mt-2 text-xs bg-bg-sunken px-2 py-1 rounded font-mono break-all">
                {showDelete.filename}
              </code>
            )}
          </>
        }
        confirmText={t('common.delete')}
        variant="danger"
        loading={deleteMutation.isPending}
        onConfirm={() => {
          if (showDelete) deleteMutation.mutate(showDelete.filename)
        }}
        onCancel={() => setShowDelete(null)}
      />

      {/* 恢复确认（显示将还原的内容） */}
      <Modal
        open={!!showRestore}
        onClose={() => setShowRestore(null)}
        title={t('backup.restoreTitle')}
        size="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowRestore(null)}>
              {t('common.cancel')}
            </Button>
            <Button
              variant="danger"
              onClick={() => {
                if (showRestore) restoreMutation.mutate(showRestore.filename)
              }}
              loading={restoreMutation.isPending}
            >
              <RotateCcw size={15} />
              {t('backup.restoreConfirm')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <div className="flex items-start gap-2.5 rounded-lg bg-danger/5 border border-danger/20 px-3 py-2.5">
            <AlertTriangle size={16} className="text-danger shrink-0 mt-0.5" />
            <p className="text-xs text-fg-muted leading-relaxed">
              {t('backup.restoreWarning')}
            </p>
          </div>

          {showRestore && (
            <div className="space-y-2 text-xs">
              <div className="flex items-center gap-2">
                <span className="text-fg-subtle">{t('backup.targetBackup')}:</span>
                <code className="font-mono text-fg break-all">
                  {showRestore.filename}
                </code>
              </div>

              {showRestore.metadata && (
                <div className="rounded-lg border border-border bg-bg-sunken/50 px-3 py-2.5 space-y-1.5">
                  <p className="text-[11px] font-medium text-fg mb-1">
                    {t('backup.restoreContent')}:
                  </p>
                  {showRestore.metadata.files && (
                    <RestoreItem
                      icon={<FileArchive size={13} />}
                      label={t('backup.scopeFiles')}
                      detail={t('backup.fileCount', {
                        count: showRestore.metadata.files.count,
                      })}
                    />
                  )}
                  {showRestore.metadata.databases &&
                    showRestore.metadata.databases.length > 0 && (
                      <RestoreItem
                        icon={<Database size={13} />}
                        label={t('backup.scopeDb')}
                        detail={showRestore.metadata.databases
                          .map((d) => d.name)
                          .join(', ')}
                      />
                    )}
                  {showRestore.metadata.config && (
                    <RestoreItem
                      icon={<SettingsIcon size={13} />}
                      label={t('backup.scopeConfig')}
                      detail={t('backup.configNotRestored')}
                    />
                  )}
                </div>
              )}
            </div>
          )}
        </div>
      </Modal>
    </div>
  )
}

interface ScopeCheckboxProps {
  checked: boolean
  onChange: (v: boolean) => void
  icon: React.ReactNode
  title: string
  desc: string
}

function ScopeCheckbox({ checked, onChange, icon, title, desc }: ScopeCheckboxProps) {
  return (
    <label
      className={`
        flex items-start gap-3 px-3 py-2.5 rounded-lg border cursor-pointer transition-colors
        ${checked
          ? 'border-accent/40 bg-accent/5'
          : 'border-border hover:bg-fg/5'}
      `}
    >
      <input
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        className="mt-0.5 accent-accent"
      />
      <span className={checked ? 'text-accent' : 'text-fg-muted'}>{icon}</span>
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium text-fg">{title}</p>
        <p className="text-[11px] text-fg-subtle mt-0.5 leading-relaxed">{desc}</p>
      </div>
    </label>
  )
}

function RestoreItem({
  icon,
  label,
  detail,
}: {
  icon: React.ReactNode
  label: string
  detail: string
}) {
  return (
    <div className="flex items-center gap-2">
      <span className="text-fg-muted">{icon}</span>
      <span className="text-fg">{label}</span>
      <ChevronRight size={11} className="text-fg-subtle" />
      <span className="text-fg-muted break-all">{detail}</span>
    </div>
  )
}
