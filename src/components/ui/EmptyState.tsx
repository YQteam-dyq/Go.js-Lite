import type { ReactNode } from 'react'
import {
  FolderOpen,
  Search,
  Database,
  AlertCircle,
  FileText,
  Package,
} from 'lucide-react'
import { Button } from './Button'
import { useI18n } from '@/hooks/useI18n'

interface EmptyStateProps {
  icon?: ReactNode
  title: string
  description?: string
  action?: {
    label: string
    onClick: () => void
    variant?: 'primary' | 'secondary'
    icon?: ReactNode
  }
  className?: string
}

export function EmptyState({
  icon,
  title,
  description,
  action,
  className = '',
}: EmptyStateProps) {
  const defaultIcon = (
    <div className="w-16 h-16 rounded-full bg-bg-sunken flex items-center justify-center text-fg-subtle">
      <FileText size={28} />
    </div>
  )

  return (
    <div className={`py-12 px-4 text-center ${className}`}>
      <div className="mx-auto mb-4">{icon || defaultIcon}</div>
      <p className="text-sm font-medium text-fg mb-1">{title}</p>
      {description && (
        <p className="text-xs text-fg-subtle max-w-xs mx-auto">{description}</p>
      )}
      {action && (
        <Button
          variant={action.variant || 'secondary'}
          size="sm"
          className="mt-4"
          onClick={action.onClick}
        >
          {action.icon && <span>{action.icon}</span>}
          {action.label}
        </Button>
      )}
    </div>
  )
}

interface EmptyFolderProps {
  onUpload?: () => void
  className?: string
}

export function EmptyFolder({ onUpload, className = '' }: EmptyFolderProps) {
  const { t } = useI18n()

  return (
    <EmptyState
      className={className}
      icon={
        <div className="w-20 h-20 rounded-2xl bg-accent/10 text-accent flex items-center justify-center mx-auto">
          <FolderOpen size={36} />
        </div>
      }
      title={t('files.empty')}
      description={t('files.emptyHint')}
      action={
        onUpload
          ? {
              label: t('files.uploadFiles'),
              onClick: onUpload,
              variant: 'primary',
              icon: undefined,
            }
          : undefined
      }
    />
  )
}

interface EmptySearchProps {
  query?: string
  className?: string
}

export function EmptySearch({ query, className = '' }: EmptySearchProps) {
  const { t } = useI18n()

  return (
    <EmptyState
      className={className}
      icon={
        <div className="w-16 h-16 rounded-full bg-bg-sunken flex items-center justify-center text-fg-subtle mx-auto">
          <Search size={28} />
        </div>
      }
      title={t('files.noResults')}
      description={query ? `「${query}」${t('files.noResultsHint')}` : t('files.noResultsHint')}
    />
  )
}

interface EmptyDatabaseProps {
  onAdd?: () => void
  className?: string
}

export function EmptyDatabase({ onAdd, className = '' }: EmptyDatabaseProps) {
  const { t } = useI18n()

  return (
    <EmptyState
      className={className}
      icon={
        <div className="w-16 h-16 rounded-full bg-info/10 text-info flex items-center justify-center mx-auto">
          <Database size={28} />
        </div>
      }
      title={t('db.noConnections')}
      description={t('db.addFirst')}
      action={
        onAdd
          ? {
              label: t('db.addConnection'),
              onClick: onAdd,
              variant: 'primary',
              icon: undefined,
            }
          : undefined
      }
    />
  )
}

interface EmptyErrorProps {
  error?: string
  onRetry?: () => void
  className?: string
}

export function EmptyError({ error, onRetry, className = '' }: EmptyErrorProps) {
  const { t } = useI18n()

  return (
    <EmptyState
      className={className}
      icon={
        <div className="w-16 h-16 rounded-full bg-danger/10 text-danger flex items-center justify-center mx-auto">
          <AlertCircle size={28} />
        </div>
      }
      title={t('common.error')}
      description={error || t('common.unknownError')}
      action={
        onRetry
          ? {
              label: t('common.retry'),
              onClick: onRetry,
              variant: 'secondary',
              icon: undefined,
            }
          : undefined
      }
    />
  )
}

interface EmptyDataProps {
  icon?: ReactNode
  title: string
  description?: string
  className?: string
}

export function EmptyData({ icon, title, description, className = '' }: EmptyDataProps) {
  return (
    <EmptyState
      className={className}
      icon={
        icon || (
          <div className="w-16 h-16 rounded-full bg-bg-sunken flex items-center justify-center text-fg-subtle mx-auto">
            <Package size={28} />
          </div>
        )
      }
      title={title}
      description={description}
    />
  )
}
