import { useQuery } from '@tanstack/react-query'
import { HardDrive, Server, Clock, Files, Upload, FileText, FolderOpen, Image, FileCode, File, Activity } from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { SkeletonDashboard } from '@/components/ui/Skeleton'
import { EmptyError } from '@/components/ui/EmptyState'
import { dashboardApi } from '@/api/dashboard'
import { monitorApi } from '@/api/monitor'
import { Sparkline } from '@/components/ui/Sparkline'
import { useFormat, getFileExtension, isImageFile, isTextFile } from '@/lib/format'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { useI18n } from '@/hooks/useI18n'
import type { FileEntry, MonitorReport } from '@shared/types'

export default function Dashboard() {
  const { t } = useI18n()
  const { formatDate, formatNumber, formatBytes, formatRelativeTime } = useFormat()
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['dashboard'],
    queryFn: () => dashboardApi.get(),
  })

  const monitorQuery = useQuery({
    queryKey: ['monitor'],
    queryFn: () => monitorApi.status(),
    staleTime: 60 * 1000,
  })
  const monitorData: MonitorReport | undefined = monitorQuery.data

  if (isLoading) {
    return (
      <div className="p-4 md:p-6">
        <SkeletonDashboard />
      </div>
    )
  }

  if (error) {
    return (
      <div className="p-4 md:p-6">
        <EmptyError
          error={error instanceof Error ? error.message : t('common.unknownError')}
          onRetry={() => refetch()}
        />
      </div>
    )
  }

  if (!data) return null

  const diskPercent = data.diskTotal > 0 ? (data.diskUsed / data.diskTotal) * 100 : 0

  const getDiskColor = () => {
    if (diskPercent >= 90) return 'from-danger to-danger/70'
    if (diskPercent >= 80) return 'from-warning to-warning/70'
    return 'from-accent to-accent/70'
  }

  const getDiskTextColor = () => {
    if (diskPercent >= 90) return 'text-danger'
    if (diskPercent >= 80) return 'text-warning'
    return 'text-accent'
  }

  return (
    <div className="p-4 md:p-6 space-y-5 page-enter">
      <div className="flex items-center justify-between stagger-1">
        <div>
          <h1 className="text-xl font-semibold text-fg">{t('dashboard.title')}</h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('dashboard.subtitle')}</p>
        </div>
        <Link to="/files">
          <Button variant="secondary" size="sm" className="active:scale-95">
            <Files size={16} />
            {t('dashboard.fileManager')}
          </Button>
        </Link>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 stagger-2">
        <Card className="card-hover">
          <CardHeader className="flex items-center gap-3 py-4">
            <div className="w-11 h-11 rounded-xl bg-accent/10 text-accent flex items-center justify-center">
              <Server size={20} />
            </div>
            <div>
              <div className="text-sm font-semibold text-fg">{t('dashboard.serverInfo')}</div>
              <div className="text-xs text-fg-subtle">{t('dashboard.serverInfoSubtitle')}</div>
            </div>
          </CardHeader>
          <CardBody className="space-y-2.5 text-sm">
            <InfoRow label={t('dashboard.phpVersion')} value={data.phpVersion} />
            <InfoRow label={t('dashboard.sapi')} value={data.sapi} />
            <InfoRow label={t('dashboard.webServer')} value={data.webServer} />
            <InfoRow label={t('dashboard.hostname')} value={data.hostname} />
            <InfoRow label={t('dashboard.timezone')} value={data.timezone} />
            <InfoRow label={t('dashboard.currentTime')} value={formatDate(data.now)} />
          </CardBody>
        </Card>

        <Card className="card-hover">
          <CardHeader className="flex items-center gap-3 py-4">
            <div className="w-11 h-11 rounded-xl bg-success/10 text-success flex items-center justify-center">
              <HardDrive size={20} />
            </div>
            <div>
              <div className="text-sm font-semibold text-fg">{t('dashboard.diskUsage')}</div>
              <div className="text-xs text-fg-subtle">{t('dashboard.diskUsageSubtitle')}</div>
            </div>
          </CardHeader>
          <CardBody className="space-y-4">
            <div className="space-y-2">
              <div className="flex justify-between text-sm">
                <span className="text-fg-muted">{t('dashboard.used')}</span>
                <span className={`font-semibold ${getDiskTextColor()}`}>{formatBytes(data.diskUsed)}</span>
              </div>
              <div
                className="h-2.5 bg-bg-sunken rounded-full overflow-hidden cursor-help"
                title={`${t('dashboard.memUsedTotal', { used: formatBytes(data.diskUsed), total: formatBytes(data.diskTotal) })}\n${t('dashboard.memPercentage', { pct: diskPercent.toFixed(1) })}`}
              >
                <div
                  className={`h-full bg-gradient-to-r ${getDiskColor()} rounded-full transition-all duration-700 ease-out`}
                  style={{ width: `${Math.min(diskPercent, 100)}%` }}
                />
              </div>
              <div className="flex justify-between text-xs text-fg-subtle">
                <span>{t('dashboard.total')} {formatBytes(data.diskTotal)}</span>
                <span className={`font-medium ${getDiskTextColor()}`}>{diskPercent.toFixed(1)}%</span>
              </div>
            </div>
            <div className="pt-2 border-t border-border/50 space-y-2.5">
              <InfoRow label={t('dashboard.free')} value={formatBytes(data.diskFree)} />
              <InfoRow label={t('dashboard.fileCount')} value={formatNumber(data.fileCount)} />
              <InfoRow label={t('dashboard.totalSize')} value={formatBytes(data.totalSize)} />
            </div>
          </CardBody>
        </Card>

        <Card className="card-hover">
          <CardHeader className="flex items-center gap-3 py-4">
            <div className="w-11 h-11 rounded-xl bg-warning/10 text-warning flex items-center justify-center">
              <Upload size={20} />
            </div>
            <div>
              <div className="text-sm font-semibold text-fg">{t('dashboard.uploadMemory')}</div>
              <div className="text-xs text-fg-subtle">{t('dashboard.uploadMemorySubtitle')}</div>
            </div>
          </CardHeader>
          <CardBody className="space-y-2.5 text-sm">
            <InfoRow label={t('dashboard.uploadLimit')} value={formatBytes(data.maxUpload)} />
            <InfoRow label={t('dashboard.postLimit')} value={formatBytes(data.maxPost)} />
            <InfoRow label={t('dashboard.memoryLimit')} value={formatBytes(data.memoryLimit)} />
            <InfoRow label={t('dashboard.rootPath')} value={<code className="text-xs bg-bg-sunken px-1.5 py-0.5 rounded">{data.rootPath}</code>} />
          </CardBody>
        </Card>
      </div>

      <Card className="stagger-3">
        <CardHeader className="flex items-center justify-between py-4">
          <div className="flex items-center gap-3">
            <div className="w-11 h-11 rounded-xl bg-info/10 text-info flex items-center justify-center">
              <Activity size={20} />
            </div>
            <div>
              <div className="text-sm font-semibold text-fg">{t('monitor.title')}</div>
              <div className="text-xs text-fg-subtle">{t('monitor.estimateHint')}</div>
            </div>
          </div>
          {monitorData && (
            <span className="text-2xs text-fg-subtle">
              {t('monitor.everyMin', { min: monitorData.config.sample_interval_min })}
            </span>
          )}
        </CardHeader>
        <CardBody>
          {monitorQuery.isLoading ? (
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              {[0, 1, 2].map((i) => (
                <div key={i} className="space-y-2 animate-pulse">
                  <div className="h-3 w-20 bg-bg-sunken rounded" />
                  <div className="h-6 w-16 bg-bg-sunken rounded" />
                  <div className="h-12 bg-bg-sunken rounded" />
                </div>
              ))}
            </div>
          ) : monitorQuery.isError ? (
            <EmptyError
              error={monitorQuery.error instanceof Error ? monitorQuery.error.message : t('common.unknownError')}
              onRetry={() => monitorQuery.refetch()}
            />
          ) : !monitorData || monitorData.history.length === 0 ? (
            <div className="py-10 text-center">
              <Activity size={28} className="mx-auto mb-3 text-fg-subtle" />
              <p className="text-sm text-fg-muted">{t('monitor.noData')}</p>
            </div>
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-5">
              <MonitorChart
                title={t('monitor.diskUsage')}
                current={`${monitorData.sample ? monitorData.sample.disk_used_pct.toFixed(1) : '—'}%`}
                threshold={t('monitor.threshold') + ' ' + monitorData.thresholds.disk_threshold_pct + '%'}
                data={monitorData.history.map((h) => h.disk_used_pct)}
                color="text-accent"
                max={100}
              />
              <MonitorChart
                title={t('monitor.inodeUsage')}
                current={`${monitorData.sample ? monitorData.sample.inode_used_pct.toFixed(1) : '—'}%`}
                threshold={t('monitor.threshold') + ' ' + monitorData.thresholds.inode_threshold_pct + '%'}
                data={monitorData.history.map((h) => h.inode_used_pct)}
                color="text-warning"
                max={100}
              />
              <MonitorChart
                title={t('monitor.panelTraffic')}
                current={formatBytes(monitorData.sample ? monitorData.sample.bandwidth_delta : 0)}
                threshold={t('monitor.estimateHint')}
                data={monitorData.history.map((h) => h.bandwidth_delta)}
                color="text-info"
              />
            </div>
          )}
        </CardBody>
      </Card>

      <Card className="stagger-3">
        <CardHeader className="flex items-center justify-between py-4">
          <div className="flex items-center gap-3">
            <div className="w-11 h-11 rounded-xl bg-fg/5 text-fg-muted flex items-center justify-center">
              <Clock size={20} />
            </div>
            <div>
              <div className="text-sm font-semibold text-fg">{t('dashboard.recentFiles')}</div>
              <div className="text-xs text-fg-subtle">{t('dashboard.recentFilesSubtitle')}</div>
            </div>
          </div>
          <Badge variant="muted">{data.recentFiles.length}{t('dashboard.fileCountBadge')}</Badge>
        </CardHeader>
        <CardBody className="p-0">
          {data.recentFiles.length === 0 ? (
            <div className="p-12 text-center">
              <div className="w-16 h-16 mx-auto mb-3 rounded-2xl bg-bg-sunken flex items-center justify-center text-fg-subtle">
                <FileText size={28} />
              </div>
              <p className="text-sm text-fg-muted">{t('dashboard.noFiles')}</p>
            </div>
          ) : (
            <ul className="divide-y divide-border/60">
              {data.recentFiles.map((f, index) => (
                <li
                  key={f.path}
                  className="flex items-center gap-3 px-5 py-3 hover:bg-fg/[0.03] transition-colors group"
                  style={{ animationDelay: `${index * 50}ms` }}
                >
                  <div className={`w-9 h-9 rounded-lg flex items-center justify-center shrink-0 ${
                    f.type === 'dir' ? 'bg-accent/10 text-accent' :
                    isImageFile(f.name) ? 'bg-purple-500/10 text-purple-500' :
                    isCodeFile(f.name) ? 'bg-info/10 text-info' :
                    'bg-bg-sunken text-fg-muted'
                  }`}>
                    {renderFileIcon(f, 16)}
                  </div>
                  <div className="flex-1 min-w-0">
                    <Link
                      to={`/files${f.path}`}
                      className="text-sm text-fg truncate block hover:text-accent transition-colors font-medium"
                    >
                      {f.name}
                    </Link>
                    <div className="text-xs text-fg-subtle mt-0.5 flex items-center gap-2">
                      <span className="truncate">{f.path}</span>
                    </div>
                  </div>
                  <div className="text-right shrink-0 hidden sm:block">
                    <div className="text-sm text-fg-muted">{formatBytes(f.size)}</div>
                    <div className="text-xs text-fg-subtle mt-0.5">{formatRelativeTime(f.mtime)}</div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>
    </div>
  )
}

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-4">
      <span className="text-fg-muted shrink-0 text-sm">{label}</span>
      <span className="text-fg truncate font-medium">{value}</span>
    </div>
  )
}

function MonitorChart({
  title,
  current,
  threshold,
  data,
  color,
  max,
}: {
  title: string
  current: string
  threshold: string
  data: number[]
  color: string
  max?: number
}) {
  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs font-medium text-fg-muted">{title}</span>
        <span className="text-2xs text-fg-subtle">{threshold}</span>
      </div>
      <div className="text-lg font-semibold text-fg leading-none">{current}</div>
      <Sparkline data={data} color={color} max={max} height={44} />
    </div>
  )
}

function isCodeFile(name: string) {
  const ext = getFileExtension(name)
  return ['php', 'js', 'ts', 'tsx', 'css', 'html', 'json', 'sql', 'py', 'sh', 'bash', 'yml', 'yaml', 'xml'].includes(ext)
}

function renderFileIcon(file: FileEntry, size: number) {
  let Icon = File
  if (file.type === 'dir') Icon = FolderOpen
  else if (isImageFile(file.name)) Icon = Image
  else if (isCodeFile(file.name)) Icon = FileCode
  else if (isTextFile(file.name)) Icon = FileText
  return <Icon size={size} />
}
