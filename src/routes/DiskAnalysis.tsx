import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  HardDrive,
  BarChart3,
  Folder,
  FileText,
  AlertCircle,
  RefreshCw,
  Clock,
  ArrowLeft,
  ChevronRight,
  Home,
} from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Skeleton, SkeletonCard } from '@/components/ui/Skeleton'
import { diskAnalysisApi } from '@/api/diskAnalysis'
import { useFormat } from '@/lib/format'
import { useI18n } from '@/hooks/useI18n'
import type { DiskDirectory, LargeFile } from '@shared/types'

function getParentPath(path: string): string {
  if (path === '/' || path === '') return '/'
  const trimmed = path.replace(/\/+$/, '')
  const lastSlash = trimmed.lastIndexOf('/')
  if (lastSlash <= 0) return '/'
  return trimmed.slice(0, lastSlash)
}

export default function DiskAnalysis() {
  const { t } = useI18n()
  const { formatBytes, formatDate } = useFormat()
  const [currentPath, setCurrentPath] = useState('/')

  const {
    data: analysis,
    isLoading: loadingAnalysis,
    error: analysisError,
    refetch: refetchAnalysis,
  } = useQuery({
    queryKey: ['disk-analysis', currentPath],
    queryFn: () => diskAnalysisApi.get(currentPath),
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
  const totalSize = analysis?.totalSize ?? 0
  const maxDirSize = directories.length > 0 ? directories[0].size : 0
  const files = largeFiles?.files ?? []

  const hasAnalysisError = !loadingAnalysis && !analysis && !!analysisError
  const hasLargeFilesError = !loadingLarge && !largeFiles && !!largeFilesError

  const canGoBack = currentPath !== '/' && currentPath !== ''

  const handleDrillDown = (path: string) => {
    setCurrentPath(path)
  }
  const handleBack = () => {
    setCurrentPath(getParentPath(currentPath))
  }
  const handleHome = () => {
    setCurrentPath('/')
  }

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

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <DiskRingCard
          loading={loadingAnalysis}
          hasError={hasAnalysisError}
          percent={usagePercent}
          used={diskUsed}
          total={diskTotal}
          formatBytes={formatBytes}
          t={t}
        />
        <DirBarChartCard
          loading={loadingAnalysis}
          hasError={hasAnalysisError}
          directories={directories}
          totalSize={totalSize}
          maxDirSize={maxDirSize}
          currentPath={currentPath}
          canGoBack={canGoBack}
          onDrillDown={handleDrillDown}
          onBack={handleBack}
          onHome={handleHome}
          formatBytes={formatBytes}
          t={t}
        />
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
                  style={{ width: `$$Math.min(usagePercent, 100)}%` }}
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
                      key={`$$dir.path}-$$i}`}
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
                      key={`$$file.path}-$$i}`}
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
        className={`w-8 h-8 rounded-md flex items-center justify-center $$overviewColorClasses[color]}`}
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
          style={{ width: `$$Math.min(barWidth, 100)}%` }}
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
  const modifiedTs = file.modified ? new Date(file.modified).getTime() / 1000 : 0
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

type TFunc = (key: string, params?: Record<string, string | number>) => string

function DiskRingCard({
  loading,
  hasError,
  percent,
  used,
  total,
  formatBytes,
  t,
}: {
  loading: boolean
  hasError: boolean
  percent: number
  used: number
  total: number
  formatBytes: (n: number) => string
  t: TFunc
}) {
  return (
    <Card className="card-hover">
      <CardHeader className="flex items-center gap-3">
        <div className="w-10 h-10 rounded-lg bg-accent/10 text-accent flex items-center justify-center">
          <HardDrive size={20} />
        </div>
        <div className="min-w-0">
          <div className="text-sm font-medium text-fg truncate">
            {t('diskAnalysis.usageRing')}
          </div>
          <div className="text-xs text-fg-subtle truncate">
            {t('diskAnalysis.overviewSubtitle')}
          </div>
        </div>
      </CardHeader>
      <CardBody>
        {loading ? (
          <div className="flex flex-col items-center py-4">
            <Skeleton variant="circular" width={160} height={160} />
            <Skeleton variant="text" className="w-40 h-4 mt-4" />
          </div>
        ) : hasError ? (
          <div className="py-6 text-center text-sm text-fg-muted">
            {t('diskAnalysis.loadFailed')}
          </div>
        ) : (
          <DiskRing
            percent={percent}
            used={used}
            total={total}
            formatBytes={formatBytes}
            t={t}
          />
        )}
      </CardBody>
    </Card>
  )
}

function DiskRing({
  percent,
  used,
  total,
  formatBytes,
  t,
}: {
  percent: number
  used: number
  total: number
  formatBytes: (n: number) => string
  t: TFunc
}) {
  const clamped = Math.min(Math.max(percent, 0), 100)
  const isHighUsage = clamped >= 80
  const radius = 70
  const circumference = 2 * Math.PI * radius
  const dash = (clamped / 100) * circumference
  const displayPercent = total > 0 ? clamped.toFixed(0) : '—'

  return (
    <div className="flex flex-col items-center">
      <div className="relative">
        <svg
          viewBox="0 0 160 160"
          className="w-36 h-36 md:w-40 md:h-40"
          role="img"
          aria-label={`$$t('diskAnalysis.usage')} $$displayPercent}%`}
        >
          <circle
            cx="80"
            cy="80"
            r={radius}
            fill="none"
            strokeWidth="20"
            className="stroke-bg-sunken"
          />
          <circle
            cx="80"
            cy="80"
            r={radius}
            fill="none"
            strokeWidth="20"
            strokeDasharray={`$$dash} $$circumference}`}
            strokeLinecap="round"
            transform="rotate(-90 80 80)"
            className={isHighUsage ? 'stroke-warning' : 'stroke-accent'}
            style={{ transition: 'stroke-dasharray 0.8s cubic-bezier(0.16, 1, 0.3, 1)' }}
          />
          <text
            x="80"
            y="80"
            textAnchor="middle"
            dominantBaseline="central"
            className="fill-fg font-mono"
            style={{ fontSize: 30, fontWeight: 700 }}
          >
            {total > 0 ? `$$displayPercent}%` : displayPercent}
          </text>
        </svg>
      </div>
      <div className="mt-4 text-center space-y-1">
        <div className="text-sm text-fg-muted">
          {t('diskAnalysis.usedOfTotal', {
            used: formatBytes(used),
            total: formatBytes(total),
          })}
        </div>
        <div className="flex items-center justify-center gap-3 text-xs">
          <span className="flex items-center gap-1.5">
            <span
              className={`inline-block w-2 h-2 rounded-full $$isHighUsage ? 'bg-warning' : 'bg-accent'}`}
            />
            <span className="text-fg-subtle">{t('diskAnalysis.used')}</span>
          </span>
          <span className="flex items-center gap-1.5">
            <span className="inline-block w-2 h-2 rounded-full bg-bg-sunken border border-border" />
            <span className="text-fg-subtle">{t('diskAnalysis.free')}</span>
          </span>
        </div>
      </div>
    </div>
  )
}

function DirBarChartCard({
  loading,
  hasError,
  directories,
  totalSize,
  maxDirSize,
  currentPath,
  canGoBack,
  onDrillDown,
  onBack,
  onHome,
  formatBytes,
  t,
}: {
  loading: boolean
  hasError: boolean
  directories: DiskDirectory[]
  totalSize: number
  maxDirSize: number
  currentPath: string
  canGoBack: boolean
  onDrillDown: (path: string) => void
  onBack: () => void
  onHome: () => void
  formatBytes: (n: number) => string
  t: TFunc
}) {
  const topDirs = directories.slice(0, 10)

  return (
    <Card className="card-hover flex flex-col">
      <CardHeader className="flex items-center gap-3">
        <div className="w-10 h-10 rounded-lg bg-info/10 text-info flex items-center justify-center shrink-0">
          <BarChart3 size={20} />
        </div>
        <div className="min-w-0 flex-1">
          <div className="text-sm font-medium text-fg truncate">
            {t('diskAnalysis.topDirectories')}
          </div>
          <div className="text-xs text-fg-subtle truncate">
            {t('diskAnalysis.topDirectoriesSubtitle')}
          </div>
        </div>
        <div className="flex items-center gap-1 shrink-0">
          {canGoBack && (
            <Button
              variant="ghost"
              size="icon-sm"
              onClick={onHome}
              title={t('nav.diskAnalysis')}
              aria-label={t('nav.diskAnalysis')}
            >
              <Home size={15} />
            </Button>
          )}
          {canGoBack && (
            <Button
              variant="ghost"
              size="icon-sm"
              onClick={onBack}
              title={t('diskAnalysis.backToParent')}
              aria-label={t('diskAnalysis.backToParent')}
            >
              <ArrowLeft size={15} />
            </Button>
          )}
        </div>
      </CardHeader>
      <CardBody className="flex-1 min-h-0 p-0">
        {canGoBack && (
          <div className="px-4 pt-3 pb-1 flex items-center gap-1 text-xs text-fg-subtle">
            <span className="text-fg-subtle/70">{t('diskAnalysis.currentPath')}:</span>
            <span className="font-mono truncate min-w-0" title={currentPath}>
              {currentPath}
            </span>
          </div>
        )}
        {loading ? (
          <div className="p-4 space-y-3">
            {Array.from({ length: 6 }).map((_, i) => (
              <div key={i} className="space-y-1.5">
                <div className="flex items-center gap-2">
                  <Skeleton variant="text" className="w-24 h-3.5" />
                  <Skeleton variant="text" className="w-12 h-3.5 ml-auto" />
                </div>
                <Skeleton variant="rectangular" height={8} className="w-full" />
              </div>
            ))}
          </div>
        ) : hasError ? (
          <div className="p-6 text-center text-sm text-fg-muted">
            {t('diskAnalysis.loadFailed')}
          </div>
        ) : topDirs.length === 0 ? (
          <div className="p-8 text-center text-sm text-fg-muted">
            {canGoBack ? t('diskAnalysis.noSubDirectories') : t('diskAnalysis.noDirectories')}
          </div>
        ) : (
          <DirBarChart
            directories={topDirs}
            totalSize={totalSize}
            maxDirSize={maxDirSize}
            onDrillDown={onDrillDown}
            formatBytes={formatBytes}
            t={t}
          />
        )}
      </CardBody>
    </Card>
  )
}

function DirBarChart({
  directories,
  totalSize,
  maxDirSize,
  onDrillDown,
  formatBytes,
  t,
}: {
  directories: DiskDirectory[]
  totalSize: number
  maxDirSize: number
  onDrillDown: (path: string) => void
  formatBytes: (n: number) => string
  t: TFunc
}) {
  return (
    <div className="p-3 space-y-1">
      {directories.map((dir, i) => {
        const barWidth = maxDirSize > 0 ? (dir.size / maxDirSize) * 100 : 0
        const sharePct = totalSize > 0 ? (dir.size / totalSize) * 100 : 0
        return (
          <button
            key={`$$dir.path}-$$i}`}
            type="button"
            onClick={() => onDrillDown(dir.path)}
            className="w-full text-left rounded-md px-2 py-1.5 transition-colors hover:bg-bg-sunken focus-ring group"
            title={t('diskAnalysis.drillHint')}
          >
            <div className="flex items-center gap-2 mb-1">
              <span className="text-2xs text-fg-subtle w-4 shrink-0 text-right font-mono">
                {i + 1}
              </span>
              <Folder size={13} className="text-fg-muted shrink-0 group-hover:text-accent transition-colors" />
              <span
                className="text-sm font-medium text-fg truncate flex-1 min-w-0"
                title={dir.path}
              >
                {dir.name}
              </span>
              <ChevronRight
                size={13}
                className="text-fg-subtle/50 shrink-0 group-hover:text-accent group-hover:translate-x-0.5 transition-all"
              />
              <span className="text-xs text-fg font-mono shrink-0 w-16 text-right">
                {formatBytes(dir.size)}
              </span>
            </div>
            <div className="flex items-center gap-2">
              <span className="w-4 shrink-0" />
              <div className="flex-1 h-2 bg-bg-sunken rounded-full overflow-hidden">
                <div
                  className="h-full rounded-full transition-all duration-500"
                  style={{
                    width: `$$Math.min(barWidth, 100)}%`,
                    background:
                      'linear-gradient(90deg, hsl(var(--accent)), hsl(var(--warning)))',
                  }}
                />
              </div>
              <span className="text-2xs text-fg-subtle shrink-0 w-12 text-right font-mono">
                {sharePct.toFixed(1)}%
              </span>
            </div>
          </button>
        )
      })}
    </div>
  )
}
