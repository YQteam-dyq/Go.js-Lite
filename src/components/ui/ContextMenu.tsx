import { useEffect, useRef } from 'react'
import {
  FolderOpen,
  FileText,
  Edit3,
  Copy,
  MoveRight,
  Download,
  Trash2,
  Shield,
} from 'lucide-react'
import type { FileEntry } from '@shared/types'

interface ContextMenuProps {
  x: number
  y: number
  onClose: () => void
  file: FileEntry
  onOpen: () => void
  onRename: () => void
  onCopy: () => void
  onMove: () => void
  onDownload: () => void
  onDelete: () => void
  onPermissions: () => void
  t: (key: string) => string
}

export function ContextMenu({
  x,
  y,
  onClose,
  file,
  onOpen,
  onRename,
  onCopy,
  onMove,
  onDownload,
  onDelete,
  onPermissions,
  t,
}: ContextMenuProps) {
  const menuRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        onClose()
      }
    }
    const escHandler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('mousedown', handler)
    document.addEventListener('keydown', escHandler)
    return () => {
      document.removeEventListener('mousedown', handler)
      document.removeEventListener('keydown', escHandler)
    }
  }, [onClose])

  const adjustedX = Math.min(x, window.innerWidth - 220)
  const adjustedY = Math.min(y, window.innerHeight - 320)

  return (
    <div
      ref={menuRef}
      className="fixed z-50 min-w-[200px] bg-bg-elevated border border-border rounded-lg shadow-xl py-1 animate-scale-in origin-top-left"
      style={{ left: adjustedX, top: adjustedY }}
      role="menu"
    >
      <button
        className="w-full flex items-center gap-3 px-3 py-2 text-sm text-fg hover:bg-bg-sunken transition-colors text-left"
        onClick={() => {
          onOpen()
          onClose()
        }}
        role="menuitem"
      >
        {file.type === 'dir' ? <FolderOpen size={16} /> : <FileText size={16} />}
        <span>{t('files.open')}</span>
      </button>

      <div className="my-1 h-px bg-border" />

      <button
        className="w-full flex items-center gap-3 px-3 py-2 text-sm text-fg hover:bg-bg-sunken transition-colors text-left"
        onClick={() => {
          onRename()
          onClose()
        }}
        role="menuitem"
      >
        <Edit3 size={16} />
        <span>{t('files.rename')}</span>
      </button>

      <button
        className="w-full flex items-center gap-3 px-3 py-2 text-sm text-fg hover:bg-bg-sunken transition-colors text-left"
        onClick={() => {
          onCopy()
          onClose()
        }}
        role="menuitem"
      >
        <Copy size={16} />
        <span>{t('files.copy')}</span>
      </button>

      <button
        className="w-full flex items-center gap-3 px-3 py-2 text-sm text-fg hover:bg-bg-sunken transition-colors text-left"
        onClick={() => {
          onMove()
          onClose()
        }}
        role="menuitem"
      >
        <MoveRight size={16} />
        <span>{t('files.move')}</span>
      </button>

      <div className="my-1 h-px bg-border" />

      <button
        className="w-full flex items-center gap-3 px-3 py-2 text-sm text-fg hover:bg-bg-sunken transition-colors text-left"
        onClick={() => {
          onDownload()
          onClose()
        }}
        role="menuitem"
      >
        <Download size={16} />
        <span>{t('files.download')}</span>
      </button>

      <button
        className="w-full flex items-center gap-3 px-3 py-2 text-sm text-fg hover:bg-bg-sunken transition-colors text-left"
        onClick={() => {
          onPermissions()
          onClose()
        }}
        role="menuitem"
      >
        <Shield size={16} />
        <span>{t('files.permissions')}</span>
      </button>

      <div className="my-1 h-px bg-border" />

      <button
        className="w-full flex items-center gap-3 px-3 py-2 text-sm text-danger hover:bg-danger/10 transition-colors text-left"
        onClick={() => {
          onDelete()
          onClose()
        }}
        role="menuitem"
      >
        <Trash2 size={16} />
        <span>{t('files.delete')}</span>
      </button>
    </div>
  )
}
