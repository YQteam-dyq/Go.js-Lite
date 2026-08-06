import { useState, useMemo, useEffect } from 'react'
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
  Cloud,
  Server,
  Shield,
  Lock,
  Pencil,
  Zap,
  Clock,
  Copy,
  Check,
  PlayCircle,
  Calendar as CalendarIcon,
  CheckCircle2,
  XCircle,
  Loader2,
  Power,
} from 'lucide-react'
import { Card, CardBody } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Spinner } from '@/components/ui/Spinner'
import { Modal, Confirm } from '@/components/ui/Modal'
import { BottomSheet } from '@/components/ui/BottomSheet'
import { EmptyState } from '@/components/ui/EmptyState'
import { backupApi, type BackupScheduleCreateInput } from '@/api/backup'
import { backupDestinationsApi, type BackupDestinationCreateInput, type BackupDestinationUpdateInput } from '@/api/backupDestinations'
import { cronApi } from '@/api/cron'
import { toast } from '@/components/ui/Toast'
import { useI18n } from '@/hooks/useI18n'
import { useIsMobile } from '@/hooks/useMediaQuery'
import { useFormat } from '@/lib/format'
import type { BackupCreateRequest, BackupRecord, BackupDestination, BackupSchedule, BackupRunRecord } from '@shared/types'

const DEFAULT_EXCLUDE_DIRS = 'cache,node_modules,.git,.gojs'

type BackupTab = 'archives' | 'destinations' | 'schedules'
type DestinationModalType = 's3' | 'ftp' | 'sftp'

export default function Backup() {
  const [activeTab, setActiveTab] = useState<BackupTab>('archives')

  return (
    <div className="p-4 md:p-6 space-y-5">
      <BackupPageHeader />

      <Card className="card-hover">
        <BackupTabs activeTab={activeTab} onTabChange={setActiveTab} />
        <CardBody className="p-0">
          {activeTab === 'archives' && <ArchivesTab />}
          {activeTab === 'destinations' && <DestinationsTab />}
          {activeTab === 'schedules' && <SchedulesTabStub />}
        </CardBody>
      </Card>
    </div>
  )
}

function BackupPageHeader() {
  const { t } = useI18n()
  return (
    <div className="flex items-start justify-between gap-4">
      <div>
        <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
          <HardDriveDownload size={22} className="text-accent" />
          {t('backup.title')}
        </h1>
        <p className="text-sm text-fg-muted mt-0.5">{t('backup.subtitle')}</p>
      </div>
    </div>
  )
}

function BackupTabs({
  activeTab,
  onTabChange,
}: {
  activeTab: BackupTab
  onTabChange: (tab: BackupTab) => void
}) {
  const { t } = useI18n()
  const tabs: Array<{ key: BackupTab; label: string; icon: React.ReactNode }> = [
    { key: 'archives', label: t('remoteBackup.tabArchives'), icon: <FileArchive size={14} /> },
    { key: 'destinations', label: t('remoteBackup.tabDestinations'), icon: <Cloud size={14} /> },
    { key: 'schedules', label: t('remoteBackup.tabSchedules'), icon: <Clock size={14} /> },
  ]
  return (
    <div className="border-b border-border px-4 md:px-6">
      <div className="flex gap-0.5 -mb-px">
        {tabs.map(({ key, label, icon }) => (
          <button
            key={key}
            onClick={() => onTabChange(key)}
            className={`
              px-4 py-3 text-xs font-medium flex items-center gap-2 border-b-2 transition-colors
              ${activeTab === key
                ? 'border-accent text-accent'
                : 'border-transparent text-fg-muted hover:text-fg hover:bg-fg/5'}
            `}
          >
            {icon}
            {label}
          </button>
        ))}
      </div>
    </div>
  )
}

function ArchivesTab() {
  const queryClient = useQueryClient()
  const { t } = useI18n()
  const isMobile = useIsMobile()
  const { formatBytes, formatDate, formatRelativeTime } = useFormat()

  const [showCreate, setShowCreate] = useState(false)
  const [showDelete, setShowDelete] = useState<BackupRecord | null>(null)
  const [showRestore, setShowRestore] = useState<BackupRecord | null>(null)
  const [showRemoteRestore, setShowRemoteRestore] = useState(false)

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
    <div>
      <div className="p-4 md:p-5 space-y-4">
        <div className="rounded-lg bg-warning/5 border border-warning/20 px-4 py-3 flex items-start gap-2.5">
          <AlertTriangle size={16} className="text-warning shrink-0 mt-0.5" />
          <p className="text-xs text-fg-muted leading-relaxed">{t('backup.notice')}</p>
        </div>

        <div className="flex items-center justify-between flex-wrap gap-3">
          <div className="flex items-center justify-between w-full">
            <span className="text-sm font-medium text-fg">{t('backup.listTitle')}</span>
            <div className="flex items-center gap-2">
              <Button variant="secondary" size="sm" onClick={() => setShowRemoteRestore(true)}>
                <Cloud size={16} />
                {t('backupRemote.browse')}
              </Button>
              <Button variant="secondary" size="sm" onClick={() => refetch()} disabled={isLoading || isFetching}>
                <RefreshCw size={16} className={isFetching ? 'animate-spin' : ''} />
                {t('common.refresh')}
              </Button>
              <Button size="sm" onClick={() => setShowCreate(true)}>
                <Plus size={16} />
                {t('backup.createNow')}
              </Button>
            </div>
          </div>
          {backups.length > 0 && (
            <span className="text-xs text-fg-muted w-full">
              {t('backup.totalCount', { count: backups.length })}
            </span>
          )}
        </div>
      </div>

      {isLoading ? (
        <div className="p-12 flex justify-center">
          <Spinner />
        </div>
      ) : error ? (
        <div className="p-8 text-center text-danger">
          <AlertTriangle size={24} className="mx-auto mb-2" />
          <p className="text-sm">{error instanceof Error ? error.message : t('common.error')}</p>
        </div>
      ) : backups.length === 0 ? (
        <div className="p-6">
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
        </div>
      ) : isMobile ? (
        <ul className="divide-y divide-border">{backups.map(renderRow)}</ul>
      ) : (
        <div className="max-h-[600px] overflow-auto border-t border-border">
          <ul className="divide-y divide-border">{backups.map(renderRow)}</ul>
        </div>
      )}

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
            <p className="text-xs text-fg-muted leading-relaxed">{t('backup.restoreWarning')}</p>
          </div>

          {showRestore && (
            <div className="space-y-2 text-xs">
              <div className="flex items-center gap-2">
                <span className="text-fg-subtle">{t('backup.targetBackup')}:</span>
                <code className="font-mono text-fg break-all">{showRestore.filename}</code>
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
                      detail={t('backup.fileCount', { count: showRestore.metadata.files.count })}
                    />
                  )}
                  {showRestore.metadata.databases && showRestore.metadata.databases.length > 0 && (
                    <RestoreItem
                      icon={<Database size={13} />}
                      label={t('backup.scopeDb')}
                      detail={showRestore.metadata.databases.map((d) => d.name).join(', ')}
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

      <RemoteRestoreModal
        open={showRemoteRestore}
        onClose={() => setShowRemoteRestore(false)}
        onRestored={() => {
          refetch()
        }}
      />
    </div>
  )
}

function RemoteRestoreModal({
  open,
  onClose,
  onRestored,
}: {
  open: boolean
  onClose: () => void
  onRestored: () => void
}) {
  const { t } = useI18n()
  const isMobile = useIsMobile()
  const { formatBytes } = useFormat()
  const queryClient = useQueryClient()

  const [destId, setDestId] = useState('')
  const [selectedKey, setSelectedKey] = useState('')

  useEffect(() => {
    if (open) {
      setDestId('')
      setSelectedKey('')
    }
  }, [open])

  const destinationsQuery = useQuery({
    queryKey: ['backup-destinations'],
    queryFn: () => backupDestinationsApi.list(),
    enabled: open,
    staleTime: 60_000,
  })
  const destinations = destinationsQuery.data?.destinations ?? []

  const browseQuery = useQuery({
    queryKey: ['backup-remote-browse', destId],
    queryFn: () => backupDestinationsApi.browse(destId),
    enabled: open && destId !== '',
  })
  const items = browseQuery.data?.items ?? []

  const downloadRestoreMutation = useMutation({
    mutationFn: async () => {
      const res = await backupDestinationsApi.download(destId, selectedKey)
      return backupApi.restore(res.filename)
    },
    onSuccess: (res) => {
      const parts: string[] = []
      if (res.restored_files > 0) {
        parts.push(t('backup.restoredFilesCount', { count: res.restored_files }))
      }
      if (res.restored_db > 0) {
        parts.push(t('backup.restoredDbCount', { count: res.restored_db }))
      }
      toast({
        type: 'success',
        title: t('backupRemote.restoreRemoteSuccess'),
        description: parts.length > 0 ? parts.join(' · ') : undefined,
      })
      queryClient.invalidateQueries({ queryKey: ['backups'] })
      onRestored()
      onClose()
    },
    onError: (err) => {
      toast({
        type: 'error',
        title: t('backupRemote.restoreRemoteFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    },
  })

  const canRestore = destId !== '' && selectedKey !== '' && !downloadRestoreMutation.isPending

  const listBody = (
    <div className="space-y-4">
      <div>
        <label className="block text-xs font-medium text-fg mb-1.5">
          {t('backupRemote.selectDestination')}
        </label>
        <select
          value={destId}
          onChange={(e) => {
            setDestId(e.target.value)
            setSelectedKey('')
          }}
          className="w-full rounded-md border border-border bg-bg-card px-3 py-2 text-xs text-fg placeholder:text-fg-subtle focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent/40"
        >
          <option value="">{t('backupRemote.selectDestinationPlaceholder')}</option>
          {destinations.map((d) => (
            <option key={d.id} value={d.id}>
              {d.name} · {d.type.toUpperCase()}
            </option>
          ))}
        </select>
        {destinationsQuery.isError && (
          <p className="text-[11px] text-danger mt-1.5">{t('backupRemote.loadDestinationsFailed')}</p>
        )}
      </div>

      {destId !== '' &&
        (browseQuery.isLoading ? (
          <div className="p-8 flex justify-center">
            <Spinner />
          </div>
        ) : browseQuery.isError ? (
          <div className="p-6 text-center text-xs text-danger">
            <AlertTriangle size={20} className="mx-auto mb-2" />
            {browseQuery.error instanceof Error
              ? browseQuery.error.message
              : t('backupRemote.loadFailed')}
          </div>
        ) : items.length === 0 ? (
          <div className="p-6 text-center text-xs text-fg-muted">{t('backupRemote.noRemoteFiles')}</div>
        ) : (
          <div className="max-h-[260px] overflow-y-auto divide-y divide-border rounded-lg border border-border">
            {items.map((item) => (
              <label
                key={item.key}
                className={`flex items-start gap-3 px-3 py-2.5 cursor-pointer transition-colors ${
                  selectedKey === item.key ? 'bg-accent/5' : 'hover:bg-fg/5'
                }`}
              >
                <input
                  type="radio"
                  name="remote-backup-select"
                  className="mt-1 accent-accent shrink-0"
                  checked={selectedKey === item.key}
                  onChange={() => setSelectedKey(item.key)}
                />
                <div className="min-w-0 flex-1">
                  <p className="text-xs font-mono text-fg break-all">{remoteItemName(item.key)}</p>
                  <p className="text-[11px] text-fg-subtle mt-0.5 flex items-center gap-3 flex-wrap">
                    <span>{formatBytes(item.size)}</span>
                    {item.modified && <span title={item.modified}>{formatRemoteModified(item.modified)}</span>}
                  </p>
                </div>
              </label>
            ))}
          </div>
        ))}
    </div>
  )

  const footer = (
    <>
      <Button variant="secondary" onClick={onClose}>
        {t('common.cancel')}
      </Button>
      <Button
        onClick={() => downloadRestoreMutation.mutate()}
        loading={downloadRestoreMutation.isPending}
        disabled={!canRestore}
      >
        <RotateCcw size={15} />
        {downloadRestoreMutation.isPending
          ? t('backupRemote.downloadRestoreInProgress')
          : t('backupRemote.downloadAndRestore')}
      </Button>
    </>
  )

  return isMobile ? (
    <BottomSheet open={open} onClose={onClose} title={t('backupRemote.browse')}>
      <div className="px-5 pb-6 flex flex-col gap-4">
        {listBody}
        <div className="flex items-center justify-end gap-2 pt-2 border-t border-border">{footer}</div>
      </div>
    </BottomSheet>
  ) : (
    <Modal open={open} onClose={onClose} title={t('backupRemote.browse')} size="md" footer={footer}>
      {listBody}
    </Modal>
  )
}

function remoteItemName(key: string) {
  const idx = key.lastIndexOf('/')
  return idx >= 0 ? key.slice(idx + 1) : key
}

function formatRemoteModified(iso: string) {
  const ts = Date.parse(iso)
  if (Number.isNaN(ts)) return iso
  return new Date(ts).toLocaleString()
}

function DestinationsTab() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const [modalOpen, setModalOpen] = useState(false)
  const [editingDest, setEditingDest] = useState<BackupDestination | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<BackupDestination | null>(null)

  const { data, isLoading, refetch, isFetching } = useQuery({
    queryKey: ['backup-destinations'],
    queryFn: () => backupDestinationsApi.list(),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string) => backupDestinationsApi.remove(id),
    onSuccess: () => {
      toast({ type: 'success', title: t('remoteBackup.deleted') })
      setDeleteTarget(null)
      queryClient.invalidateQueries({ queryKey: ['backup-destinations'] })
    },
    onError: (err) => {
      toast({
        type: 'error',
        title: t('common.deleteFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    },
  })

  const destinations = data?.destinations ?? []

  return (
    <div className="p-4 md:p-5 space-y-4">
      <div>
        <div className="flex items-center justify-between gap-3 flex-wrap">
          <div>
            <h2 className="text-sm font-semibold text-fg">{t('remoteBackup.tabDestinations')}</h2>
            <p className="text-[11px] text-fg-muted mt-1 leading-relaxed">
              {t('remoteBackup.credentialsEncryptedHint')}
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Button variant="secondary" size="sm" onClick={() => refetch()} disabled={isLoading || isFetching}>
              <RefreshCw size={15} className={isFetching ? 'animate-spin' : ''} />
              {t('common.refresh')}
            </Button>
            <Button
              size="sm"
              onClick={() => {
                setEditingDest(null)
                setModalOpen(true)
              }}
            >
              <Plus size={15} />
              {t('remoteBackup.newDestination')}
            </Button>
          </div>
        </div>
      </div>

      {isLoading ? (
        <div className="p-10 flex justify-center">
          <Spinner />
        </div>
      ) : destinations.length === 0 ? (
        <EmptyState
          icon={
            <div className="w-16 h-16 rounded-full bg-bg-sunken flex items-center justify-center text-fg-subtle mx-auto">
              <Cloud size={28} />
            </div>
          }
          title={t('remoteBackup.destinationsEmptyHint')}
          description={t('remoteBackup.destinationsEmptyDescription')}
          action={{
            label: t('remoteBackup.newDestination'),
            onClick: () => {
              setEditingDest(null)
              setModalOpen(true)
            },
            variant: 'primary',
            icon: <Plus size={14} />,
          }}
        />
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {destinations.map((dest) => (
            <DestinationCard
              key={dest.id}
              dest={dest}
              onEdit={() => {
                setEditingDest(dest)
                setModalOpen(true)
              }}
              onDelete={() => setDeleteTarget(dest)}
            />
          ))}
        </div>
      )}

      <DestinationModal
        open={modalOpen}
        editing={editingDest}
        onClose={() => {
          setModalOpen(false)
          setEditingDest(null)
        }}
      />

      <Confirm
        open={!!deleteTarget}
        title={t('remoteBackup.deleteDestination')}
        message={
          <>
            <span>{t('remoteBackup.deleteDestinationConfirm')}</span>
            {deleteTarget && (
              <code className="block mt-2 text-xs bg-bg-sunken px-2 py-1 rounded font-mono">
                {deleteTarget.name}
              </code>
            )}
          </>
        }
        confirmText={t('common.delete')}
        variant="danger"
        loading={deleteMutation.isPending}
        onConfirm={() => {
          if (deleteTarget) deleteMutation.mutate(deleteTarget.id)
        }}
        onCancel={() => setDeleteTarget(null)}
      />
    </div>
  )
}

function DestinationCard({
  dest,
  onEdit,
  onDelete,
}: {
  dest: BackupDestination
  onEdit: () => void
  onDelete: () => void
}) {
  const { t } = useI18n()
  const { formatRelativeTime } = useFormat()
  const [testing, setTesting] = useState(false)

  const typeMeta = getDestinationMeta(dest.type)

  const summary = getDestinationSummary(dest)
  const lastTestOk = (dest as BackupDestination & { last_test_ok?: boolean | null }).last_test_ok
  const lastTestAt = (dest as BackupDestination & { last_test_at?: number | null }).last_test_at

  const testMutation = useMutation({
    mutationFn: async () => {
      setTesting(true)
      try {
        const payload = buildTestPayloadFromDest(dest)
        return await backupDestinationsApi.test({ ...payload, id: dest.id })
      } finally {
        setTesting(false)
      }
    },
    onSuccess: (res) => {
      if (res.ok) {
        toast({ type: 'success', title: t('remoteBackup.testSuccess'), description: t('remoteBackup.testSuccessDetail') })
      } else {
        toast({ type: 'error', title: t('remoteBackup.testFailed'), description: res.error || t('remoteBackup.testFailedDetail') })
      }
    },
    onError: (err) => {
      toast({
        type: 'error',
        title: t('remoteBackup.testFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    },
  })

  return (
    <div className="rounded-xl border border-border bg-bg-card hover:border-accent/30 hover:shadow-sm transition-all p-4 flex flex-col gap-3">
      <div className="flex items-start gap-3">
        <div
          className={`w-10 h-10 rounded-lg flex items-center justify-center shrink-0 ${typeMeta.bgClass}`}
        >
          {typeMeta.icon}
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2 flex-wrap">
            <h3 className="text-sm font-semibold text-fg truncate">{dest.name}</h3>
            <Badge variant="muted" className="text-[10px] gap-1">
              {typeMeta.badgeIcon}
              {typeMeta.label}
            </Badge>
          </div>
          <p className="text-[11px] text-fg-muted mt-1 truncate" title={summary}>
            {summary || '\u00A0'}
          </p>
        </div>
      </div>

      <div className="flex items-center gap-2 flex-wrap">
        {lastTestAt ? (
          <Badge variant={lastTestOk ? 'success' : 'danger'} className="gap-1 text-[10px]">
            <Zap size={10} />
            {lastTestOk ? t('remoteBackup.statusOk') : t('remoteBackup.statusFailed')}
            <span className="text-fg-muted font-normal">
              · {formatRelativeTime(lastTestAt)}
            </span>
          </Badge>
        ) : (
          <Badge variant="muted" className="text-[10px]">
            {t('remoteBackup.testNotRun')}
          </Badge>
        )}
      </div>

      <div className="flex items-center gap-1.5 pt-1 border-t border-border mt-auto">
        <Button
          variant="secondary"
          size="sm"
          className="flex-1"
          onClick={() => testMutation.mutate()}
          loading={testing || testMutation.isPending}
          disabled={testing || testMutation.isPending}
        >
          <Zap size={13} />
          {t('remoteBackup.testConnection')}
        </Button>
        <Button
          variant="ghost"
          size="icon-sm"
          onClick={onEdit}
          title={t('common.edit')}
          aria-label={t('common.edit')}
        >
          <Pencil size={14} />
        </Button>
        <Button
          variant="ghost"
          size="icon-sm"
          onClick={onDelete}
          title={t('common.delete')}
          aria-label={t('common.delete')}
          className="text-danger hover:text-danger"
        >
          <Trash2 size={14} />
        </Button>
      </div>
    </div>
  )
}

function getDestinationMeta(type: BackupDestination['type']) {
  switch (type) {
    case 's3':
      return {
        label: 'S3',
        icon: <Cloud size={18} className="text-sky-600" />,
        badgeIcon: <Cloud size={10} />,
        bgClass: 'bg-sky-500/10',
      }
    case 'ftp':
      return {
        label: 'FTP',
        icon: <Server size={18} className="text-amber-600" />,
        badgeIcon: <Server size={10} />,
        bgClass: 'bg-amber-500/10',
      }
    case 'sftp':
      return {
        label: 'SFTP',
        icon: (
          <div className="relative">
            <Server size={18} className="text-emerald-600" />
            <Lock size={9} className="text-emerald-700 absolute -right-1 -bottom-1 bg-bg-card rounded-sm" />
          </div>
        ),
        badgeIcon: <Shield size={10} />,
        bgClass: 'bg-emerald-500/10',
      }
  }
}

function getDestinationSummary(dest: BackupDestination): string {
  switch (dest.type) {
    case 's3': {
      const parts: string[] = []
      if (dest.bucket) parts.push(dest.bucket)
      if (dest.path_prefix) parts.push('/' + dest.path_prefix.replace(/^\/+/, ''))
      return parts.join('') || dest.endpoint || 'S3'
    }
    case 'ftp': {
      const host = dest.host || ''
      const prefix = dest.path_prefix ? '/' + dest.path_prefix.replace(/^\/+/, '') : ''
      return `${dest.username}@${host}${dest.port !== 21 ? ':' + dest.port : ''}${prefix}`
    }
    case 'sftp': {
      const host = dest.host || ''
      const prefix = dest.path_prefix ? '/' + dest.path_prefix.replace(/^\/+/, '') : ''
      return `${dest.username}@${host}${dest.port !== 22 ? ':' + dest.port : ''}${prefix}`
    }
  }
}

function buildTestPayloadFromDest(dest: BackupDestination): BackupDestinationCreateInput {
  const common = {
    name: dest.name,
    path_prefix: dest.path_prefix || '',
  }
  switch (dest.type) {
    case 's3':
      return {
        type: 's3',
        ...common,
        access_key: '****',
        secret_key: '****',
        endpoint: dest.endpoint,
        region: dest.region,
        bucket: dest.bucket,
        sse: dest.sse,
      }
    case 'ftp':
      return {
        type: 'ftp',
        ...common,
        host: dest.host,
        port: dest.port,
        username: dest.username,
        password: '****',
        use_tls: dest.use_tls,
      }
    case 'sftp':
      return {
        type: 'sftp',
        ...common,
        host: dest.host,
        port: dest.port,
        username: dest.username,
        password: dest.password_enc ? '****' : '',
        private_key: dest.private_key_enc ? '****' : '',
      }
  }
}

function DestinationModal({
  open,
  editing,
  onClose,
}: {
  open: boolean
  editing: BackupDestination | null
  onClose: () => void
}) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const isEdit = !!editing

  const [typeTab, setTypeTab] = useState<DestinationModalType>(isEdit ? editing.type : 's3')

  const [s3Form, setS3Form] = useState({
    name: '',
    access_key: '',
    secret_key: '',
    endpoint: 's3.amazonaws.com',
    region: 'us-east-1',
    bucket: '',
    path_prefix: '',
    sse: false,
  })

  const [ftpForm, setFtpForm] = useState({
    name: '',
    host: '',
    port: 21,
    username: '',
    password: '',
    path_prefix: '',
    use_tls: false,
  })

  const [sftpForm, setSftpForm] = useState({
    name: '',
    host: '',
    port: 22,
    username: '',
    password: '',
    private_key: '',
    path_prefix: '',
  })

  const [saveAnyway, setSaveAnyway] = useState(false)
  const [testResult, setTestResult] = useState<{ ok: boolean; message?: string } | null>(null)
  const [testing, setTesting] = useState(false)

  const resetForms = () => {
    if (isEdit && editing) {
      setTypeTab(editing.type)
      if (editing.type === 's3') {
        setS3Form({
          name: editing.name,
          access_key: editing.access_key_enc ? '****' : '',
          secret_key: editing.secret_key_enc ? '****' : '',
          endpoint: editing.endpoint,
          region: editing.region || 'us-east-1',
          bucket: editing.bucket,
          path_prefix: editing.path_prefix || '',
          sse: !!editing.sse,
        })
      } else if (editing.type === 'ftp') {
        setFtpForm({
          name: editing.name,
          host: editing.host,
          port: editing.port,
          username: editing.username,
          password: editing.password_enc ? '****' : '',
          path_prefix: editing.path_prefix || '',
          use_tls: !!editing.use_tls,
        })
      } else if (editing.type === 'sftp') {
        setSftpForm({
          name: editing.name,
          host: editing.host,
          port: editing.port,
          username: editing.username,
          password: editing.password_enc ? '****' : '',
          private_key: editing.private_key_enc ? '****' : '',
          path_prefix: editing.path_prefix || '',
        })
      }
    } else {
      setS3Form({ name: '', access_key: '', secret_key: '', endpoint: 's3.amazonaws.com', region: 'us-east-1', bucket: '', path_prefix: '', sse: false })
      setFtpForm({ name: '', host: '', port: 21, username: '', password: '', path_prefix: '', use_tls: false })
      setSftpForm({ name: '', host: '', port: 22, username: '', password: '', private_key: '', path_prefix: '' })
    }
    setSaveAnyway(false)
    setTestResult(null)
    setTesting(false)
  }

  useState(() => {
    if (open) resetForms()
  })

  if (open && !testing && !testResult) {
  }

  const collectPayload = (): BackupDestinationCreateInput | null => {
    if (typeTab === 's3') {
      const f = s3Form
      if (!f.name || !f.bucket) return null
      if (!isEdit && (!f.access_key || !f.secret_key)) return null
      return { type: 's3', ...f }
    }
    if (typeTab === 'ftp') {
      const f = ftpForm
      if (!f.name || !f.host || !f.username) return null
      return { type: 'ftp', ...f }
    }
    if (typeTab === 'sftp') {
      const f = sftpForm
      if (!f.name || !f.host || !f.username) return null
      return { type: 'sftp', ...f }
    }
    return null
  }

  const testMutation = useMutation({
    mutationFn: async () => {
      const payload = collectPayload()
      if (!payload) throw new Error(t('common.requiredFields'))
      setTesting(true)
      setTestResult(null)
      try {
        const res = await backupDestinationsApi.test(
          isEdit && editing ? { ...payload, id: editing.id } : payload,
        )
        setTestResult({ ok: res.ok, message: res.error })
        if (res.ok) {
          toast({ type: 'success', title: t('remoteBackup.testSuccess'), description: t('remoteBackup.testSuccessDetail') })
        } else {
          toast({ type: 'error', title: t('remoteBackup.testFailed'), description: res.error || t('remoteBackup.testFailedDetail') })
        }
        return res
      } finally {
        setTesting(false)
      }
    },
    onError: (err) => {
      setTestResult({ ok: false, message: err instanceof Error ? err.message : t('common.unknownError') })
      toast({
        type: 'error',
        title: t('remoteBackup.testFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    },
  })

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = collectPayload()
      if (!payload) throw new Error(t('common.requiredFields'))
      if (isEdit && editing) {
        return await backupDestinationsApi.update(editing.id, payload as unknown as BackupDestinationUpdateInput)
      }
      return await backupDestinationsApi.create(payload)
    },
    onSuccess: () => {
      toast({ type: 'success', title: isEdit ? t('remoteBackup.updated') : t('remoteBackup.created') })
      queryClient.invalidateQueries({ queryKey: ['backup-destinations'] })
      onClose()
    },
    onError: (err) => {
      toast({
        type: 'error',
        title: t('common.saveFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    },
  })

  const canTest = !!collectPayload()
  const canSave = canTest && (saveAnyway || (testResult && testResult.ok))

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="lg"
      title={isEdit ? t('remoteBackup.editDestination') : t('remoteBackup.newDestination')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button
            variant="secondary"
            onClick={() => testMutation.mutate()}
            loading={testing || testMutation.isPending}
            disabled={!canTest || testing || testMutation.isPending}
          >
            <Zap size={14} />
            {t('remoteBackup.testConnection')}
          </Button>
          <Button
            onClick={() => saveMutation.mutate()}
            loading={saveMutation.isPending}
            disabled={!canSave || saveMutation.isPending}
          >
            {t('common.save')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="flex gap-1 p-1 bg-bg-sunken rounded-lg">
          {(
            [
              { key: 's3', label: t('remoteBackup.destinationTypeS3'), icon: <Cloud size={13} /> },
              { key: 'ftp', label: t('remoteBackup.destinationTypeFtp'), icon: <Server size={13} /> },
              { key: 'sftp', label: t('remoteBackup.destinationTypeSftp'), icon: <Shield size={13} /> },
            ] as Array<{ key: DestinationModalType; label: string; icon: React.ReactNode }>
          ).map(({ key, label, icon }) => (
            <button
              key={key}
              onClick={() => {
                if (!isEdit) {
                  setTypeTab(key)
                  setTestResult(null)
                }
              }}
              disabled={isEdit}
              className={`
                flex-1 flex items-center justify-center gap-2 px-3 py-2 rounded-md text-xs font-medium transition-all
                ${typeTab === key
                  ? 'bg-bg-card text-fg shadow-sm'
                  : 'text-fg-muted hover:text-fg disabled:opacity-50 disabled:cursor-not-allowed'}
              `}
            >
              {icon}
              <span className="hidden sm:inline">{label}</span>
              <span className="sm:hidden font-semibold uppercase">{key}</span>
            </button>
          ))}
        </div>

        {typeTab === 's3' && (
          <div className="space-y-3">
            <FormField label="Name" required>
              <Input
                value={s3Form.name}
                onChange={(e) => setS3Form({ ...s3Form, name: e.target.value })}
                placeholder="My S3 Backup"
              />
            </FormField>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <FormField label={t('remoteBackup.s3AccessKeyId')} required>
                <Input
                  value={s3Form.access_key}
                  onChange={(e) => setS3Form({ ...s3Form, access_key: e.target.value })}
                  placeholder="AKIA..."
                />
              </FormField>
              <FormField label={t('remoteBackup.s3SecretKey')} required>
                <Input
                  type="password"
                  value={s3Form.secret_key}
                  onChange={(e) => setS3Form({ ...s3Form, secret_key: e.target.value })}
                  placeholder="••••••••••••••••"
                />
              </FormField>
            </div>
            <FormField label={t('remoteBackup.s3Endpoint')}>
              <Input
                value={s3Form.endpoint}
                onChange={(e) => setS3Form({ ...s3Form, endpoint: e.target.value })}
                placeholder="s3.amazonaws.com"
              />
              <p className="text-[10px] text-fg-subtle mt-1 leading-relaxed">{t('remoteBackup.s3EndpointHint')}</p>
            </FormField>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <FormField label={t('remoteBackup.s3Region')}>
                <Input
                  value={s3Form.region}
                  onChange={(e) => setS3Form({ ...s3Form, region: e.target.value })}
                  placeholder="us-east-1"
                />
              </FormField>
              <FormField label={t('remoteBackup.s3Bucket')} required>
                <Input
                  value={s3Form.bucket}
                  onChange={(e) => setS3Form({ ...s3Form, bucket: e.target.value })}
                  placeholder="my-backup-bucket"
                />
              </FormField>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <FormField label={t('remoteBackup.s3PathPrefix')}>
                <Input
                  value={s3Form.path_prefix}
                  onChange={(e) => setS3Form({ ...s3Form, path_prefix: e.target.value })}
                  placeholder="backups/gojs"
                />
              </FormField>
              <div className="flex items-end pb-2">
                <label className="inline-flex items-center gap-2 cursor-pointer select-none">
                  <input
                    type="checkbox"
                    checked={s3Form.sse}
                    onChange={(e) => setS3Form({ ...s3Form, sse: e.target.checked })}
                    className="accent-accent"
                  />
                  <span className="text-xs font-medium text-fg">{t('remoteBackup.s3Sse')}</span>
                </label>
              </div>
            </div>
          </div>
        )}

        {typeTab === 'ftp' && (
          <div className="space-y-3">
            <FormField label="Name" required>
              <Input
                value={ftpForm.name}
                onChange={(e) => setFtpForm({ ...ftpForm, name: e.target.value })}
                placeholder="My FTP Server"
              />
            </FormField>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <FormField label={t('remoteBackup.ftpHost')} required className="sm:col-span-2">
                <Input
                  value={ftpForm.host}
                  onChange={(e) => setFtpForm({ ...ftpForm, host: e.target.value })}
                  placeholder="ftp.example.com"
                />
              </FormField>
              <FormField label={t('remoteBackup.ftpPort')}>
                <Input
                  type="number"
                  value={ftpForm.port}
                  onChange={(e) => setFtpForm({ ...ftpForm, port: Number(e.target.value) || 21 })}
                />
              </FormField>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <FormField label={t('remoteBackup.ftpUser')} required>
                <Input
                  value={ftpForm.username}
                  onChange={(e) => setFtpForm({ ...ftpForm, username: e.target.value })}
                  placeholder="ftpuser"
                />
              </FormField>
              <FormField label={t('remoteBackup.ftpPass')}>
                <Input
                  type="password"
                  value={ftpForm.password}
                  onChange={(e) => setFtpForm({ ...ftpForm, password: e.target.value })}
                  placeholder="••••••••"
                />
              </FormField>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <FormField label={t('remoteBackup.ftpRemotePath')}>
                <Input
                  value={ftpForm.path_prefix}
                  onChange={(e) => setFtpForm({ ...ftpForm, path_prefix: e.target.value })}
                  placeholder="/backups/gojs"
                />
              </FormField>
              <div className="flex items-end pb-2">
                <label className="inline-flex items-center gap-2 cursor-pointer select-none">
                  <input
                    type="checkbox"
                    checked={ftpForm.use_tls}
                    onChange={(e) => setFtpForm({ ...ftpForm, use_tls: e.target.checked })}
                    className="accent-accent"
                  />
                  <span className="text-xs font-medium text-fg">{t('remoteBackup.ftpUseTls')}</span>
                </label>
              </div>
            </div>
          </div>
        )}

        {typeTab === 'sftp' && (
          <div className="space-y-3">
            <FormField label="Name" required>
              <Input
                value={sftpForm.name}
                onChange={(e) => setSftpForm({ ...sftpForm, name: e.target.value })}
                placeholder="My SFTP Server"
              />
            </FormField>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <FormField label={t('remoteBackup.sftpHost')} required className="sm:col-span-2">
                <Input
                  value={sftpForm.host}
                  onChange={(e) => setSftpForm({ ...sftpForm, host: e.target.value })}
                  placeholder="sftp.example.com"
                />
              </FormField>
              <FormField label={t('remoteBackup.sftpPort')}>
                <Input
                  type="number"
                  value={sftpForm.port}
                  onChange={(e) => setSftpForm({ ...sftpForm, port: Number(e.target.value) || 22 })}
                />
              </FormField>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <FormField label={t('remoteBackup.sftpUser')} required>
                <Input
                  value={sftpForm.username}
                  onChange={(e) => setSftpForm({ ...sftpForm, username: e.target.value })}
                  placeholder="root"
                />
              </FormField>
              <FormField label={t('remoteBackup.sftpPass')}>
                <Input
                  type="password"
                  value={sftpForm.password}
                  onChange={(e) => setSftpForm({ ...sftpForm, password: e.target.value })}
                  placeholder="••••••••"
                />
              </FormField>
            </div>
            <FormField label={t('remoteBackup.sftpPrivateKey')}>
              <textarea
                value={sftpForm.private_key}
                onChange={(e) => setSftpForm({ ...sftpForm, private_key: e.target.value })}
                rows={5}
                placeholder="-----BEGIN RSA PRIVATE KEY-----\n...\n-----END RSA PRIVATE KEY-----"
                className="w-full rounded-md border border-border bg-bg-card px-3 py-2 text-xs font-mono text-fg placeholder:text-fg-subtle focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent/40"
              />
              <p className="text-[10px] text-fg-subtle mt-1 leading-relaxed">{t('remoteBackup.sftpPrivateKeyHint')}</p>
            </FormField>
            <FormField label={t('remoteBackup.sftpRemotePath')}>
              <Input
                value={sftpForm.path_prefix}
                onChange={(e) => setSftpForm({ ...sftpForm, path_prefix: e.target.value })}
                placeholder="/var/backups/gojs"
              />
            </FormField>
          </div>
        )}

        {testResult && (
          <div
            className={`rounded-lg border px-3 py-2.5 flex items-start gap-2 text-xs ${
              testResult.ok
                ? 'bg-success/5 border-success/20 text-success'
                : 'bg-danger/5 border-danger/20 text-danger'
            }`}
          >
            {testResult.ok ? (
              <Badge variant="success" className="gap-1 shrink-0">
                <Zap size={10} />
                {t('remoteBackup.statusOk')}
              </Badge>
            ) : (
              <Badge variant="danger" className="gap-1 shrink-0">
                <AlertTriangle size={10} />
                {t('remoteBackup.statusFailed')}
              </Badge>
            )}
            <span className="text-fg-muted leading-relaxed break-all">
              {testResult.ok
                ? t('remoteBackup.testSuccessDetail')
                : testResult.message || t('remoteBackup.testFailedDetail')}
            </span>
          </div>
        )}

        <div className="flex items-start gap-2 rounded-lg border border-border bg-bg-sunken/50 px-3 py-2.5">
          <input
            type="checkbox"
            checked={saveAnyway}
            onChange={(e) => setSaveAnyway(e.target.checked)}
            className="mt-0.5 accent-accent"
          />
          <div>
            <label className="text-xs font-medium text-fg cursor-pointer select-none">
              {t('remoteBackup.saveAnyway')}
            </label>
            <p className="text-[10px] text-fg-subtle mt-0.5 leading-relaxed">
              {t('remoteBackup.saveAnywayHint')}
            </p>
          </div>
        </div>
      </div>
    </Modal>
  )
}

function FormField({
  label,
  children,
  required,
  className = '',
}: {
  label: string
  children: React.ReactNode
  required?: boolean
  className?: string
}) {
  return (
    <div className={className}>
      <label className="block text-[11px] font-medium text-fg mb-1.5">
        {label}
        {required && <span className="text-danger ml-0.5">*</span>}
      </label>
      {children}
    </div>
  )
}

type ScheduleModalTab = 'general' | 'schedule' | 'source' | 'destinations' | 'retention'
const SCHEDULE_MODAL_TABS: ScheduleModalTab[] = ['general', 'schedule', 'source', 'destinations', 'retention']

const CRON_PRESETS: Array<{ key: string; label_key: string; expr: string }> = [
  { key: 'daily', label_key: 'cronPresetDaily', expr: '0 2 * * *' },
  { key: 'weekly', label_key: 'cronPresetWeekly', expr: '0 0 * * 0' },
  { key: 'monthly', label_key: 'cronPresetMonthly', expr: '0 0 1 * *' },
  { key: 'every6h', label_key: 'cronPresetEvery6h', expr: '0 */6 * * *' },
  { key: 'custom', label_key: 'cronPresetCustom', expr: '' },
]

function SchedulesTabStub() {
  return <SchedulesTab />
}

function SchedulesTab() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const [showModal, setShowModal] = useState(false)
  const [editingSchedule, setEditingSchedule] = useState<BackupSchedule | null>(null)
  const [runImmediately, setRunImmediately] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState<BackupSchedule | null>(null)
  const [showRegenTokenConfirm, setShowRegenTokenConfirm] = useState(false)
  const [copied, setCopied] = useState(false)

  const schedulesQuery = useQuery({
    queryKey: ['backup-schedules'],
    queryFn: () => backupApi.listSchedules(),
    staleTime: 30_000,
  })

  const destinationsQuery = useQuery({
    queryKey: ['backup-destinations'],
    queryFn: () => backupDestinationsApi.list(),
    staleTime: 60_000,
  })

  const runsQuery = useQuery({
    queryKey: ['backup-runs-recent'],
    queryFn: () => backupApi.listRuns({ limit: 10, offset: 0 }),
    staleTime: 60_000,
    refetchInterval: (query) => {
      const data = query.state.data
      const hasRunning = (data?.runs ?? []).some((r: BackupRunRecord) => r.status === 'running')
      return hasRunning ? 10_000 : false
    },
  })

  const configQuery = useQuery({
    queryKey: ['internal-cron-config'],
    queryFn: async () => {
      try {
        const r = await queryClient.fetchQuery({
          queryKey: ['bootstrap'],
          queryFn: async () => {
            const resp = await fetch('/api/bootstrap')
            return await resp.json()
          },
          staleTime: 60_000,
        })
        return { internal_cron_token: (r as any).data?.config?.internal_cron_token ?? '' }
      } catch {
        return { internal_cron_token: '' }
      }
    },
    staleTime: 60_000,
  })

  const webcronUrl = useMemo(() => {
    const token = configQuery.data?.internal_cron_token ?? ''
    const host = typeof window !== 'undefined' ? window.location.host : ''
    const proto = typeof window !== 'undefined' ? window.location.protocol : 'https:'
    const base = (import.meta.env.BASE_URL || '/').replace(/\/$/, '')
    const suffix = base ? `${base}/webcron.php` : '/webcron.php'
    return token ? `${proto}//${host}${suffix}?token=${encodeURIComponent(token)}` : ''
  }, [configQuery.data])

  const handleCopyUrl = async () => {
    if (!webcronUrl) return
    try {
      await navigator.clipboard.writeText(webcronUrl)
      setCopied(true)
      setTimeout(() => setCopied(false), 1500)
      toast({ type: 'success', title: t('common.copied') ?? 'Copied' })
    } catch {
      toast({ type: 'error', title: t('common.unknownError') })
    }
  }

  const regenTokenMutation = useMutation({
    mutationFn: () => cronApi.regenerateInternalCronToken(),
    onSuccess: (res) => {
      toast({ type: 'success', title: t('remoteBackup.regenerateToken') })
      queryClient.invalidateQueries({ queryKey: ['internal-cron-config'] })
      queryClient.setQueryData(['internal-cron-config'], { internal_cron_token: res.token })
      setShowRegenTokenConfirm(false)
    },
    onError: (err) => {
      toast({
        type: 'error',
        title: t('common.saveFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    },
  })

  const createMutation = useMutation({
    mutationFn: (data: BackupScheduleCreateInput) => backupApi.createSchedule(data),
    onSuccess: async (res) => {
      toast({ type: 'success', title: t('remoteBackup.created') })
      queryClient.invalidateQueries({ queryKey: ['backup-schedules'] })
      setShowModal(false)
      if (runImmediately && res.schedule) {
        await backupApi.runScheduleNow(res.schedule.id)
        queryClient.invalidateQueries({ queryKey: ['backup-runs-recent'] })
      }
    },
    onError: (err) => {
      toast({
        type: 'error',
        title: t('common.saveFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    },
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: BackupScheduleCreateInput }) =>
      backupApi.updateSchedule(id, data),
    onSuccess: async (res) => {
      toast({ type: 'success', title: t('remoteBackup.updated') })
      queryClient.invalidateQueries({ queryKey: ['backup-schedules'] })
      setShowModal(false)
      setEditingSchedule(null)
      if (runImmediately && res.schedule) {
        await backupApi.runScheduleNow(res.schedule.id)
        queryClient.invalidateQueries({ queryKey: ['backup-runs-recent'] })
      }
    },
    onError: (err) => {
      toast({
        type: 'error',
        title: t('common.saveFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string) => backupApi.deleteSchedule(id),
    onSuccess: () => {
      toast({ type: 'success', title: t('remoteBackup.deleted') })
      queryClient.invalidateQueries({ queryKey: ['backup-schedules'] })
      setDeleteTarget(null)
    },
    onError: (err) => {
      toast({
        type: 'error',
        title: t('common.deleteFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    },
  })

  const runNowMutation = useMutation({
    mutationFn: (id: string) => backupApi.runScheduleNow(id),
    onSuccess: () => {
      toast({ type: 'success', title: t('remoteBackup.runNow') })
      queryClient.invalidateQueries({ queryKey: ['backup-runs-recent'] })
      queryClient.invalidateQueries({ queryKey: ['backup-schedules'] })
    },
    onError: (err) => {
      toast({
        type: 'error',
        title: t('common.unknownError'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    },
  })

  const schedules = schedulesQuery.data?.schedules ?? []
  const destinations = destinationsQuery.data?.destinations ?? []
  const runs = runsQuery.data?.runs ?? []

  const scheduleMap = useMemo(() => {
    const m = new Map<string, BackupSchedule>()
    for (const s of schedules) m.set(s.id, s)
    return m
  }, [schedules])

  const openNew = () => {
    setEditingSchedule(null)
    setRunImmediately(false)
    setShowModal(true)
  }
  const openEdit = (s: BackupSchedule) => {
    setEditingSchedule(s)
    setRunImmediately(false)
    setShowModal(true)
  }

  return (
    <div className="p-4 md:p-5 space-y-5">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h2 className="text-sm font-semibold text-fg">{t('remoteBackup.tabSchedules')}</h2>
          <p className="text-[11px] text-fg-muted mt-0.5 leading-relaxed">
            {t('remoteBackup.schedulesHintCard')}
          </p>
        </div>
        <Button size="sm" onClick={openNew}>
          <Plus size={15} />
          {t('remoteBackup.createSchedule')}
        </Button>
      </div>

      <div className="rounded-xl border border-border bg-bg-sunken/30 px-4 py-3 space-y-2.5">
        <div className="flex items-center justify-between gap-3 flex-wrap">
          <div className="flex items-center gap-2 min-w-0">
            <Clock size={14} className="text-accent shrink-0" />
            <span className="text-[11px] font-medium text-fg shrink-0">
              {t('remoteBackup.webcronUrl')}
            </span>
          </div>
          <Button
            variant="secondary"
            size="sm"
            onClick={() => setShowRegenTokenConfirm(true)}
            loading={regenTokenMutation.isPending}
          >
            <RefreshCw size={13} className={regenTokenMutation.isPending ? 'animate-spin' : ''} />
            {t('remoteBackup.regenerateToken')}
          </Button>
        </div>
        <div className="flex items-center gap-2">
          <Input
            readOnly
            value={webcronUrl}
            className="text-[11px] font-mono !py-1.5 bg-bg-card"
            placeholder="..."
          />
          <Button
            variant="secondary"
            size="icon-sm"
            onClick={handleCopyUrl}
            disabled={!webcronUrl}
            aria-label="Copy"
            title="Copy"
          >
            {copied ? <Check size={14} className="text-success" /> : <Copy size={14} />}
          </Button>
        </div>
      </div>

      {schedulesQuery.isLoading ? (
        <div className="p-10 flex justify-center">
          <Spinner />
        </div>
      ) : schedules.length === 0 ? (
        <EmptyState
          icon={
            <div className="w-16 h-16 rounded-full bg-bg-sunken flex items-center justify-center text-fg-subtle mx-auto">
              <Clock size={28} />
            </div>
          }
          title={t('remoteBackup.scheduleEmptyHint')}
          description={t('remoteBackup.scheduleEmptyDesc')}
          action={{
            label: t('remoteBackup.createSchedule'),
            onClick: openNew,
            variant: 'primary',
            icon: <Plus size={14} />,
          }}
        />
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {schedules.map((s) => (
            <ScheduleCard
              key={s.id}
              schedule={s}
              destinations={destinations.filter((d) => s.destination_ids.includes(d.id))}
              onRunNow={() => runNowMutation.mutate(s.id)}
              runNowLoading={runNowMutation.isPending}
              onEdit={() => openEdit(s)}
              onDelete={() => setDeleteTarget(s)}
            />
          ))}
        </div>
      )}

      <div className="space-y-3 pt-2">
        <div className="flex items-center justify-between flex-wrap gap-2">
          <h3 className="text-sm font-semibold text-fg flex items-center gap-2">
            <CalendarIcon size={15} className="text-accent" />
            {t('remoteBackup.recentRuns')}
          </h3>
          <Button
            variant="secondary"
            size="sm"
            onClick={() => queryClient.invalidateQueries({ queryKey: ['backup-runs-recent'] })}
            disabled={runsQuery.isLoading || runsQuery.isFetching}
          >
            <RefreshCw size={13} className={runsQuery.isFetching ? 'animate-spin' : ''} />
            {t('common.refresh')}
          </Button>
        </div>

        <div className="rounded-xl border border-border overflow-hidden bg-bg-card">
          <div className="overflow-x-auto max-h-[480px] overflow-y-auto">
            <table className="w-full text-[11px]">
              <thead className="bg-bg-sunken sticky top-0 z-10">
                <tr>
                  <th className="text-left px-3 py-2.5 font-medium text-fg-muted whitespace-nowrap">
                    {t('remoteBackup.colsSchedule')}
                  </th>
                  <th className="text-left px-3 py-2.5 font-medium text-fg-muted whitespace-nowrap">
                    {t('remoteBackup.colsStartedAt')}
                  </th>
                  <th className="text-left px-3 py-2.5 font-medium text-fg-muted whitespace-nowrap">
                    {t('remoteBackup.colsDuration')}
                  </th>
                  <th className="text-left px-3 py-2.5 font-medium text-fg-muted whitespace-nowrap">
                    {t('remoteBackup.colsStatus')}
                  </th>
                  <th className="text-right px-3 py-2.5 font-medium text-fg-muted whitespace-nowrap">
                    {t('remoteBackup.colsBytes')}
                  </th>
                  <th className="text-right px-3 py-2.5 font-medium text-fg-muted whitespace-nowrap">
                    {t('remoteBackup.colsDests')}
                  </th>
                  <th className="text-right px-3 py-2.5 font-medium text-fg-muted whitespace-nowrap">
                    {t('remoteBackup.colsPruned')}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {runsQuery.isLoading ? (
                  <tr>
                    <td colSpan={7} className="p-8 text-center">
                      <Spinner />
                    </td>
                  </tr>
                ) : runs.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="p-6 text-center text-[11px] text-fg-muted">
                      {t('remoteBackup.scheduleEmptyHint')}
                    </td>
                  </tr>
                ) : (
                  runs.map((r) => (
                    <RunRow
                      key={r.id}
                      run={r}
                      schedule={scheduleMap.get(r.schedule_id) ?? null}
                    />
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <ScheduleModal
        open={showModal}
        editing={editingSchedule}
        destinations={destinations}
        runImmediately={runImmediately}
        onRunImmediatelyChange={setRunImmediately}
        onClose={() => {
          setShowModal(false)
          setEditingSchedule(null)
        }}
        onSave={(payload) => {
          if (editingSchedule) {
            updateMutation.mutate({ id: editingSchedule.id, data: payload })
          } else {
            createMutation.mutate(payload)
          }
        }}
        saving={createMutation.isPending || updateMutation.isPending}
        isEdit={!!editingSchedule}
      />

      <Confirm
        open={!!deleteTarget}
        title={t('remoteBackup.deleteDestination')}
        message={
          <>
            <span>{t('remoteBackup.deleteDestinationConfirm')}</span>
            {deleteTarget && (
              <code className="block mt-2 text-xs bg-bg-sunken px-2 py-1 rounded font-mono">
                {deleteTarget.name}
              </code>
            )}
          </>
        }
        confirmText={t('common.delete')}
        variant="danger"
        loading={deleteMutation.isPending}
        onConfirm={() => {
          if (deleteTarget) deleteMutation.mutate(deleteTarget.id)
        }}
        onCancel={() => setDeleteTarget(null)}
      />

      <Confirm
        open={showRegenTokenConfirm}
        title={t('remoteBackup.regenerateToken')}
        message={<span>{t('remoteBackup.tokenInvalidatedWarning')}</span>}
        confirmText={t('remoteBackup.regenerateToken')}
        variant="danger"
        loading={regenTokenMutation.isPending}
        onConfirm={() => regenTokenMutation.mutate()}
        onCancel={() => setShowRegenTokenConfirm(false)}
      />
    </div>
  )
}

function RunRow({
  run,
  schedule,
}: {
  run: BackupRunRecord
  schedule: BackupSchedule | null
}) {
  const { formatBytes, formatDate, formatDuration } = useFormat()
  const startedMs = (run.started_at ?? 0) * 1000
  const endedMs = run.ended_at ? run.ended_at * 1000 : null
  const durationSec = endedMs && startedMs ? Math.max(0, Math.floor((endedMs - startedMs) / 1000)) : null

  const destOk = (run.destination_results ?? []).filter((r) => r.ok).length
  const destTotal = (run.destination_results ?? []).length

  const statusVariant: any =
    run.status === 'success' ? 'success' : run.status === 'running' ? 'accent' : 'danger'
  const statusIcon =
    run.status === 'success' ? (
      <CheckCircle2 size={11} />
    ) : run.status === 'running' ? (
      <Loader2 size={11} className="animate-spin" />
    ) : (
      <XCircle size={11} />
    )

  return (
    <tr className="hover:bg-fg/5 transition-colors">
      <td className="px-3 py-2 whitespace-nowrap text-fg font-medium">
        {schedule?.name ?? run.schedule_id}
      </td>
      <td className="px-3 py-2 whitespace-nowrap text-fg-muted font-mono" title={formatDate(run.started_at)}>
        {formatDate(run.started_at)}
      </td>
      <td className="px-3 py-2 whitespace-nowrap text-fg-muted">
        {run.status === 'running' ? (
          <Badge variant="accent" className="gap-1 text-[10px]">
            <Loader2 size={9} className="animate-spin" />
            ...
          </Badge>
        ) : durationSec !== null ? (
          formatDuration(durationSec)
        ) : (
          '-'
        )}
      </td>
      <td className="px-3 py-2 whitespace-nowrap">
        <Badge variant={statusVariant} className="gap-1 text-[10px]">
          {statusIcon}
          {run.status}
        </Badge>
      </td>
      <td className="px-3 py-2 whitespace-nowrap text-right font-mono text-fg-muted">
        {run.bytes_total > 0 ? formatBytes(run.bytes_total) : '-'}
      </td>
      <td className="px-3 py-2 whitespace-nowrap text-right font-mono text-fg-muted">
        {destTotal > 0 ? `${destOk}/${destTotal}` : '-'}
      </td>
      <td className="px-3 py-2 whitespace-nowrap text-right font-mono text-fg-muted">
        {run.pruned_count > 0 ? run.pruned_count : '-'}
      </td>
    </tr>
  )
}

function ScheduleCard({
  schedule,
  destinations,
  onRunNow,
  runNowLoading,
  onEdit,
  onDelete,
}: {
  schedule: BackupSchedule
  destinations: BackupDestination[]
  onRunNow: () => void
  runNowLoading: boolean
  onEdit: () => void
  onDelete: () => void
}) {
  const { t } = useI18n()
  const { formatRelativeTime } = useFormat()
  const queryClient = useQueryClient()

  const retention = schedule.retention ?? {}
  const retentionParts: string[] = []
  if (retention.keep_last) retentionParts.push(`${t('remoteBackup.keepLast')}: ${retention.keep_last}`)
  if (retention.keep_daily) retentionParts.push(`${t('remoteBackup.keepDaily')}: ${retention.keep_daily}`)
  if (retention.keep_weekly) retentionParts.push(`${t('remoteBackup.keepWeekly')}: ${retention.keep_weekly}`)
  if (retention.keep_monthly) retentionParts.push(`${t('remoteBackup.keepMonthly')}: ${retention.keep_monthly}`)
  const retentionText = retentionParts.length > 0 ? retentionParts.join(' · ') : '-'

  const toggleMutation = useMutation({
    mutationFn: async (enabled: boolean) => {
      const payload: BackupScheduleCreateInput = {
        name: schedule.name,
        enabled,
        cron_expr: schedule.cron_expr,
        destination_ids: schedule.destination_ids,
        source: schedule.source ?? {},
        retention: schedule.retention ?? {},
      }
      return await backupApi.updateSchedule(schedule.id, payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['backup-schedules'] })
    },
    onError: (err) => {
      toast({
        type: 'error',
        title: t('common.saveFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    },
  })

  return (
    <div className="rounded-xl border border-border bg-bg-card hover:border-accent/30 hover:shadow-sm transition-all p-4 flex flex-col gap-3">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2 flex-wrap">
            <h3 className="text-sm font-semibold text-fg truncate">{schedule.name}</h3>
          </div>
          <div className="mt-1.5 flex items-center gap-2 flex-wrap">
            <Badge variant="muted" className="text-[10px] gap-1">
              <Clock size={9} />
              {schedule.cron_expr}
            </Badge>
            {schedule.next_run_at ? (
              <span className="text-[10px] text-fg-muted">
                {t('remoteBackup.runsNext')}: {formatRelativeTime(schedule.next_run_at)}
              </span>
            ) : null}
          </div>
        </div>
        <label className="inline-flex items-center cursor-pointer shrink-0" title={schedule.enabled ? t('remoteBackup.enabled') : 'Disabled'}>
          <input
            type="checkbox"
            className="sr-only peer"
            checked={!!schedule.enabled}
            onChange={(e) => toggleMutation.mutate(e.target.checked)}
            disabled={toggleMutation.isPending}
          />
          <div className="w-9 h-5 bg-border rounded-full peer peer-checked:bg-accent transition-colors relative after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-4 shadow-sm"></div>
        </label>
      </div>

      <div className="flex items-center gap-1.5 flex-wrap">
        {schedule.source?.include_files && (
          <Badge variant="accent" className="gap-1 text-[10px]">
            <FileArchive size={9} />
            {t('remoteBackup.sourceFiles')}
          </Badge>
        )}
        {schedule.source?.include_db && (
          <Badge variant="success" className="gap-1 text-[10px]">
            <Database size={9} />
            {t('remoteBackup.sourceDb')}
          </Badge>
        )}
        {schedule.source?.include_config && (
          <Badge variant="muted" className="gap-1 text-[10px]">
            <SettingsIcon size={9} />
            {t('remoteBackup.sourceConfig')}
          </Badge>
        )}
      </div>

      <div className="flex items-center gap-1.5 flex-wrap">
        {destinations.length === 0 ? (
          <Badge variant="danger" className="gap-1 text-[10px]">
            <AlertTriangle size={9} />
            -
          </Badge>
        ) : (
          destinations.slice(0, 3).map((d) => {
            const meta = getDestinationMeta(d.type)
            return (
              <div
                key={d.id}
                className={`w-6 h-6 rounded flex items-center justify-center ${meta.bgClass} shrink-0`}
                title={d.name}
              >
                <span className="scale-75">{meta.icon}</span>
              </div>
            )
          })
        )}
        {destinations.length > 3 && (
          <Badge variant="muted" className="text-[10px]">+{destinations.length - 3}</Badge>
        )}
      </div>

      <div className="text-[10px] text-fg-muted leading-relaxed" title={retentionText}>
        {t('remoteBackup.retentionSummary')}: {retentionText}
      </div>

      <div className="flex items-center gap-1.5 pt-1 border-t border-border mt-auto">
        <Button
          variant="secondary"
          size="sm"
          className="flex-1"
          onClick={onRunNow}
          loading={runNowLoading}
          disabled={runNowLoading}
        >
          <PlayCircle size={13} />
          {t('remoteBackup.runNow')}
        </Button>
        <Button
          variant="ghost"
          size="icon-sm"
          onClick={onEdit}
          title={t('common.edit')}
          aria-label={t('common.edit')}
        >
          <Pencil size={14} />
        </Button>
        <Button
          variant="ghost"
          size="icon-sm"
          onClick={onDelete}
          className="text-danger hover:text-danger"
          title={t('common.delete')}
          aria-label={t('common.delete')}
        >
          <Trash2 size={14} />
        </Button>
      </div>
    </div>
  )
}

function ScheduleModal({
  open,
  editing,
  destinations,
  runImmediately,
  onRunImmediatelyChange,
  onClose,
  onSave,
  saving,
  isEdit,
}: {
  open: boolean
  editing: BackupSchedule | null
  destinations: BackupDestination[]
  runImmediately: boolean
  onRunImmediatelyChange: (v: boolean) => void
  onClose: () => void
  onSave: (data: BackupScheduleCreateInput) => void
  saving: boolean
  isEdit: boolean
}) {
  const { t } = useI18n()
  const [tab, setTab] = useState<ScheduleModalTab>('general')

  const [name, setName] = useState('')
  const [enabled, setEnabled] = useState(true)

  const [cronPreset, setCronPreset] = useState('daily')
  const [cronMin, setCronMin] = useState('0')
  const [cronHour, setCronHour] = useState('2')
  const [cronDom, setCronDom] = useState('*')
  const [cronMonth, setCronMonth] = useState('*')
  const [cronDow, setCronDow] = useState('*')

  const [includeFiles, setIncludeFiles] = useState(true)
  const [includeDb, setIncludeDb] = useState(true)
  const [includeConfig, setIncludeConfig] = useState(true)
  const [excludeDirsText, setExcludeDirsText] = useState(DEFAULT_EXCLUDE_DIRS)

  const [selectedDests, setSelectedDests] = useState<string[]>([])

  const [keepLast, setKeepLast] = useState<number | ''>(30)
  const [keepDaily, setKeepDaily] = useState<number | ''>(7)
  const [keepWeekly, setKeepWeekly] = useState<number | ''>(4)
  const [keepMonthly, setKeepMonthly] = useState<number | ''>(6)

  const cronExpr =
    cronPreset === 'custom'
      ? [cronMin, cronHour, cronDom, cronMonth, cronDow].map((s) => s.trim() || '*').join(' ')
      : (CRON_PRESETS.find((p) => p.key === cronPreset)?.expr ?? '0 2 * * *')

  const resetForms = () => {
    if (editing) {
      setName(editing.name)
      setEnabled(!!editing.enabled)
      const existing = CRON_PRESETS.find((p) => p.expr === editing.cron_expr)
      if (existing) {
        setCronPreset(existing.key)
      } else {
        setCronPreset('custom')
        const parts = editing.cron_expr.split(/\s+/)
        setCronMin(parts[0] ?? '0')
        setCronHour(parts[1] ?? '2')
        setCronDom(parts[2] ?? '*')
        setCronMonth(parts[3] ?? '*')
        setCronDow(parts[4] ?? '*')
      }
      setIncludeFiles(!!editing.source?.include_files)
      setIncludeDb(!!editing.source?.include_db)
      setIncludeConfig(!!editing.source?.include_config)
      setExcludeDirsText((editing.source?.exclude_dirs ?? []).join(', '))
      setSelectedDests([...(editing.destination_ids ?? [])])
      setKeepLast((editing.retention?.keep_last ?? 0) || '')
      setKeepDaily((editing.retention?.keep_daily ?? 0) || '')
      setKeepWeekly((editing.retention?.keep_weekly ?? 0) || '')
      setKeepMonthly((editing.retention?.keep_monthly ?? 0) || '')
    } else {
      setName('')
      setEnabled(true)
      setCronPreset('daily')
      setCronMin('0')
      setCronHour('2')
      setCronDom('*')
      setCronMonth('*')
      setCronDow('*')
      setIncludeFiles(true)
      setIncludeDb(true)
      setIncludeConfig(true)
      setExcludeDirsText(DEFAULT_EXCLUDE_DIRS)
      setSelectedDests([])
      setKeepLast(30)
      setKeepDaily(7)
      setKeepWeekly(4)
      setKeepMonthly(6)
    }
    setTab('general')
  }

  useEffect(() => {
    if (open) resetForms()
    
  }, [open, editing?.id])

  const humanReadable = useMemo(() => {
    if (cronExpr === '0 2 * * *') return `Daily at 02:00`
    if (cronExpr === '0 0 * * 0') return `Weekly on Sunday 00:00`
    if (cronExpr === '0 0 1 * *') return `Monthly on day 1 00:00`
    if (cronExpr === '0 */6 * * *') return `Every 6 hours`
    return cronExpr
  }, [cronExpr])

  const canSave =
    name.trim().length > 0 &&
    (includeFiles || includeDb || includeConfig) &&
    selectedDests.length > 0 &&
    !saving

  const handleSave = () => {
    if (!canSave) return
    const payload: BackupScheduleCreateInput = {
      name: name.trim(),
      enabled,
      cron_expr: cronExpr,
      destination_ids: selectedDests,
      source: {
        include_files: includeFiles,
        include_db: includeDb,
        include_config: includeConfig,
        exclude_dirs: excludeDirsText
          .split(',')
          .map((s) => s.trim())
          .filter(Boolean),
      },
      retention: {
        keep_last: typeof keepLast === 'number' ? keepLast : 0,
        keep_daily: typeof keepDaily === 'number' ? keepDaily : 0,
        keep_weekly: typeof keepWeekly === 'number' ? keepWeekly : 0,
        keep_monthly: typeof keepMonthly === 'number' ? keepMonthly : 0,
      },
    }
    onSave(payload)
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="lg"
      title={isEdit ? t('remoteBackup.editSchedule') : t('remoteBackup.createSchedule')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <label className="inline-flex items-center gap-2 cursor-pointer select-none text-xs text-fg-muted">
            <input
              type="checkbox"
              checked={runImmediately}
              onChange={(e) => onRunImmediatelyChange(e.target.checked)}
              className="accent-accent"
            />
            {t('remoteBackup.runImmediatelyAfterSave')}
          </label>
          <Button onClick={handleSave} loading={saving} disabled={!canSave}>
            {t('common.save')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="flex gap-0.5 p-1 bg-bg-sunken rounded-lg overflow-x-auto">
          {SCHEDULE_MODAL_TABS.map((tk) => (
            <button
              key={tk}
              onClick={() => setTab(tk)}
              className={`flex-1 min-w-[90px] flex items-center justify-center gap-1.5 px-2.5 py-2 rounded-md text-[11px] font-medium whitespace-nowrap transition-all ${
                tab === tk ? 'bg-bg-card text-fg shadow-sm' : 'text-fg-muted hover:text-fg'
              }`}
            >
              {tk === 'general' && <Power size={11} />}
              {tk === 'schedule' && <Clock size={11} />}
              {tk === 'source' && <FileArchive size={11} />}
              {tk === 'destinations' && <Cloud size={11} />}
              {tk === 'retention' && <Shield size={11} />}
              {t(`remoteBackup.tab${tk.charAt(0).toUpperCase() + tk.slice(1)}`)}
            </button>
          ))}
        </div>

        {tab === 'general' && (
          <div className="space-y-3">
            <FormField label={t('remoteBackup.scheduleName')} required>
              <Input
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Daily full backup"
              />
            </FormField>
            <div className="flex items-center gap-2.5">
              <label className="inline-flex items-center cursor-pointer select-none">
                <input
                  type="checkbox"
                  className="sr-only peer"
                  checked={enabled}
                  onChange={(e) => setEnabled(e.target.checked)}
                />
                <div className="w-9 h-5 bg-border rounded-full peer peer-checked:bg-accent transition-colors relative after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-4 shadow-sm"></div>
              </label>
              <span className="text-xs font-medium text-fg">{t('remoteBackup.enabled')}</span>
            </div>
          </div>
        )}

        {tab === 'schedule' && (
          <div className="space-y-3">
            <div>
              <p className="text-[11px] font-medium text-fg mb-1.5">{t('remoteBackup.cronExpr')}</p>
              <div className="flex items-center gap-1.5 flex-wrap">
                {CRON_PRESETS.map((p) => (
                  <button
                    key={p.key}
                    onClick={() => setCronPreset(p.key)}
                    className={`px-2.5 py-1.5 rounded-md text-[11px] font-medium transition-all ${
                      cronPreset === p.key
                        ? 'bg-accent text-white shadow-sm'
                        : 'bg-bg-sunken text-fg-muted hover:text-fg hover:bg-bg-card'
                    }`}
                  >
                    {t(`remoteBackup.${p.label_key}`)}
                  </button>
                ))}
              </div>
            </div>

            {cronPreset === 'custom' && (
              <div className="grid grid-cols-5 gap-2">
                {[
                  { label: 'Min', value: cronMin, onChange: setCronMin, ph: '0-59' },
                  { label: 'Hour', value: cronHour, onChange: setCronHour, ph: '0-23' },
                  { label: 'Dom', value: cronDom, onChange: setCronDom, ph: '1-31' },
                  { label: 'Month', value: cronMonth, onChange: setCronMonth, ph: '1-12' },
                  { label: 'Dow', value: cronDow, onChange: setCronDow, ph: '0-7' },
                ].map((f, i) => (
                  <div key={i}>
                    <label className="block text-[10px] text-fg-subtle mb-1">{f.label}</label>
                    <Input value={f.value} onChange={(e) => f.onChange(e.target.value)} placeholder={f.ph} className="!py-1.5 text-[11px]" />
                  </div>
                ))}
              </div>
            )}

            <div className="rounded-lg border border-border bg-bg-sunken/50 px-3 py-2.5 space-y-1.5">
              <div className="flex items-center gap-2 text-[11px]">
                <span className="text-fg-subtle font-medium">{t('remoteBackup.cronExpr')}:</span>
                <code className="font-mono text-fg">{cronExpr}</code>
              </div>
              <div className="flex items-center gap-2 text-[11px]">
                <span className="text-fg-subtle font-medium">{t('remoteBackup.cronHumanPreview')}:</span>
                <span className="text-fg">{humanReadable}</span>
              </div>
            </div>
          </div>
        )}

        {tab === 'source' && (
          <div className="space-y-3">
            <div className="space-y-2">
              <ScopeCheckbox
                checked={includeFiles}
                onChange={setIncludeFiles}
                icon={<FileArchive size={16} />}
                title={t('remoteBackup.sourceFiles')}
                desc="Back up web root files and directories"
              />
              <ScopeCheckbox
                checked={includeDb}
                onChange={setIncludeDb}
                icon={<Database size={16} />}
                title={t('remoteBackup.sourceDb')}
                desc="Export all configured databases"
              />
              <ScopeCheckbox
                checked={includeConfig}
                onChange={setIncludeConfig}
                icon={<SettingsIcon size={16} />}
                title={t('remoteBackup.sourceConfig')}
                desc="Include panel configuration in backup"
              />
            </div>
            <FormField label={t('remoteBackup.sourceExclude')}>
              <Input
                value={excludeDirsText}
                onChange={(e) => setExcludeDirsText(e.target.value)}
                placeholder={DEFAULT_EXCLUDE_DIRS}
              />
            </FormField>
            {!includeFiles && !includeDb && !includeConfig && (
              <div className="flex items-start gap-2 text-xs text-danger bg-danger/5 border border-danger/20 rounded-lg px-3 py-2">
                <AlertTriangle size={14} className="shrink-0 mt-0.5" />
                <span>No source selected</span>
              </div>
            )}
          </div>
        )}

        {tab === 'destinations' && (
          <div className="space-y-3">
            {destinations.length === 0 ? (
              <div className="rounded-lg border border-warning/20 bg-warning/5 px-3 py-2.5 flex items-start gap-2.5">
                <AlertTriangle size={14} className="text-warning shrink-0 mt-0.5" />
                <div>
                  <p className="text-xs font-medium text-fg">{t('remoteBackup.noDestsSelected')}</p>
                  <button
                    onClick={() => {
                      document.dispatchEvent(new CustomEvent('backup:switch-tab', { detail: 'destinations' }))
                    }}
                    className="text-[11px] text-accent hover:underline mt-0.5"
                  >
                    {t('remoteBackup.goCreateDestinations')} →
                  </button>
                </div>
              </div>
            ) : (
              <div className="space-y-1.5 max-h-[320px] overflow-y-auto pr-1">
                {destinations.map((d) => {
                  const checked = selectedDests.includes(d.id)
                  return (
                    <label
                      key={d.id}
                      className={`flex items-start gap-3 px-3 py-2.5 rounded-lg border cursor-pointer transition-colors ${
                        checked ? 'border-accent/40 bg-accent/5' : 'border-border hover:bg-fg/5'
                      }`}
                    >
                      <input
                        type="checkbox"
                        checked={checked}
                        onChange={() =>
                          setSelectedDests((prev) =>
                            prev.includes(d.id) ? prev.filter((x) => x !== d.id) : [...prev, d.id]
                          )
                        }
                        className="mt-0.5 accent-accent"
                      />
                      <div className={`w-7 h-7 rounded-md flex items-center justify-center shrink-0 ${getDestinationMeta(d.type).bgClass}`}>
                        <span className="scale-[0.75]">{getDestinationMeta(d.type).icon}</span>
                      </div>
                      <div className="min-w-0 flex-1">
                        <p className="text-xs font-medium text-fg truncate">{d.name}</p>
                        <p className="text-[10px] text-fg-subtle truncate">
                          {getDestinationSummary(d)}
                        </p>
                      </div>
                    </label>
                  )
                })}
              </div>
            )}
            {selectedDests.length === 0 && destinations.length > 0 && (
              <div className="flex items-start gap-2 text-xs text-warning bg-warning/5 border border-warning/20 rounded-lg px-3 py-2">
                <AlertTriangle size={14} className="shrink-0 mt-0.5" />
                <span>{t('remoteBackup.noDestsSelected')}</span>
              </div>
            )}
          </div>
        )}

        {tab === 'retention' && (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <FormField label={t('remoteBackup.keepLast')}>
              <Input
                type="number"
                min={0}
                value={keepLast}
                onChange={(e) => setKeepLast(e.target.value === '' ? '' : Math.max(0, Number(e.target.value) || 0))}
                placeholder="0 = disable"
              />
            </FormField>
            <FormField label={t('remoteBackup.keepDaily')}>
              <Input
                type="number"
                min={0}
                value={keepDaily}
                onChange={(e) => setKeepDaily(e.target.value === '' ? '' : Math.max(0, Number(e.target.value) || 0))}
                placeholder="0 = disable"
              />
            </FormField>
            <FormField label={t('remoteBackup.keepWeekly')}>
              <Input
                type="number"
                min={0}
                value={keepWeekly}
                onChange={(e) => setKeepWeekly(e.target.value === '' ? '' : Math.max(0, Number(e.target.value) || 0))}
                placeholder="0 = disable"
              />
            </FormField>
            <FormField label={t('remoteBackup.keepMonthly')}>
              <Input
                type="number"
                min={0}
                value={keepMonthly}
                onChange={(e) => setKeepMonthly(e.target.value === '' ? '' : Math.max(0, Number(e.target.value) || 0))}
                placeholder="0 = disable"
              />
            </FormField>
          </div>
        )}
      </div>
    </Modal>
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
        ${checked ? 'border-accent/40 bg-accent/5' : 'border-border hover:bg-fg/5'}
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
