import { useEffect, type ReactNode } from 'react'
import { X } from 'lucide-react'
import { Button } from './Button'

interface BottomSheetProps {
  open: boolean
  onClose: () => void
  title?: ReactNode
  children: ReactNode
}

export function BottomSheet({ open, onClose, title, children }: BottomSheetProps) {
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

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center">
      <div
        className="absolute inset-0 bg-black/50 backdrop-blur-sm animate-fade-in"
        onClick={onClose}
      />
      <div className="relative w-full bg-bg-elevated rounded-t-2xl shadow-2xl border-t border-border animate-slide-up max-h-[85vh] flex flex-col safe-bottom">
        <div className="flex justify-center pt-2 pb-1">
          <div className="w-10 h-1 rounded-full bg-border" />
        </div>
        {title && (
          <div className="flex items-center justify-between px-5 py-3 border-b border-border shrink-0">
            <h3 className="text-base font-semibold text-fg">{title}</h3>
            <Button variant="ghost" size="icon-sm" onClick={onClose} aria-label="Close">
              <X size={18} />
            </Button>
          </div>
        )}
        <div className="flex-1 overflow-auto py-2">{children}</div>
      </div>
    </div>
  )
}

interface ActionSheetItemProps {
  icon?: ReactNode
  label: ReactNode
  onClick?: () => void
  danger?: boolean
  disabled?: boolean
}

export function ActionSheetItem({ icon, label, onClick, danger, disabled }: ActionSheetItemProps) {
  return (
    <button
      onClick={onClick}
      disabled={disabled}
      className={`
        w-full flex items-center gap-4 px-5 py-3.5 text-base text-left
        transition-colors min-h-[56px]
        $$disabled
          ? 'text-fg-subtle opacity-50 cursor-not-allowed'
          : danger
          ? 'text-danger hover:bg-danger/10 active:bg-danger/15'
          : 'text-fg hover:bg-bg-sunken active:bg-bg-sunken'
        }
      `}
    >
      {icon && <span className="shrink-0 w-6 h-6 flex items-center justify-center">{icon}</span>}
      <span className="flex-1">{label}</span>
    </button>
  )
}

export function ActionSheetSeparator() {
  return <div className="mx-4 my-1 h-px bg-border" />
}
