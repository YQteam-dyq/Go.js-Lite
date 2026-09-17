import { useCallback, useEffect, useState } from 'react'
import { BellOff, BellRing } from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import {
  getPushSupport,
  subscribeToPush,
  type PushFallbackReason,
  type PushSupport,
} from '@/lib/pwa'
import { useI18n } from '@/hooks/useI18n'

const REASON_KEYS: Record<PushFallbackReason, string> = {
  unsupported: 'pwa.pushUnsupported',
  insecure_context: 'pwa.pushInsecureContext',
  no_service_worker: 'pwa.pushNoServiceWorker',
  no_push_manager: 'pwa.pushNoPushManager',
  permission_denied: 'pwa.pushPermissionDenied',
  subscribe_failed: 'pwa.pushSubscribeFailed',
  no_application_server_key: 'pwa.pushNoKey',
}

export function PushStatusCard() {
  const { t } = useI18n()
  const [support, setSupport] = useState<PushSupport>({ supported: false, permission: 'unsupported' })
  const [enabled, setEnabled] = useState(false)
  const [reason, setReason] = useState<PushFallbackReason | null>(null)
  const [pending, setPending] = useState(false)

  useEffect(() => {
    setSupport(getPushSupport())
  }, [])

  const handleEnable = useCallback(async () => {
    setPending(true)
    const result = await subscribeToPush()
    setPending(false)
    const next = getPushSupport()
    setSupport(next)
    if (result.ok) {
      setEnabled(true)
      setReason(null)
      return
    }
    setEnabled(false)
    setReason(result.reason ?? 'subscribe_failed')
  }, [])

  const fallbackReason = reason ?? (support.supported ? null : support.reason ?? 'unsupported')
  const active = enabled || support.permission === 'granted'

  return (
    <Card className="stagger-2 card-hover">
      <CardHeader>
        <div className="text-sm font-semibold text-fg flex items-center gap-2">
          {active ? <BellRing size={16} className="text-accent" /> : <BellOff size={16} />}
          {t('pwa.pushSectionTitle')}
        </div>
        <div className="text-xs text-fg-subtle">
          {t('pwa.pushPermissionLabel', { permission: support.permission })}
        </div>
      </CardHeader>
      <CardBody className="space-y-3">
        <p className="text-sm text-fg-muted">
          {reason
            ? t(REASON_KEYS[reason])
            : support.supported
              ? t('pwa.pushAvailable')
              : t(REASON_KEYS[fallbackReason ?? 'unsupported'])}
        </p>
        <div className="flex items-center gap-3">
          <Badge variant={active ? 'success' : support.supported ? 'muted' : 'warning'}>
            {active ? t('pwa.pushEnabled') : support.supported ? t('pwa.pushReady') : t('pwa.pushFallback')}
          </Badge>
          {support.supported && !enabled && (
            <Button variant="secondary" size="sm" onClick={handleEnable} loading={pending}>
              {t('pwa.enablePush')}
            </Button>
          )}
        </div>
      </CardBody>
    </Card>
  )
}
