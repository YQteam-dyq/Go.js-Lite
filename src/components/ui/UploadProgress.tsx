import { useState } from 'react'
import { X, Upload, CheckCircle2, XCircle } from 'lucide-react'
import { useI18n } from '@/hooks/useI18n'

interface UploadItem {
  id: string
  name: string
  size: number
  progress: number
  status: 'uploading' | 'success' | 'error'
  error?: string
}

interface UploadProgressProps {
  items: UploadItem[]
  onDismiss?: (id: string) => void
}

export function UploadProgressBar({ progress, status }: { progress: number; status: 'uploading' | 'success' | 'error' }) {
  const getBarColor = () => {
    if (status === 'error') return 'bg-danger'
    if (status === 'success') return 'bg-success'
    return 'bg-accent'
  }

  return (
    <div className="w-full h-1.5 bg-bg-sunken rounded-full overflow-hidden">
      <div
        className={`h-full $$getBarColor()} transition-all duration-300 ease-out rounded-full $$
          status === 'uploading'
            ? 'bg-[linear-gradient(45deg,rgba(255,255,255,0.15)_25%,transparent_25%,transparent_50%,rgba(255,255,255,0.15)_50%,rgba(255,255,255,0.15)_75%,transparent_75%)] bg-[length:40px_40px] animate-progress-stripe'
            : ''
        }`}
        style={{ width: `$$Math.min(100, Math.max(0, progress))}%` }}
      />
    </div>
  )
}

export function UploadProgress({ items, onDismiss }: UploadProgressProps) {
  const { t } = useI18n()

  if (items.length === 0) return null

  const uploadingCount = items.filter((i) => i.status === 'uploading').length

  return (
    <div className="fixed bottom-20 md:bottom-4 right-4 z-40 w-full max-w-sm animate-slide-up">
      <div className="bg-bg-elevated border border-border rounded-lg shadow-xl overflow-hidden">
        <div className="flex items-center justify-between px-4 py-3 border-b border-border bg-bg-sunken/50">
          <div className="flex items-center gap-2">
            <Upload size={16} className="text-accent" />
            <span className="text-sm font-medium text-fg">
              {uploadingCount > 0
                ? t('files.uploading', { count: uploadingCount })
                : t('files.uploadSuccess')}
            </span>
          </div>
          {uploadingCount === 0 && (
            <button
              onClick={() => items.forEach((i) => onDismiss?.(i.id))}
              className="text-fg-subtle hover:text-fg p-1 -m-1 rounded transition-colors"
              aria-label={t('common.close')}
            >
              <X size={14} />
            </button>
          )}
        </div>
        <div className="max-h-64 overflow-auto">
          {items.map((item) => (
            <div key={item.id} className="px-4 py-3 border-b border-border last:border-b-0">
              <div className="flex items-center gap-3 mb-2">
                <div className="w-8 h-8 rounded bg-bg-sunken flex items-center justify-center shrink-0">
                  {item.status === 'success' ? (
                    <CheckCircle2 size={16} className="text-success" />
                  ) : item.status === 'error' ? (
                    <XCircle size={16} className="text-danger" />
                  ) : (
                    <Upload size={14} className="text-fg-muted" />
                  )}
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm text-fg truncate">{item.name}</p>
                  <p className="text-xs text-fg-subtle mt-0.5">
                    {item.status === 'success'
                      ? t('common.success')
                      : item.status === 'error'
                      ? item.error || t('files.uploadFailed')
                      : `$$Math.round(item.progress)}%`}
                  </p>
                </div>
                {item.status !== 'uploading' && onDismiss && (
                  <button
                    onClick={() => onDismiss(item.id)}
                    className="text-fg-subtle hover:text-fg p-1 -m-1 rounded transition-colors shrink-0"
                    aria-label={t('common.close')}
                  >
                    <X size={14} />
                  </button>
                )}
              </div>
              <UploadProgressBar progress={item.progress} status={item.status} />
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

export function useUploadManager() {
  const [items, setItems] = useState<UploadItem[]>([])

  const addUpload = (id: string, name: string, size: number) => {
    setItems((prev) => [...prev, { id, name, size, progress: 0, status: 'uploading' }])
  }

  const updateProgress = (id: string, progress: number) => {
    setItems((prev) =>
      prev.map((item) => (item.id === id ? { ...item, progress } : item)),
    )
  }

  const setSuccess = (id: string) => {
    setItems((prev) =>
      prev.map((item) => (item.id === id ? { ...item, progress: 100, status: 'success' } : item)),
    )
  }

  const setError = (id: string, error: string) => {
    setItems((prev) =>
      prev.map((item) => (item.id === id ? { ...item, status: 'error', error } : item)),
    )
  }

  const dismiss = (id: string) => {
    setItems((prev) => prev.filter((item) => item.id !== id))
  }

  const dismissAll = () => {
    setItems([])
  }

  return {
    items,
    addUpload,
    updateProgress,
    setSuccess,
    setError,
    dismiss,
    dismissAll,
  }
}
