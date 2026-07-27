import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Code2, Search, Server, Cpu, HardDrive } from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Spinner } from '@/components/ui/Spinner'
import { Badge } from '@/components/ui/Badge'
import { phpInfoApi } from '@/api/phpinfo'
import { useI18n } from '@/hooks/useI18n'

export default function PhpInfo() {
  const { t } = useI18n()
  const [search, setSearch] = useState('')

  const { data, isLoading, error } = useQuery({
    queryKey: ['phpinfo'],
    queryFn: () => phpInfoApi.get(),
  })

  const { data: iniData } = useQuery({
    queryKey: ['phpini', search],
    queryFn: () => phpInfoApi.getIni(search),
    enabled: !!data,
  })

  const filteredIni = iniData
    ? Object.entries(iniData).filter(([k]) =>
        k.toLowerCase().includes(search.toLowerCase()),
      )
    : data
      ? Object.entries(data.coreIni).filter(([k]) =>
          k.toLowerCase().includes(search.toLowerCase()),
        )
      : []

  return (
    <div className="p-4 md:p-6 space-y-5">
      <div>
        <h1 className="text-xl font-semibold text-fg">{t('phpinfo.title')}</h1>
        <p className="text-sm text-fg-muted mt-0.5">{t('phpinfo.subtitle')}</p>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <Spinner size="lg" />
        </div>
      ) : error ? (
        <Card className="p-6 text-center text-danger">
          {t('common.error')}：{error instanceof Error ? error.message : t('common.unknownError')}
        </Card>
      ) : data ? (
        <>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <InfoCard
              icon={<Code2 size={20} />}
              label={t('phpinfo.version')}
              value={data.version}
              accent
            />
            <InfoCard
              icon={<Server size={20} />}
              label={t('phpinfo.sapi')}
              value={data.sapi}
            />
            <InfoCard
              icon={<HardDrive size={20} />}
              label={t('phpinfo.iniFile')}
              value={data.iniFile || t('phpinfo.notFound')}
              mono
            />
            <InfoCard
              icon={<Cpu size={20} />}
              label={t('phpinfo.loadedExtensions')}
              value={`${data.loadedExtensions.length}${t('phpinfo.extensionCount')}`}
            />
          </div>

          <Card>
            <CardHeader className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-lg bg-accent/10 text-accent flex items-center justify-center">
                  <Code2 size={20} />
                </div>
                <div>
                  <div className="text-sm font-medium text-fg">{t('phpinfo.config')}</div>
                  <div className="text-xs text-fg-subtle">{t('phpinfo.configSubtitle')}</div>
                </div>
              </div>
              <div className="w-56 max-w-[50%]">
                <Input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder={t('phpinfo.searchPlaceholder')}
                  icon={<Search size={16} />}
                />
              </div>
            </CardHeader>
            <CardBody className="p-0">
              {filteredIni.length === 0 ? (
                <div className="p-8 text-center text-sm text-fg-muted">
                  {t('phpinfo.noMatches')}
                </div>
              ) : (
                <div className="max-h-[500px] overflow-auto">
                  <table className="w-full text-sm">
                    <thead className="sticky top-0 bg-bg-elevated">
                      <tr className="text-fg-muted text-left text-xs">
                        <th className="font-medium px-5 py-2.5 border-b border-border w-1/3">
                          {t('phpinfo.configItem')}
                        </th>
                        <th className="font-medium px-5 py-2.5 border-b border-border">{t('phpinfo.value')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredIni.map(([key, value]) => (
                        <tr key={key} className="border-b border-border/50 hover:bg-fg/5">
                          <td className="px-5 py-2 font-mono text-xs text-fg">{key}</td>
                          <td className="px-5 py-2 font-mono text-xs text-fg-muted">
                            <Badge variant="muted" className="font-mono">
                              {value || '—'}
                            </Badge>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-lg bg-success/10 text-success flex items-center justify-center">
                <Cpu size={20} />
              </div>
              <div>
                <div className="text-sm font-medium text-fg">{t('phpinfo.loadedExtensions')}</div>
                <div className="text-xs text-fg-subtle">{data.loadedExtensions.length}{t('phpinfo.extensionCount')}</div>
              </div>
            </CardHeader>
            <CardBody>
              <div className="flex flex-wrap gap-1.5">
                {data.loadedExtensions.map((ext) => (
                  <Badge key={ext} variant="muted">
                    {ext}
                  </Badge>
                ))}
              </div>
            </CardBody>
          </Card>
        </>
      ) : null}
    </div>
  )
}

function InfoCard({
  icon,
  label,
  value,
  accent,
  mono,
}: {
  icon: React.ReactNode
  label: string
  value: string
  accent?: boolean
  mono?: boolean
}) {
  return (
    <Card>
      <CardBody className="flex items-start gap-3">
        <div
          className={`w-10 h-10 rounded-lg flex items-center justify-center shrink-0 ${
            accent ? 'bg-accent/10 text-accent' : 'bg-bg-sunken text-fg-muted'
          }`}
        >
          {icon}
        </div>
        <div className="min-w-0">
          <div className="text-xs text-fg-subtle">{label}</div>
          <div className={`text-base font-semibold text-fg mt-0.5 truncate ${mono ? 'font-mono' : ''}`}>
            {value}
          </div>
        </div>
      </CardBody>
    </Card>
  )
}
