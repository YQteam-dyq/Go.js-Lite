import { useEffect, useRef, useState } from 'react'
import { CloudOff, RefreshCw } from 'lucide-react'
import { useI18n } from '@/hooks/useI18n'

function initialOnlineState(): boolean {
  if (typeof navigator === 'undefined') return true
  return navigator.onLine !== false
}

export function OfflineBanner() {
  const { t } = useI18n()
  const [online, setOnline] = useState(initialOnlineState)
  const [showRestored, setShowRestored] = useState(false)
  const wasOffline = useRef(false)

  useEffect(() => {
    const handleOffline = () => {
      wasOffline.current = true
      setShowRestored(false)
      setOnline(false)
    }
    const handleOnline = () => {
      setOnline(true)
      if (wasOffline.current) {
        wasOffline.current = false
        setShowRestored(true)
      }
    }
    window.addEventListener('offline', handleOffline)
    window.addEventListener('online', handleOnline)
    return () => {
      window.removeEventListener('offline', handleOffline)
      window.removeEventListener('online', handleOnline)
    }
  }, [])

  useEffect(() => {
    if (!showRestored) return
    const timer = window.setTimeout(() => setShowRestored(false), 4000)
    return () => window.clearTimeout(timer)
  }, [showRestored])

  if (online && !showRestored) return null

  const offline = !online

  return (
    <div
      role="status"
      aria-live="polite"
      className={`
        shrink-0 flex items-center gap-2 px-4 py-2 text-xs
        ${offline ? 'bg-warning-soft text-fg' : 'bg-success-soft text-fg'}
      `}
    >
      {offline ? <CloudOff size={14} className="shrink-0" /> : <RefreshCw size={14} className="shrink-0" />}
      <span className="font-medium">{offline ? t('pwa.offlineTitle') : t('pwa.backOnline')}</span>
      {offline && <span className="text-fg-muted truncate">{t('pwa.offlineDescription')}</span>}
    </div>
  )
}
