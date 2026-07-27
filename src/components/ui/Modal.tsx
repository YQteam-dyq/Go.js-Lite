import { useEffect, useRef, type ReactNode } from 'react'
import { X } from 'lucide-react'
import { Button } from './Button'
import { useIsMobile } from '@/hooks/useMediaQuery'
import { useI18n } from '@/hooks/useI18n'

interface ModalProps {
  open: boolean
  onClose: () => void
  title?: ReactNode
  children?: ReactNode
  footer?: ReactNode
  size?: 'sm' | 'md' | 'lg' | 'full'
  closeOnBackdrop?: boolean
}

export function Modal({
  open,
  onClose,
  title,
  children,
  footer,
  size = 'md',
  closeOnBackdrop = true,
}: ModalProps) {
  const isMobile = useIsMobile()
  const dialogRef = useRef<HTMLDivElement>(null)
  const { t } = useI18n()

  useEffect(() => {
    if (!open) return

    const prevOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', handler)

    return () => {
      document.body.style.overflow = prevOverflow
      window.removeEventListener('keydown', handler)
    }
  }, [open, onClose])

  if (!open) return null

  const sizeClasses = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-2xl',
    full: 'max-w-none w-full h-full rounded-none',
  }

  const fullscreen = isMobile && size !== 'full' ? 'h-[85vh] flex flex-col' : ''

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div
        className="absolute inset-0 bg-black/60 backdrop-blur-sm animate-fade-in"
        onClick={closeOnBackdrop ? onClose : undefined}
      />
      <div
        ref={dialogRef}
        className={`relative w-full ${sizeClasses[size]} bg-bg-elevated rounded-2xl shadow-2xl border border-border/50 animate-scale-in will-change-transform ${fullscreen}`}
        role="dialog"
        aria-modal="true"
      >
        {(title || !closeOnBackdrop === false) && (
          <div className="flex items-center justify-between px-5 py-4 border-b border-border shrink-0">
            <h3 className="text-base font-semibold text-fg">{title}</h3>
            <Button
              variant="ghost"
              size="icon-sm"
              onClick={onClose}
              aria-label={t('common.close')}
            >
              <X size={18} />
            </Button>
          </div>
        )}
        <div className={`px-5 py-4 ${fullscreen ? 'flex-1 overflow-auto' : ''}`}>{children}</div>
        {footer && (
          <div className="px-5 py-3 border-t border-border shrink-0 flex justify-end gap-2 flex-wrap">
            {footer}
          </div>
        )}
      </div>
    </div>
  )
}

interface ConfirmProps {
  open: boolean
  title: string
  message: ReactNode
  confirmText?: string
  cancelText?: string
  variant?: 'primary' | 'danger'
  onConfirm: () => void
  onCancel: () => void
  loading?: boolean
}

export function Confirm({
  open,
  title,
  message,
  confirmText,
  cancelText,
  variant = 'primary',
  onConfirm,
  onCancel,
  loading,
}: ConfirmProps) {
  const { t } = useI18n()
  const defaultConfirmText = variant === 'danger' ? t('common.delete') : t('common.confirm')
  const finalConfirmText = confirmText ?? defaultConfirmText
  const finalCancelText = cancelText ?? t('common.cancel')
  return (
    <Modal
      open={open}
      onClose={onCancel}
      title={title}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onCancel}>
            {finalCancelText}
          </Button>
          <Button variant={variant === 'danger' ? 'danger' : 'primary'} onClick={onConfirm} loading={loading}>
            {finalConfirmText}
          </Button>
        </>
      }
    >
      <p className="text-sm text-fg-muted leading-relaxed">{message}</p>
    </Modal>
  )
}
