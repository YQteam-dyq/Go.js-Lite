import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { FolderOpen, FileText, RotateCcw, Trash2 } from 'lucide-react'
import { Modal, Confirm } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/EmptyState'
import { Spinner } from '@/components/ui/Spinner'
import { toast } from '@/components/ui/Toast'
import { trashApi } from '@/api/trash'
import { ApiError } from '@/api/client'
import { useFormat } from '@/lib/format'
import { useI18n } from '@/hooks/useI18n'

interface TrashModalProps {
  open: boolean
  onClose: () => void
  onChanged?: () => void
}

export function TrashModal({ open, onClose, onChanged }: TrashModalProps) {
  const { t } = useI18n()
  const { formatBytes, formatRelativeTime } = useFormat()
  const queryClient = useQueryClient()
  const [purgeAllConfirm, setPurgeAllConfirm] = useState(false)
  const [busyId, setBusyId] = useState<string | null>(null)
  const [purgeBusy, setPurgeBusy] = useState(false)

  const { data, isLoading, refetch } = useQuery({
    queryKey: ['trash-list'],
    queryFn: () => trashApi.list(),
    enabled: open,
  })

  const enabled = data?.enabled ?? true
  const items = data?.items ?? []

  const handleChanged = () => {
    queryClient.invalidateQueries({ queryKey: ['trash-list'] })
    onChanged?.()
  }

  const toggleEnabled = async () => {
    const next = !enabled
    try {
      await trashApi.setConfig(next)
      toast({
        type: 'success',
        title: next ? t('trash.enabled') : t('trash.disabled'),
      })
      refetch()
    } catch (err) {
      toast({
        type: 'error',
        title: t('common.unknownError'),
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  const handleRestore = async (id: string) => {
    setBusyId(id)
    try {
      await trashApi.restore(id)
      toast({ type: 'success', title: t('trash.restored') })
      handleChanged()
    } catch (err) {
      const code = err instanceof ApiError ? err.code : ''
      toast({
        type: 'error',
        title: code === 'restore_conflict' ? t('trash.conflict') : t('trash.restoreFailed'),
        description: err instanceof Error ? err.message : undefined,
      })
    } finally {
      setBusyId(null)
    }
  }

  const handlePurgeItem = async (id: string) => {
    setBusyId(id)
    try {
      await trashApi.purge(id)
      toast({ type: 'success', title: t('trash.purged') })
      handleChanged()
    } catch (err) {
      toast({
        type: 'error',
        title: t('trash.purgeFailed'),
        description: err instanceof Error ? err.message : undefined,
      })
    } finally {
      setBusyId(null)
    }
  }

  const handlePurgeAll = async () => {
    setPurgeBusy(true)
    try {
      await trashApi.purge()
      toast({ type: 'success', title: t('trash.purged') })
      setPurgeAllConfirm(false)
      handleChanged()
    } catch (err) {
      toast({
        type: 'error',
        title: t('trash.purgeFailed'),
        description: err instanceof Error ? err.message : undefined,
      })
    } finally {
      setPurgeBusy(false)
    }
  }

  return (
    <>
      <Modal
        open={open}
        onClose={onClose}
        title={t('trash.title')}
        size="lg"
        footer={
          items.length > 0 ? (
            <Button variant="danger" onClick={() => setPurgeAllConfirm(true)}>
              <Trash2 size={15} />
              {t('trash.purgeAll')}
            </Button>
          ) : undefined
        }
      >
        {}
        <div className="flex items-center justify-between py-2.5 px-1 border-b border-border mb-2">
          <span className="text-sm text-fg">{t('trash.enable')}</span>
          <button
            type="button"
            onClick={toggleEnabled}
            className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none $$
              enabled ? 'bg-accent' : 'bg-fg/15'
            }`}
            role="switch"
            aria-checked={enabled}
            aria-label={t('trash.enable')}
          >
            <span
              className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition-transform $$
                enabled ? 'translate-x-5' : 'translate-x-0'
              }`}
            />
          </button>
        </div>

        {isLoading ? (
          <div className="py-16 flex items-center justify-center">
            <Spinner size="lg" />
          </div>
        ) : items.length === 0 ? (
          <EmptyState
            icon={
              <div className="w-16 h-16 rounded-full bg-bg-sunken flex items-center justify-center text-fg-subtle mx-auto">
                <Trash2 size={28} />
              </div>
            }
            title={t('trash.empty')}
          />
        ) : (
          <ul className="divide-y divide-border">
            {items.map((item) => (
              <li key={item.id} className="flex items-center gap-3 py-2.5 px-1">
                <div
                  className={`w-9 h-9 rounded-md flex items-center justify-center shrink-0 $$
                    item.type === 'dir'
                      ? 'bg-accent/10 text-accent'
                      : 'bg-bg-sunken text-fg-muted'
                  }`}
                >
                  {item.type === 'dir' ? <FolderOpen size={18} /> : <FileText size={18} />}
                </div>

                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="text-sm text-fg truncate">{item.orig_path}</span>
                    <Badge variant={item.type === 'dir' ? 'accent' : 'muted'}>
                      {item.type === 'dir' ? t('trash.dir') : t('trash.file')}
                    </Badge>
                  </div>
                  <div className="flex items-center gap-3 text-xs text-fg-subtle mt-0.5">
                    <span>
                      {t('trash.size')}: {formatBytes(item.size)}
                    </span>
                    <span>
                      {t('trash.deleteAt')}: {formatRelativeTime(item.deleted_at)}
                    </span>
                  </div>
                </div>

                <div className="flex items-center gap-1 shrink-0">
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => handleRestore(item.id)}
                    loading={busyId === item.id}
                  >
                    <RotateCcw size={14} />
                    {t('trash.restore')}
                  </Button>
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    onClick={() => handlePurgeItem(item.id)}
                    disabled={busyId === item.id}
                    aria-label={t('trash.purge')}
                    title={t('trash.purge')}
                    className="text-danger hover:text-danger"
                  >
                    <Trash2 size={15} />
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Modal>

      <Confirm
        open={purgeAllConfirm}
        title={t('trash.purgeAll')}
        message={t('trash.purgeConfirm')}
        confirmText={t('trash.purgeAll')}
        variant="danger"
        loading={purgeBusy}
        onConfirm={handlePurgeAll}
        onCancel={() => setPurgeAllConfirm(false)}
      />
    </>
  )
}
