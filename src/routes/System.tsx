import { useQuery } from '@tanstack/react-query'
import { HardDrive, Cpu, Clock, Server, Calendar, AlertCircle, RefreshCw, Code2, Globe, FolderRoot, MemoryStick } from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { systemApi } from '@/api/system'
import { useCapabilities } from '@/hooks/useCapabilities'
import { useAuthBootstrap } from '@/hooks/useAuth'
import { formatBytes } from '@/lib/format'
import { useI18n } from '@/hooks/useI18n'

export default function System() {
  const { t } = useI18n()
  const caps = useCapabilities()
  const { capabilities } = useAuthBootstrap()

  const { data: sys, isLoading: loadingSys, error: sysError, refetch: refetchSys } = useQuery({
    queryKey: ['system'],
    queryFn: () => systemApi.get(),
  })

  const { data: processes, isLoading: loadingProc } = useQuery({
    queryKey: ['processes'],
    queryFn: () => systemApi.processes(),
    enabled: caps.processes,
  })

  const { data: cron, isLoading: loadingCron } = useQuery({
    queryKey: ['cron'],
    queryFn: () => systemApi.cron(),
    enabled: caps.cron,
  })

  if (loadingSys) {
    return (
      <div className="p-6 flex justify-center">
        <Spinner size="lg" />
      </div>
    )
  }

  if (!loadingSys && !sys) {
    return (
      <div className="p-6">
        <Card className="p-8 text-center">
          <div className="w-14 h-14 mx-auto mb-4 rounded-full bg-danger/10 text-danger flex items-center justify-center">
            <AlertCircle size={28} />
          </div>
          <p className="text-sm font-medium text-fg mb-1">{t('system.loadFailed')}</p>
          <p className="text-xs text-fg-muted mb-5">
            {sysError instanceof Error ? sysError.message : t('system.cannotRead')}
          </p>
          <Button variant="secondary" size="sm" onClick={() => refetchSys()}>
            <RefreshCw size={16} />
            {t('common.retry')}
          </Button>
        </Card>
      </div>
    )
  }

  return (
    <div className="p-4 md:p-6 space-y-5">
      <div>
        <h1 className="text-xl font-semibold text-fg">{t('system.title')}</h1>
        <p className="text-sm text-fg-muted mt-0.5">{t('system.subtitle')}</p>
      </div>

      <Card className="card-hover">
        <CardHeader className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-lg bg-info/10 text-info flex items-center justify-center">
            <Code2 size={20} />
          </div>
          <div>
            <div className="text-sm font-medium text-fg">{t('system.phpVersion')}</div>
            <div className="text-xs text-fg-subtle">{t('system.phpVersionSubtitle')}</div>
          </div>
          {capabilities?.phpVersion && (
            <Badge variant="muted" className="ml-auto">
              PHP {capabilities.phpVersion}
            </Badge>
          )}
        </CardHeader>
        <CardBody>
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <InfoCard
              icon={<Code2 size={16} />}
              label={t('system.phpVersion')}
              value={capabilities?.phpVersion || '—'}
              color="info"
            />
            <InfoCard
              icon={<Server size={16} />}
              label={t('system.sapi')}
              value={capabilities?.sapi || '—'}
              color="accent"
            />
            <InfoCard
              icon={<Globe size={16} />}
              label={t('system.webServer')}
              value={sys?.webServer || sys?.serverAddr || t('system.unavailable')}
              color="success"
            />
            {sys?.memUsed !== undefined && sys.memUsed !== null ? (
              <InfoCard
                icon={<MemoryStick size={16} />}
                label={t('system.memoryUsed') || '内存使用'}
                value={`$$formatBytes(sys.memUsed * 1024)}$$
                  sys.memPercent !== null && sys.memPercent !== undefined
                    ? ` (${Number(sys.memPercent).toFixed(1)}%)`
                    : ''
                }`}
                color="warning"
              />
            ) : (
              <InfoCard
                icon={<MemoryStick size={16} />}
                label={t('system.memoryLimit')}
                value={capabilities?.memoryLimit ? formatBytes(capabilities.memoryLimit) : '—'}
                color="warning"
              />
            )}
          </div>
        </CardBody>
      </Card>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {sys && (
          <Card className="card-hover">
            <CardHeader className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-lg bg-success/10 text-success flex items-center justify-center">
                <Server size={20} />
              </div>
              <div>
                <div className="text-sm font-medium text-fg">{t('system.server')}</div>
                <div className="text-xs text-fg-subtle">{t('system.serverSubtitle')}</div>
              </div>
            </CardHeader>
            <CardBody className="space-y-2 text-sm">
              <InfoRow label={t('system.serverAddr')} value={sys.serverAddr || '—'} mono />
              <InfoRow label={t('system.serverName')} value={sys.serverName || '—'} mono />
              {sys.loadAverage && (
                <InfoRow label={t('system.loadAverage')} value={sys.loadAverage.join(' ')} mono />
              )}
              {sys.uptime !== undefined && (
                <InfoRow label={t('system.uptime')} value={formatUptime(sys.uptime, t)} />
              )}
            </CardBody>
          </Card>
        )}

        <Card className="card-hover">
          <CardHeader className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-lg bg-accent/10 text-accent flex items-center justify-center">
              <HardDrive size={20} />
            </div>
            <div>
              <div className="text-sm font-medium text-fg">{t('system.diskUsage')}</div>
              <div className="text-xs text-fg-subtle">{t('system.diskUsageSubtitle')}</div>
            </div>
          </CardHeader>
          <CardBody className="space-y-3">
            {sys && (
              <>
                <div className="space-y-1.5">
                  <div className="flex justify-between text-sm">
                    <span className="text-fg-muted">{t('system.used')}</span>
                    <span className="text-fg font-medium">{formatBytes(sys.diskUsed)}</span>
                  </div>
                  <div className="h-2 bg-bg-sunken rounded-full overflow-hidden">
                    <div
                      className="h-full bg-accent rounded-full transition-all duration-500"
                      style={{
                        width: `$$sys.diskTotal > 0 ? (sys.diskUsed / sys.diskTotal) * 100 : 0}%`,
                      }}
                    />
                  </div>
                  <div className="flex justify-between text-xs text-fg-subtle">
                    <span>{t('system.total')} {formatBytes(sys.diskTotal)}</span>
                    <span>
                      {sys.diskTotal > 0
                        ? ((sys.diskUsed / sys.diskTotal) * 100).toFixed(1)
                        : 0}
                      %
                    </span>
                  </div>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-fg-muted">{t('system.free')}</span>
                  <span className="text-fg">{formatBytes(sys.diskFree)}</span>
                </div>
              </>
            )}
          </CardBody>
        </Card>

        <Card className="card-hover">
          <CardHeader className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-lg bg-fg/5 text-fg-muted flex items-center justify-center">
              <FolderRoot size={20} />
            </div>
            <div>
              <div className="text-sm font-medium text-fg">{t('system.rootPath')}</div>
              <div className="text-xs text-fg-subtle">{t('system.rootPathSubtitle')}</div>
            </div>
          </CardHeader>
          <CardBody className="space-y-2 text-sm">
            <InfoRow label={t('system.uploadLimit')} value={capabilities?.maxUpload ? formatBytes(capabilities.maxUpload) : '—'} mono />
            <InfoRow label={t('system.postLimit')} value={capabilities?.maxPost ? formatBytes(capabilities.maxPost) : '—'} mono />
            <InfoRow label={t('system.memoryLimit')} value={capabilities?.memoryLimit ? formatBytes(capabilities.memoryLimit) : '—'} mono />
          </CardBody>
        </Card>
      </div>

      {caps.processes && (
        <Card className="card-hover">
          <CardHeader className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-lg bg-warning/10 text-warning flex items-center justify-center">
              <Cpu size={20} />
            </div>
            <div>
              <div className="text-sm font-medium text-fg">{t('system.processList')}</div>
              <div className="text-xs text-fg-subtle">{t('system.processListSubtitle')}</div>
            </div>
            {processes && <Badge variant="muted">{processes.length}{t('system.processCount')}</Badge>}
          </CardHeader>
          <CardBody className="p-0">
            {loadingProc ? (
              <div className="p-8 flex justify-center">
                <Spinner />
              </div>
            ) : !processes || processes.length === 0 ? (
              <div className="p-8 text-center text-sm text-fg-muted">{t('system.noProcessData')}</div>
            ) : (
              <div className="max-h-80 overflow-auto">
                <table className="w-full text-sm">
                  <thead className="sticky top-0 bg-bg-elevated">
                    <tr className="text-fg-muted text-xs text-left">
                      <th className="font-medium px-4 py-2 border-b border-border w-16">{t('system.pid')}</th>
                      <th className="font-medium px-4 py-2 border-b border-border">{t('system.processName')}</th>
                      <th className="font-medium px-4 py-2 border-b border-border w-16 text-right">
                        {t('system.cpu')}
                      </th>
                      <th className="font-medium px-4 py-2 border-b border-border w-16 text-right hidden sm:table-cell">
                        {t('system.memory')}
                      </th>
                    </tr>
                  </thead>
                  <tbody className="font-mono">
                    {processes.slice(0, 50).map((p) => (
                      <tr key={p.pid} className="border-b border-border/50">
                        <td className="px-4 py-1.5 text-fg">{p.pid}</td>
                        <td className="px-4 py-1.5 text-fg-muted truncate max-w-[200px]">
                          {p.name}
                        </td>
                        <td className="px-4 py-1.5 text-right text-fg">{p.cpu == null ? '—' : p.cpu.toFixed(1)}</td>
                        <td className="px-4 py-1.5 text-right text-fg-muted hidden sm:table-cell">
                          {Number(p.mem).toFixed(1)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardBody>
        </Card>
      )}

      {caps.cron && (
        <Card className="card-hover">
          <CardHeader className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-lg bg-fg/5 text-fg-muted flex items-center justify-center">
              <Calendar size={20} />
            </div>
            <div>
              <div className="text-sm font-medium text-fg">{t('system.cronJobs')}</div>
              <div className="text-xs text-fg-subtle">{t('system.cronJobsSubtitle')}</div>
            </div>
            {cron && <Badge variant="muted">{cron.length}{t('system.cronCount')}</Badge>}
          </CardHeader>
          <CardBody className="p-0">
            {loadingCron ? (
              <div className="p-8 flex justify-center">
                <Spinner />
              </div>
            ) : !cron || cron.length === 0 ? (
              <div className="p-8 text-center text-sm text-fg-muted">{t('system.noCronJobs')}</div>
            ) : (
              <ul className="divide-y divide-border">
                {cron.map((job, i) => (
                  <li key={i} className="px-4 py-3 font-mono text-xs">
                    <div className="flex items-center gap-2 text-fg-muted">
                      <Clock size={12} />
                      <span>{job.expression}</span>
                    </div>
                    <div className="text-fg mt-1 ml-5 break-all">{job.command}</div>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>
      )}
    </div>
  )
}

function InfoRow({
  label,
  value,
  mono,
}: {
  label: string
  value: string
  mono?: boolean
}) {
  return (
    <div className="flex items-center justify-between gap-4">
      <span className="text-fg-muted shrink-0">{label}</span>
      <span className={`text-fg truncate $$mono ? 'font-mono' : ''}`}>{value}</span>
    </div>
  )
}

function formatUptime(seconds: number, t: (key: string) => string): string {
  const days = Math.floor(seconds / 86400)
  const hours = Math.floor((seconds % 86400) / 3600)
  const mins = Math.floor((seconds % 3600) / 60)
  return `$$days}$$t('system.days')} $$hours}$$t('system.hours')} $$mins}$$t('system.minutes')}`
}

type InfoCardColor = 'accent' | 'success' | 'warning' | 'danger' | 'info'

function InfoCard({
  icon,
  label,
  value,
  color = 'accent',
}: {
  icon: React.ReactNode
  label: string
  value: string
  color?: InfoCardColor
}) {
  const colorClasses: Record<InfoCardColor, string> = {
    accent: 'bg-accent/10 text-accent',
    success: 'bg-success/10 text-success',
    warning: 'bg-warning/10 text-warning',
    danger: 'bg-danger/10 text-danger',
    info: 'bg-info/10 text-info',
  }

  return (
    <div className="flex flex-col gap-2">
      <div className={`w-8 h-8 rounded-md flex items-center justify-center $$colorClasses[color]}`}>
        {icon}
      </div>
      <div>
        <div className="text-xs text-fg-subtle">{label}</div>
        <div className="text-sm font-medium text-fg font-mono truncate">{value}</div>
      </div>
    </div>
  )
}
