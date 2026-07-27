import { useState, useRef, useEffect, type ReactNode } from 'react'
import { ChevronDown } from 'lucide-react'
import { Button } from './Button'
import { useIsMobile } from '@/hooks/useMediaQuery'

interface DropdownMenuProps {
  trigger: ReactNode
  children: ReactNode
  align?: 'left' | 'right'
}

export function DropdownMenu({ trigger, children, align = 'right' }: DropdownMenuProps) {
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const handler = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [open])

  return (
    <div ref={containerRef} className="relative">
      <div onClick={() => setOpen(!open)}>{trigger}</div>
      {open && (
        <div
          className={`
            absolute z-40 mt-2 min-w-[180px] bg-bg-elevated border border-border
            rounded-lg shadow-xl py-1 animate-scale-in origin-top-right
            ${align === 'right' ? 'right-0' : 'left-0'}
          `}
          role="menu"
        >
          {children}
        </div>
      )}
    </div>
  )
}

interface MenuItemProps {
  icon?: ReactNode
  label: ReactNode
  description?: ReactNode
  onClick?: () => void
  danger?: boolean
  disabled?: boolean
}

export function MenuItem({ icon, label, description, onClick, danger, disabled }: MenuItemProps) {
  return (
    <button
      role="menuitem"
      onClick={onClick}
      disabled={disabled}
      className={`
        w-full flex items-center gap-3 px-3 py-2 text-sm text-left
        transition-colors
        ${disabled
          ? 'text-fg-subtle opacity-50 cursor-not-allowed'
          : danger
          ? 'text-danger hover:bg-danger/10'
          : 'text-fg hover:bg-bg-sunken'
        }
      `}
    >
      {icon && <span className="shrink-0">{icon}</span>}
      <span className="flex-1 min-w-0">
        <div className="truncate">{label}</div>
        {description && <div className="text-xs text-fg-subtle truncate">{description}</div>}
      </span>
    </button>
  )
}

export function MenuSeparator() {
  return <div className="my-1 h-px bg-border" />
}

interface NewDropdownProps {
  onNewFile: () => void
  onNewFolder: () => void
  t: (key: string) => string
}

export function NewDropdown({ onNewFile, onNewFolder, t }: NewDropdownProps) {
  const isMobile = useIsMobile()

  if (isMobile) {
    return (
      <Button variant="secondary" size="icon" onClick={onNewFile} aria-label={t('files.newFile')}>
        <ChevronDown size={18} />
      </Button>
    )
  }

  return (
    <DropdownMenu
      trigger={
        <Button variant="secondary" size="icon" aria-label={t('files.newFile')}>
          <ChevronDown size={18} />
        </Button>
      }
    >
      <MenuItem
        icon={<span className="w-4 h-4 flex items-center justify-center">📄</span>}
        label={t('files.newFileItem')}
        onClick={onNewFile}
      />
      <MenuItem
        icon={<span className="w-4 h-4 flex items-center justify-center">📁</span>}
        label={t('files.newFolder')}
        onClick={onNewFolder}
      />
    </DropdownMenu>
  )
}
