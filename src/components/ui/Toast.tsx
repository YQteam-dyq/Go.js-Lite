import { useEffect, useState } from 'react'
import { CheckCircle2, XCircle, Info, AlertTriangle, X } from 'lucide-react'
import { useUiStore } from '@/stores/uiStore'
import { useI18n } from '@/hooks/useI18n'

const icons = {
  success: CheckCircle2,
  error: XCircle,
  info: Info,
  warning: AlertTriangle,
}

const colorClasses = {
  success: 'text-success',
  error: 'text-danger',
  info: 'text-accent',
  warning: 'text-warning',
}

const bgClasses = {
  success: 'border-success/20',
  error: 'border-danger/20',
  info: 'border-accent/20',
  warning: 'border-warning/20',
}

export function Toaster() {
  const toasts = useUiStore((s) => s.toasts)
  const removeToast = useUiStore((s) => s.removeToast)

  return (
    <div className="fixed top-4 left-1/2 -translate-x-1/2 z-[100] flex flex-col gap-2 w-full max-w-sm pointer-events-none px-4">
      {toasts.map((toast, index) => (
        <ToastItem
          key={toast.id}
          toast={toast}
          index={index}
          onClose={() => removeToast(toast.id)}
        />
      ))}
    </div>
  )
}

function ToastItem({
  toast,
  index,
  onClose,
}: {
  toast: ReturnType<typeof useUiStore.getState>['toasts'][number]
  index: number
  onClose: () => void
}) {
  const Icon = icons[toast.type]
  const [show, setShow] = useState(false)
  const [leaving, setLeaving] = useState(false)
  const { t } = useI18n()

  useEffect(() => {
    const timer = requestAnimationFrame(() => setShow(true))
    return () => cancelAnimationFrame(timer)
  }, [])

  const handleClose = () => {
    setLeaving(true)
    setTimeout(onClose, 300)
  }

  return (
    <div
      className={`
        pointer-events-auto
        bg-bg-elevated/95 backdrop-blur-medium
        border ${bgClasses[toast.type]} rounded-xl
        shadow-xl shadow-black/10
        p-4 flex items-start gap-3
        will-change-transform
        transition-all duration-300 ease-out
        ${show && !leaving ? 'opacity-100 translate-y-0 scale-100' : 'opacity-0 -translate-y-4 scale-95'}
      `}
      style={{ animationDelay: `${index * 50}ms` }}
      role="status"
    >
      <div className={`shrink-0 w-8 h-8 rounded-lg flex items-center justify-center ${
        toast.type === 'success' ? 'bg-success/10' :
        toast.type === 'error' ? 'bg-danger/10' :
        toast.type === 'warning' ? 'bg-warning/10' :
        'bg-accent/10'
      }`}>
        <Icon size={18} className={colorClasses[toast.type]} />
      </div>
      <div className="flex-1 min-w-0 pt-0.5">
        <p className="text-sm font-semibold text-fg">{toast.title}</p>
        {toast.description && (
          <p className="text-xs text-fg-muted mt-1 leading-relaxed">{toast.description}</p>
        )}
      </div>
      <button
        onClick={handleClose}
        className="text-fg-subtle hover:text-fg p-1 -m-1 rounded-md hover:bg-bg-sunken transition-colors shrink-0"
        aria-label={t('common.close')}
      >
        <X size={16} />
      </button>
    </div>
  )
}

export function toast(params: {
  type?: 'success' | 'error' | 'info' | 'warning'
  title: string
  description?: string
  duration?: number
}) {
  return useUiStore.getState().addToast({
    type: params.type || 'info',
    title: params.title,
    description: params.description,
    duration: params.duration,
  })
}
