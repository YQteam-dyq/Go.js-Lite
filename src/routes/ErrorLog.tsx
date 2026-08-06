import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, RefreshCw, Trash2, Bug, Search } from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Confirm } from '@/components/ui/Modal'
import { errorLogApi } from '@/api/errorLog'
import { toast } from '@/components/ui/Toast'
import { formatBytes } from '@/lib/format'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'
import type { ErrorLogEntry } from '@shared/types'

export default function ErrorLog() {
  const queryClient = useQueryClient()
  const { t } = useI18n()
  const [filter, setFilter] = useState('')
  const [showClear, setShowClear] = useState(false)
  const [clearing, setClearing] = useState(false)

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['error-log'],
    queryFn: () => errorLogApi.get(100),
  })

  const filteredEntries = data?.entries.filter((e) =>
    filter
      ? e.message.toLowerCase().includes(filter.toLowerCase())
      : true,
  ) || []

  const handleClear = async () => {
    setClearing(true)
    try {
      await errorLogApi.clear()
      toast({ type: 'success', title: t('errorLog.cleared') })
      setShowClear(false)
      queryClient.invalidateQueries({ queryKey: ['error-log'] })
    } catch (err) {
      toast({
        type: 'error',
        title: t('errorLog.clearFailed'),
        description: err instanceof Error ? resolveErrorText(err) : t('common.unknownError'),
      })
    } finally {
      setClearing(false)
    }
  }

  const getTypeColor = (type: ErrorLogEntry['type']) => {
    switch (type) {
      case 'fatal':
        return 'bg-danger/10 text-danger border-danger/20'
      case 'warning':
        return 'bg-warning/10 text-warning border-warning/20'
      case 'notice':
        return 'bg-info/10 text-info border-info/20'
      case 'deprecated':
        return 'bg-fg-muted/10 text-fg-muted border-fg-muted/20'
      default:
        return 'bg-accent/10 text-accent border-accent/20'
    }
  }

  return (
    <div className="p-4 md:p-6 space-y-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <Bug size={22} className="text-danger" />
            {t('errorLog.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">
            {t('errorLog.subtitle')}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => refetch()}
            disabled={isLoading}
          >
            <RefreshCw size={16} className={isLoading ? 'animate-spin' : ''} />
            {t('errorLog.refresh')}
          </Button>
          <Button
            variant="secondary"
            size="sm"
            onClick={() => setShowClear(true)}
            disabled={isLoading || !data?.found}
          >
            <Trash2 size={16} />
            {t('errorLog.clear')}
          </Button>
        </div>
      </div>

      {data?.found && (
        <div className="flex items-center justify-between text-xs text-fg-muted">
          <span>
            {t('errorLog.path')}: <code className="bg-bg-sunken px-1.5 py-0.5 rounded">{data.path}</code>
          </span>
          {data.size !== undefined && (
            <span>{t('errorLog.size')}: {formatBytes(data.size)}</span>
          )}
        </div>
      )}

      <Card className="card-hover">
        <CardHeader>
          <div className="w-full max-w-md">
            <Input
              placeholder={t('errorLog.searchPlaceholder')}
              value={filter}
              onChange={(e) => setFilter(e.target.value)}
              icon={<Search size={16} />}
            />
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
                {resolveErrorText(error) || t('common.error')}
              </p>
            </div>
          ) : !data?.found ? (
            <div className="p-12 text-center text-fg-muted">
              <Bug size={32} className="mx-auto mb-3 opacity-30" />
              <p className="text-sm font-medium">{t('errorLog.notFound')}</p>
              <p className="text-xs mt-1">
                {t('errorLog.notFoundHint')}
              </p>
              <p className="text-xs mt-2 text-fg-subtle">
                {t('errorLog.defaultPathHint')}
              </p>
            </div>
          ) : filteredEntries.length === 0 ? (
            <div className="p-12 text-center text-fg-muted">
              <Bug size={32} className="mx-auto mb-3 opacity-30" />
              <p className="text-sm font-medium">{t('errorLog.noMatches')}</p>
              <p className="text-xs mt-1">
                {filter ? t('errorLog.noMatchesHint') : t('errorLog.allGood')}
              </p>
            </div>
          ) : (
            <div className="max-h-[600px] overflow-auto">
              <ul className="divide-y divide-border">
                {filteredEntries.map((entry, i) => (
                  <li key={i} className="p-3 hover:bg-fg/5">
                    <div className="flex items-start gap-3">
                      <Badge
                        variant="muted"
                        className={`shrink-0 mt-0.5 border ${getTypeColor(entry.type)}`}
                      >
                        {entry.type.toUpperCase()}
                      </Badge>
                      <code className="text-xs text-fg break-all font-mono leading-relaxed">
                        {entry.message}
                      </code>
                    </div>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </CardBody>
      </Card>

      <Confirm
        open={showClear}
        onCancel={() => setShowClear(false)}
        title={t('errorLog.clearTitle')}
        message={t('errorLog.clearConfirm')}
        confirmText={t('errorLog.clearConfirmBtn')}
        variant="danger"
        loading={clearing}
        onConfirm={handleClear}
      />
    </div>
  )
}
