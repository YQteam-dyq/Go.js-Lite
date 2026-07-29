import { useQuery } from '@tanstack/react-query'
import {
  HardDrive,
  BarChart3,
  Folder,
  FileText,
  AlertCircle,
  RefreshCw,
  Clock,
} from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Skeleton, SkeletonCard } from '@/components/ui/Skeleton'
import { diskAnalysisApi } from '@/api/diskAnalysis'
import { useFormat } from '@/lib/format'
import { useI18n } from '@/hooks/useI18n'
import type { DiskDirectory, LargeFile } from '@shared/types'

export default function DiskAnalysis() {
  const { t } = useI18n()
  const { formatBytes, formatDate } = useFormat()

  const {
    data: analysis,
    isLoading: loadingAnalysis,
    error: analysisError,
    refetch: refetchAnalysis,
  } = useQuery({
    queryKey: ['disk-analysis'],
    queryFn: () => diskAnalysisApi.get(),
  })

  const {
    data: largeFiles,
    isLoading: loadingLarge,
    error: largeFilesError,
    refetch: refetchLarge,
  } = useQuery({
    queryKey: ['disk-analysis', 'large-files'],
    queryFn: () => diskAnalysisApi.getLargeFiles(),
  })

  const diskTotal = analysis?.diskTotal ?? 0
  const diskFree = analysis?.diskFree ?? 0
  const diskUsed = diskTotal > 0 ? diskTotal - diskFree : 0
  const usagePercent = diskTotal > 0 ? (diskUsed / diskTotal) * 100 : 0

  const directories = analysis?.directories ?? []
  const maxDirSize = directories.length > 0 ? directories[0].size : 0
  const files = largeFiles?.files ?? []

  const hasAnalysisError = !loadingAnalysis && !analysis && !!analysisError
  const hasLargeFilesError = !loadingLarge && !largeFiles && !!largeFilesError

  return (
    <div className="p-4 md:p-6 space-y-5">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-fg">{t('diskAnalysis.title')}</h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('diskAnalysis.subtitle')}</p>
        </div>
        <Button
          variant="secondary"
          size="sm"
          onClick={() => {
            refetchAnalysis()
            refetchLarge()
          }}
        >
          <RefreshCw size={16} />
          {t('common.refresh')}
        </Button>
      </div>

      {loadingAnalysis ? (
        <SkeletonCard />
      ) : hasAnalysisError ? (
        <ErrorState
          message={
            analysisError instanceof Error
              ? analysisError.message
              : t('diskAnalysis.loadFailed')
          }
          onRetry={() => refetchAnalysis()}
          retryLabel={t('common.retry')}
        />
      ) : analysis ? (
        <Card className="card-hover">
          <CardHeader className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-lg bg-accent/10 text-accent flex items-center justify-center">
              <HardDrive size={20} />
            </div>
            <div>
              <div className="text-sm font-medium text-fg">{t('diskAnalysis.overview')}</div>
              <div className="text-xs text-fg-subtle">{t('diskAnalysis.overviewSubtitle')}</div>
            </div>
          </CardHeader>
          <CardBody className="space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <OverviewStat
                label={t('diskAnalysis.total')}
                value={formatBytes(diskTotal)}
                color="info"
              />
              <OverviewStat
                label={t('diskAnalysis.used')}
                value={formatBytes(diskUsed)}
                color="warning"
              />
              <OverviewStat
                label={t('diskAnalysis.free')}
                value={formatBytes(diskFree)}
                color="success"
              />
            </div>
            <div className="space-y-1.5">
              <div className="flex justify-between text-sm">
                <span className="text-fg-muted">{t('diskAnalysis.usage')}</span>
                <span className="text-fg font-medium">{usagePercent.toFixed(1)}%</span>
              </div>
              <div className="h-2.5 bg-bg-sunken rounded-full overflow-hidden">
                <div
                  className="h-full bg-accent rounded-full transition-all duration-500"
                  style={{ width: `${Math.min(usagePercent, 100)}%` }}
                />
              </div>
            </div>
          </CardBody>
        </Card>
      ) : null}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <Card className="card-hover flex flex-col">
          <CardHeader className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-lg bg-info/10 text-info flex items-center justify-center">
              <BarChart3 size={20} />
            </div>
            <div className="min-w-0">
              <div className="text-sm font-medium text-fg truncate">
                {t('diskAnalysis.directories')}
              </div>
              <div className="text-xs text-fg-subtle truncate">
                {t('diskAnalysis.directoriesSubtitle')}
              </div>
            </div>
            {directories.length > 0 && (
              <Badge variant="muted" className="ml-auto shrink-0">
                {directories.length}
                {t('diskAnalysis.directoriesCount')}
              </Badge>
            )}
          </CardHeader>
          <CardBody className="p-0 flex-1 min-h-0">
            {loadingAnalysis ? (
              <DirectorySkeleton />
            ) : hasAnalysisError ? (
              <div className="p-6">
                <ErrorState
                  compact
                  message={t('diskAnalysis.loadFailed')}
                  onRetry={() => refetchAnalysis()}
                  retryLabel={t('common.retry')}
                />
              </div>
            ) : directories.length === 0 ? (
              <div className="p-8 text-center text-sm text-fg-muted">
                {t('diskAnalysis.noDirectories')}
              </div>
            ) : (
              <div className="max-h-96 overflow-auto">
                <ul className="divide-y divide-border">
                  {directories.map((dir, i) => (
                    <DirectoryRow
                      key={`${dir.path}-${i}`}
                      dir={dir}
                      maxDirSize={maxDirSize}
                      formatBytes={formatBytes}
                      t={t}
                    />
                  ))}
                </ul>
              </div>
            )}
          </CardBody>
        </Card>

        <Card className="card-hover flex flex-col">
          <CardHeader className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-lg bg-warning/10 text-warning flex items-center justify-center">
              <FileText size={20} />
            </div>
            <div className="min-w-0">
              <div className="text-sm font-medium text-fg truncate">
                {t('diskAnalysis.largeFiles')}
              </div>
              <div className="text-xs text-fg-subtle truncate">
                {t('diskAnalysis.largeFilesSubtitle')}
              </div>
            </div>
            {files.length > 0 && (
              <Badge variant="muted" className="ml-auto shrink-0">
                {files.length}
                {t('diskAnalysis.largeFilesCount')}
              </Badge>
            )}
          </CardHeader>
          <CardBody className="p-0 flex-1 min-h-0">
            {loadingLarge ? (
              <DirectorySkeleton />
            ) : hasLargeFilesError ? (
              <div className="p-6">
                <ErrorState
                  compact
                  message={
                    largeFilesError instanceof Error
                      ? largeFilesError.message
                      : t('diskAnalysis.loadFailed')
                  }
                  onRetry={() => refetchLarge()}
                  retryLabel={t('common.retry')}
                />
              </div>
            ) : files.length === 0 ? (
              <div className="p-8 text-center text-sm text-fg-muted">
                {t('diskAnalysis.noLargeFiles')}
              </div>
            ) : (
              <div className="max-h-96 overflow-auto">
                <ul className="divide-y divide-border">
                  {files.map((file, i) => (
                    <LargeFileRow
                      key={`${file.path}-${i}`}
                      file={file}
                      formatBytes={formatBytes}
                      formatDate={formatDate}
                    />
                  ))}
                </ul>
              </div>
            )}
          </CardBody>
        </Card>
      </div>
    </div>
  )
}

type Color = 'accent' | 'success' | 'warning' | 'danger' | 'info'

const overviewColorClasses: Record<Color, string> = {
  accent: 'bg-accent/10 text-accent',
  success: 'bg-success/10 text-success',
  warning: 'bg-warning/10 text-warning',
  danger: 'bg-danger/10 text-danger',
  info: 'bg-info/10 text-info',
}

function OverviewStat({
  label,
  value,
  color = 'accent',
}: {
  label: string
  value: string
  color?: Color
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <div
        className={`w-8 h-8 rounded-md flex items-center justify-center ${overviewColorClasses[color]}`}
      >
        <HardDrive size={16} />
      </div>
      <div className="text-xs text-fg-subtle">{label}</div>
      <div className="text-sm font-medium text-fg font-mono truncate">{value}</div>
    </div>
  )
}

function DirectoryRow({
  dir,
  maxDirSize,
  formatBytes,
  t,
}: {
  dir: DiskDirectory
  maxDirSize: number
  formatBytes: (n: number) => string
  t: (key: string) => string
}) {
  const barWidth = maxDirSize > 0 ? (dir.size / maxDirSize) * 100 : 0
  return (
    <li className="px-4 py-3">
      <div className="flex items-center gap-2 mb-1.5">
        <Folder size={14} className="text-fg-muted shrink-0" />
        <span className="text-sm font-medium text-fg truncate flex-1 min-w-0" title={dir.path}>
          {dir.name}
        </span>
        <span className="text-sm text-fg font-mono shrink-0">{formatBytes(dir.size)}</span>
      </div>
      <div className="h-1.5 bg-bg-sunken rounded-full overflow-hidden mb-1">
        <div
          className="h-full bg-accent/70 rounded-full transition-all duration-300"
          style={{ width: `${Math.min(barWidth, 100)}%` }}
        />
      </div>
      <div className="flex justify-between text-xs text-fg-subtle">
        <span>
          {dir.fileCount} {t('diskAnalysis.fileCount')}
        </span>
        <span>{dir.percent.toFixed(1)}%</span>
      </div>
    </li>
  )
}

function LargeFileRow({
  file,
  formatBytes,
  formatDate,
}: {
  file: LargeFile
  formatBytes: (n: number) => string
  formatDate: (ts: number) => string
}) {
  const modifiedTs = file.modified ? new Date(file.modified).getTime() : 0
  return (
    <li className="px-4 py-3">
      <div className="flex items-center gap-2 mb-1">
        <FileText size={14} className="text-fg-muted shrink-0" />
        <span className="text-sm font-medium text-fg truncate flex-1 min-w-0" title={file.name}>
          {file.name}
        </span>
        <span className="text-sm text-fg font-mono shrink-0">{formatBytes(file.size)}</span>
      </div>
      <div className="flex items-center gap-3 text-xs text-fg-subtle pl-5">
        <span className="truncate min-w-0" title={file.path}>
          {file.path}
        </span>
        {modifiedTs > 0 && (
          <span className="flex items-center gap-1 shrink-0">
            <Clock size={11} />
            {formatDate(modifiedTs)}
          </span>
        )}
      </div>
    </li>
  )
}

function DirectorySkeleton() {
  return (
    <div className="divide-y divide-border">
      {Array.from({ length: 6 }).map((_, i) => (
        <div key={i} className="px-4 py-3 space-y-2">
          <div className="flex items-center gap-2">
            <Skeleton variant="circular" width={14} height={14} />
            <Skeleton variant="text" className="flex-1" />
            <Skeleton variant="text" className="w-16 h-4" />
          </div>
          <Skeleton variant="rectangular" height={6} className="w-full" />
        </div>
      ))}
    </div>
  )
}

function ErrorState({
  message,
  onRetry,
  retryLabel,
  compact = false,
}: {
  message: string
  onRetry: () => void
  retryLabel: string
  compact?: boolean
}) {
  if (compact) {
    return (
      <div className="text-center">
        <div className="w-12 h-12 mx-auto mb-3 rounded-full bg-danger/10 text-danger flex items-center justify-center">
          <AlertCircle size={24} />
        </div>
        <p className="text-sm text-fg-muted mb-4">{message}</p>
        <Button variant="secondary" size="sm" onClick={onRetry}>
          <RefreshCw size={16} />
          {retryLabel}
        </Button>
      </div>
    )
  }
  return (
    <Card className="p-8 text-center">
      <div className="w-14 h-14 mx-auto mb-4 rounded-full bg-danger/10 text-danger flex items-center justify-center">
        <AlertCircle size={28} />
      </div>
      <p className="text-sm font-medium text-fg mb-1">{message}</p>
      <div className="mb-5">
        <Button variant="secondary" size="sm" onClick={onRetry} className="mt-3">
          <RefreshCw size={16} />
          {retryLabel}
        </Button>
      </div>
    </Card>
  )
}
