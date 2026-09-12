import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ArrowUpCircle, RefreshCw, Boxes, AlertTriangle, CheckCircle2 } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/Alert'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonTable } from '@/components/ui/Skeleton'
import { phpUpgradeApi } from '@/api/phpUpgrade'
import { useI18n } from '@/hooks/useI18n'

type TabKey = 'upgrade' | 'autoload'

export default function PhpUpgrade() {
  const { t } = useI18n()
  const [tab, setTab] = useState<TabKey>('upgrade')

  const upgradeQuery = useQuery({ queryKey: ['php-upgrade-check'], queryFn: () => phpUpgradeApi.check() })
  const auditQuery = useQuery({ queryKey: ['php-autoload-audit'], queryFn: () => phpUpgradeApi.autoloadAudit() })

  const upgrade = upgradeQuery.data
  const audit = auditQuery.data

  const tabButton = (key: TabKey, label: string) => (
    <button
      key={key}
      type="button"
      onClick={() => setTab(key)}
      className={`px-3 py-1.5 rounded-lg text-sm border transition-colors ${
        tab === key ? 'border-accent bg-accent/10 text-accent' : 'border-border text-fg-muted hover:text-fg'
      }`}
    >
      {label}
    </button>
  )

  const stat = (label: string, value: React.ReactNode) => (
    <div className="px-3 py-2 rounded-lg bg-bg-sunken">
      <div className="text-[11px] text-fg-subtle">{label}</div>
      <div className="text-sm font-mono text-fg">{value}</div>
    </div>
  )

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-5xl mx-auto page-enter">
      <div className="stagger-1 flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <ArrowUpCircle size={20} className="text-accent" />
            {t('phpUpgrade.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('phpUpgrade.subtitle')}</p>
        </div>
        <Button
          variant="ghost"
          onClick={() => { upgradeQuery.refetch(); auditQuery.refetch() }}
          loading={upgradeQuery.isFetching || auditQuery.isFetching}
        >
          <RefreshCw size={16} />
          {t('common.refresh')}
        </Button>
      </div>

      <div className="stagger-2 flex flex-wrap gap-2">
        {tabButton('upgrade', t('phpUpgrade.tabUpgrade'))}
        {tabButton('autoload', t('phpUpgrade.tabAutoload'))}
      </div>

      {tab === 'upgrade' && (
        <>
          <Card className="stagger-3">
            <CardHeader>
              <div className="text-sm font-semibold text-fg">{t('phpUpgrade.versionTitle')}</div>
              {upgrade &&
                (upgrade.upgrade_needed ? (
                  <Badge variant="warning">{t('phpUpgrade.upgradeNeeded')}</Badge>
                ) : (
                  <Badge variant="success">{t('phpUpgrade.upToDate')}</Badge>
                ))}
            </CardHeader>
            <CardBody className="grid grid-cols-2 md:grid-cols-4 gap-3">
              {stat(t('phpUpgrade.current'), upgrade?.current ?? '—')}
              {stat(t('phpUpgrade.requiredMin'), upgrade?.required_min ?? '—')}
              {stat(t('phpUpgrade.requiredConstraint'), upgrade?.required_constraint ?? '—')}
              {stat(t('phpUpgrade.recommended'), upgrade?.recommended ?? '—')}
            </CardBody>
          </Card>

          <Card className="stagger-4">
            <CardHeader>
              <div className="text-sm font-semibold text-fg flex items-center gap-2">
                <AlertTriangle size={16} />
                {t('phpUpgrade.blockers')}
              </div>
              <div className="text-xs text-fg-subtle">
                {t('phpUpgrade.blockerCount')}: {upgrade?.blocker_count ?? 0}
              </div>
            </CardHeader>
            <CardBody>
              {upgradeQuery.isLoading ? (
                <SkeletonTable rows={3} columns={3} />
              ) : !upgrade || upgrade.blockers.length === 0 ? (
                <Alert variant="success">
                  <AlertTitle className="flex items-center gap-2">
                    <CheckCircle2 size={16} />
                    {t('phpUpgrade.noBlockers')}
                  </AlertTitle>
                  <AlertDescription>{t('phpUpgrade.noBlockersDesc')}</AlertDescription>
                </Alert>
              ) : (
                <div className="overflow-x-auto -mx-2">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-left text-fg-subtle border-b border-border">
                        <th className="px-3 py-2 font-medium">{t('phpUpgrade.file')}</th>
                        <th className="px-3 py-2 font-medium">{t('phpUpgrade.line')}</th>
                        <th className="px-3 py-2 font-medium">{t('phpUpgrade.message')}</th>
                        <th className="px-3 py-2 font-medium">{t('phpUpgrade.requires')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {upgrade.blockers.map((b, idx) => (
                        <tr key={`${b.file}-${b.line}-${idx}`} className="border-b border-border/40">
                          <td className="px-3 py-2 font-mono text-fg">{b.file}</td>
                          <td className="px-3 py-2 font-mono text-fg-muted">{b.line ?? '—'}</td>
                          <td className="px-3 py-2 text-fg-muted">{b.msg}</td>
                          <td className="px-3 py-2">
                            <Badge variant="warning">PHP {b.requires}+</Badge>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </CardBody>
          </Card>
        </>
      )}

      {tab === 'autoload' && (
        <Card className="stagger-3">
          <CardHeader>
            <div className="text-sm font-semibold text-fg flex items-center gap-2">
              <Boxes size={16} />
              {t('phpUpgrade.autoloadTitle')}
            </div>
            <div className="text-xs text-fg-subtle font-mono">
              {audit?.autoload_file}
              {audit && (
                <>
                  {' · '}
                  {t('phpUpgrade.registered')}: {audit.registered_count} · {t('phpUpgrade.unregistered')}:{' '}
                  {audit.unregistered_count}
                </>
              )}
            </div>
          </CardHeader>
          <CardBody>
            {auditQuery.isLoading ? (
              <SkeletonTable rows={4} columns={3} />
            ) : !audit || audit.suggestions.length === 0 ? (
              <EmptyState title={t('phpUpgrade.noSuggestions')} description={t('phpUpgrade.noSuggestionsDesc')} />
            ) : (
              <>
                <div className="text-xs text-fg-subtle mb-2">{t('phpUpgrade.auditHint')}</div>
                <div className="overflow-x-auto -mx-2">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-left text-fg-subtle border-b border-border">
                        <th className="px-3 py-2 font-medium">{t('phpUpgrade.file')}</th>
                        <th className="px-3 py-2 font-medium">{t('phpUpgrade.line')}</th>
                        <th className="px-3 py-2 font-medium">{t('phpUpgrade.statement')}</th>
                        <th className="px-3 py-2 font-medium">{t('phpUpgrade.suggestion')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {audit.suggestions.map((s, idx) => (
                        <tr key={`${s.file}-${s.line}-${idx}`} className="border-b border-border/40">
                          <td className="px-3 py-2 font-mono text-fg">{s.file}</td>
                          <td className="px-3 py-2 font-mono text-fg-muted">{s.line}</td>
                          <td className="px-3 py-2 font-mono text-xs text-fg-muted max-w-md truncate" title={s.statement}>
                            {s.statement}
                          </td>
                          <td className="px-3 py-2">
                            <Badge variant={s.suggestion === 'composer autoload' ? 'accent' : 'warning'}>
                              {s.suggestion}
                            </Badge>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}
          </CardBody>
        </Card>
      )}
    </div>
  )
}
