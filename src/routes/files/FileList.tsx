import { useState, useMemo, useRef, useCallback } from 'react'
import { useParams, Link, useNavigate } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  FolderOpen,
  FileText,
  Image,
  FileCode,
  File,
  ChevronRight,
  Search,
  Plus,
  Upload,
  MoreVertical,
  ArrowUpDown,
  Trash2,
  Edit3,
  Copy,
  MoveRight,
  Download,
  Shield,
  FilePlus,
  FolderPlus,
  List,
  Grid3X3,
} from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { SkeletonTable } from '@/components/ui/Skeleton'
import { EmptyFolder, EmptySearch, EmptyError } from '@/components/ui/EmptyState'
import { Modal, Confirm } from '@/components/ui/Modal'
import { ContextMenu } from '@/components/ui/ContextMenu'
import { BottomSheet, ActionSheetItem, ActionSheetSeparator } from '@/components/ui/BottomSheet'
import { DropdownMenu, MenuItem } from '@/components/ui/DropdownMenu'
import { UploadProgress, useUploadManager } from '@/components/ui/UploadProgress'
import { filesApi } from '@/api/files'
import { useFormat, getFileExtension, isImageFile, isTextFile } from '@/lib/format'
import { validateFileName } from '@/lib/validate'
import type { FileEntry } from '@shared/types'
import { useLongPress } from '@/hooks/useLongPress'
import { useUiStore } from '@/stores/uiStore'
import { toast } from '@/components/ui/Toast'
import { useI18n } from '@/hooks/useI18n'
import { useIsMobile } from '@/hooks/useMediaQuery'

type SortField = 'name' | 'size' | 'mtime'
type SortOrder = 'asc' | 'desc'
type ViewMode = 'list' | 'grid'

type NewItemType = 'file' | 'folder' | null

interface ContextMenuState {
  x: number
  y: number
  file: FileEntry
}

export default function FileList() {
  const { t } = useI18n()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const isMobile = useIsMobile()
  const { '*': path = '' } = useParams()
  const [search, setSearch] = useState('')
  const [sortField, setSortField] = useState<SortField>('name')
  const [sortOrder, setSortOrder] = useState<SortOrder>('asc')
  const [viewMode, setViewMode] = useState<ViewMode>('list')
  const multiSelection = useUiStore((s) => s.multiSelection)
  const clearSelection = useUiStore((s) => s.clearSelection)
  const toggleSelection = useUiStore((s) => s.toggleSelection)

  const [newItemType, setNewItemType] = useState<NewItemType>(null)
  const [newItemName, setNewItemName] = useState('')
  const [newItemError, setNewItemError] = useState('')
  const [creating, setCreating] = useState(false)

  const [renameFile, setRenameFile] = useState<FileEntry | null>(null)
  const [renameName, setRenameName] = useState('')
  const [renameError, setRenameError] = useState('')
  const [renaming, setRenaming] = useState(false)

  const [deleteConfirm, setDeleteConfirm] = useState<{
    open: boolean
    files: FileEntry[]
  }>({ open: false, files: [] })
  const [deleting, setDeleting] = useState(false)

  const [contextMenu, setContextMenu] = useState<ContextMenuState | null>(null)
  const [actionSheetFile, setActionSheetFile] = useState<FileEntry | null>(null)
  const [showFabSheet, setShowFabSheet] = useState(false)

  const [isDragging, setIsDragging] = useState(false)
  const dragCounter = useRef(0)
  const fileInputRef = useRef<HTMLInputElement>(null)

  const uploadManager = useUploadManager()
  const [exitingIds, setExitingIds] = useState<Set<string>>(new Set())

  const currentPath = path ? '/' + path : ''
  const currentDir = currentPath || '/'

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['files', currentPath, sortField, sortOrder],
    queryFn: () => filesApi.list(currentPath, sortField, sortOrder),
  })

  const filteredFiles = useMemo(() => {
    if (!data?.files) return []
    if (!search.trim()) return data.files
    const q = search.toLowerCase()
    return data.files.filter((f) => f.name.toLowerCase().includes(q))
  }, [data, search])

  const breadcrumbs = useMemo(() => {
    const parts = currentPath.split('/').filter(Boolean)
    const result = [{ name: t('files.rootPath'), path: '' }]
    let p = ''
    for (const part of parts) {
      p += '/' + part
      result.push({ name: part, path: p })
    }
    return result
  }, [currentPath, t])

  const toggleSort = (field: SortField) => {
    if (sortField === field) {
      setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc')
    } else {
      setSortField(field)
      setSortOrder('asc')
    }
  }

  const invalidateFiles = useCallback(() => {
    queryClient.invalidateQueries({ queryKey: ['files'] })
  }, [queryClient])

  const handleNewClick = () => {
    if (isMobile) {
      setShowFabSheet(true)
    }
  }

  const openNewModal = (type: NewItemType) => {
    setNewItemType(type)
    setNewItemName('')
    setNewItemError('')
  }

  const handleCreate = async () => {
    const validation = validateFileName(newItemName)
    if (!validation.valid) {
      setNewItemError(t(validation.error!))
      return
    }

    setCreating(true)
    try {
      if (newItemType === 'file') {
        await filesApi.createFile(currentDir, newItemName.trim())
      } else {
        await filesApi.createDir(currentDir, newItemName.trim())
      }
      toast({ type: 'success', title: t('files.createSuccess') })
      setNewItemType(null)
      setNewItemName('')
      setNewItemError('')
      invalidateFiles()
    } catch (err) {
      setNewItemError(err instanceof Error ? err.message : t('files.createFailed'))
    } finally {
      setCreating(false)
    }
  }

  const handleUploadClick = () => {
    fileInputRef.current?.click()
  }

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = e.target.files
    if (files && files.length > 0) {
      uploadFiles(Array.from(files))
    }
    e.target.value = ''
  }

  const uploadFiles = async (files: File[]) => {
    for (const file of files) {
      const id = 'upload-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8)
      uploadManager.addUpload(id, file.name, file.size)

      try {
        await filesApi.simulateUploadProgress((percent) => {
          uploadManager.updateProgress(id, percent)
        }, 1000 + Math.random() * 1500)

        await filesApi.uploadFile(currentDir, { name: file.name, size: file.size })
        uploadManager.setSuccess(id)
        invalidateFiles()
      } catch (err) {
        uploadManager.setError(id, err instanceof Error ? err.message : t('files.uploadFailed'))
      }
    }
  }

  const handleDragEnter = (e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    dragCounter.current++
    if (e.dataTransfer.items && e.dataTransfer.items.length > 0) {
      setIsDragging(true)
    }
  }

  const handleDragLeave = (e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    dragCounter.current--
    if (dragCounter.current <= 0) {
      setIsDragging(false)
      dragCounter.current = 0
    }
  }

  const handleDragOver = (e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
  }

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setIsDragging(false)
    dragCounter.current = 0

    const files = e.dataTransfer.files
    if (files && files.length > 0) {
      uploadFiles(Array.from(files))
    }
  }

  const handleContextMenu = (e: React.MouseEvent, file: FileEntry) => {
    e.preventDefault()
    e.stopPropagation()
    setContextMenu({ x: e.clientX, y: e.clientY, file })
  }

  const handleMoreActions = (e: React.MouseEvent, file: FileEntry) => {
    e.stopPropagation()
    if (isMobile) {
      setActionSheetFile(file)
    } else {
      setContextMenu({ x: e.clientX, y: e.clientY, file })
    }
  }

  const handleOpenFile = (file: FileEntry) => {
    if (file.type === 'dir') {
      navigate(`/files${file.path}`)
    } else {
      navigate(`/edit${file.path}`)
    }
  }

  const handleRename = (file: FileEntry) => {
    setRenameFile(file)
    setRenameName(file.name)
    setRenameError('')
  }

  const handleRenameSubmit = async () => {
    if (!renameFile) return

    const validation = validateFileName(renameName)
    if (!validation.valid) {
      setRenameError(t(validation.error!))
      return
    }

    setRenaming(true)
    try {
      await filesApi.renameFile(renameFile.path, renameName.trim())
      toast({ type: 'success', title: t('files.renameSuccess') })
      setRenameFile(null)
      setRenameName('')
      setRenameError('')
      invalidateFiles()
    } catch (err) {
      setRenameError(err instanceof Error ? err.message : t('files.renameFailed'))
    } finally {
      setRenaming(false)
    }
  }

  const handleDeleteSingle = (file: FileEntry) => {
    setDeleteConfirm({ open: true, files: [file] })
  }

  const handleDeleteSelected = () => {
    const selectedFiles = filteredFiles.filter((f) => multiSelection.has(f.path))
    setDeleteConfirm({ open: true, files: selectedFiles })
  }

  const handleDeleteConfirm = async () => {
    if (deleteConfirm.files.length === 0) return

    setDeleting(true)
    try {
      const paths = deleteConfirm.files.map((f) => f.path)
      await filesApi.deleteFiles(paths)

      setExitingIds(new Set(paths))
      setTimeout(() => {
        setExitingIds(new Set())
      }, 250)

      toast({ type: 'success', title: t('files.deleteSuccess') })
      setDeleteConfirm({ open: false, files: [] })
      clearSelection()
      invalidateFiles()
    } catch (err) {
      toast({
        type: 'error',
        title: t('files.deleteFailed'),
        description: err instanceof Error ? err.message : undefined,
      })
    } finally {
      setDeleting(false)
    }
  }

  const handleCopy = (file: FileEntry) => {
    toast({ type: 'info', title: t('common.copy') + ': ' + file.name })
  }

  const handleMove = (file: FileEntry) => {
    toast({ type: 'info', title: t('files.move') + ': ' + file.name })
  }

  const handleDownload = (_file: FileEntry) => {
    toast({ type: 'info', title: t('files.downloadInProgress') })
  }

  const handlePermissions = (file: FileEntry) => {
    toast({ type: 'info', title: t('files.permissions') + ': ' + file.name })
  }

  return (
    <div
      className="h-full flex flex-col"
      onDragEnter={handleDragEnter}
      onDragLeave={handleDragLeave}
      onDragOver={handleDragOver}
      onDrop={handleDrop}
    >
      <div className="p-4 md:p-5 pb-3 space-y-3">
        <div className="flex items-center gap-2 flex-wrap">
          <h1 className="text-lg font-semibold text-fg">{t('files.title')}</h1>
          <Badge variant="muted">{data?.files.length || 0} {t('files.itemCount')}</Badge>
        </div>

        <nav className="flex items-center gap-1 text-sm overflow-x-auto pb-1 -mx-1 px-1">
          {breadcrumbs.map((crumb, i) => (
            <span key={crumb.path} className="flex items-center gap-1 shrink-0">
              {i > 0 && <ChevronRight size={14} className="text-fg-subtle" />}
              <Link
                to={crumb.path ? `/files${crumb.path}` : '/files'}
                className="inline-flex items-center min-h-[44px] px-1 text-fg-muted hover:text-fg hover:underline underline-offset-2 transition-colors"
              >
                {crumb.name}
              </Link>
            </span>
          ))}
        </nav>

        <div className="flex items-center gap-2">
          <div className="flex-1 relative">
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t('files.searchPlaceholder')}
              icon={<Search size={16} />}
            />
          </div>

          <DropdownMenu
            trigger={
              <Button variant="secondary" size="icon" aria-label={t('files.newFile')}>
                <Plus size={18} />
              </Button>
            }
          >
            <MenuItem
              icon={<FilePlus size={16} />}
              label={t('files.newFileItem')}
              onClick={() => openNewModal('file')}
            />
            <MenuItem
              icon={<FolderPlus size={16} />}
              label={t('files.newFolder')}
              onClick={() => openNewModal('folder')}
            />
          </DropdownMenu>

          <div className="hidden sm:flex items-center bg-bg-sunken rounded-lg p-0.5">
            <button
              onClick={() => setViewMode('list')}
              className={`p-1.5 rounded-md transition-colors ${
                viewMode === 'list'
                  ? 'bg-bg-elevated text-fg shadow-sm'
                  : 'text-fg-muted hover:text-fg'
              }`}
              aria-label={t('files.listView')}
            >
              <List size={16} />
            </button>
            <button
              onClick={() => setViewMode('grid')}
              className={`p-1.5 rounded-md transition-colors ${
                viewMode === 'grid'
                  ? 'bg-bg-elevated text-fg shadow-sm'
                  : 'text-fg-muted hover:text-fg'
              }`}
              aria-label={t('files.gridView')}
            >
              <Grid3X3 size={16} />
            </button>
          </div>

          <Button variant="secondary" size="icon" onClick={handleUploadClick} aria-label={t('files.upload')}>
            <Upload size={18} />
          </Button>
          <input
            ref={fileInputRef}
            type="file"
            multiple
            className="hidden"
            onChange={handleFileSelect}
          />
        </div>
      </div>

      <div className="flex-1 min-h-0 px-4 md:px-5 pb-4">
        <Card className="h-full overflow-hidden flex flex-col">
          {viewMode === 'list' && (
            <div className="hidden md:flex items-center gap-2 px-4 py-2 text-xs font-medium text-fg-muted border-b border-border bg-bg-sunken/50">
              <button
                className="flex items-center gap-1 flex-1 min-w-0 hover:text-fg transition-colors"
                onClick={() => toggleSort('name')}
              >
                {t('files.name')}
                <ArrowUpDown size={12} className={sortField === 'name' ? 'text-accent' : ''} />
              </button>
              <button
                className="w-24 text-right hover:text-fg transition-colors"
                onClick={() => toggleSort('size')}
              >
                {t('files.size')}
              </button>
              <button
                className="w-36 text-right hover:text-fg transition-colors hidden lg:block"
                onClick={() => toggleSort('mtime')}
              >
                {t('files.modified')}
              </button>
              <div className="w-10 text-right">{t('files.permissions')}</div>
              <div className="w-10 text-right">{t('common.actions')}</div>
            </div>
          )}

          <div className="flex-1 overflow-auto">
            {isLoading ? (
              <SkeletonTable rows={8} columns={4} />
            ) : error ? (
              <EmptyError
                error={error instanceof Error ? error.message : t('common.unknownError')}
                onRetry={() => refetch()}
                className="py-16"
              />
            ) : filteredFiles.length === 0 ? (
              search ? (
                <EmptySearch query={search} className="py-16" />
              ) : (
                <EmptyFolder className="py-16" />
              )
            ) : viewMode === 'list' ? (
              <ul className="divide-y divide-border">
                {filteredFiles.map((file, index) => (
                  <FileRow
                    key={file.path}
                    file={file}
                    index={index}
                    selected={multiSelection.has(file.path)}
                    exiting={exitingIds.has(file.path)}
                    isNew={false}
                    onToggleSelect={() => toggleSelection(file.path)}
                    onContextMenu={(e) => handleContextMenu(e, file)}
                    onMoreActions={(e) => handleMoreActions(e, file)}
                    onOpen={() => handleOpenFile(file)}
                  />
                ))}
              </ul>
            ) : (
              <div className="p-4 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
                {filteredFiles.map((file, index) => (
                  <FileGridItem
                    key={file.path}
                    file={file}
                    index={index}
                    selected={multiSelection.has(file.path)}
                    exiting={exitingIds.has(file.path)}
                    isNew={false}
                    onToggleSelect={() => toggleSelection(file.path)}
                    onContextMenu={(e) => handleContextMenu(e, file)}
                    onMoreActions={(e) => handleMoreActions(e, file)}
                    onOpen={() => handleOpenFile(file)}
                  />
                ))}
              </div>
            )}
          </div>
        </Card>
      </div>

      {multiSelection.size > 0 && (
        <div className="fixed bottom-16 md:bottom-4 left-1/2 -translate-x-1/2 z-30 animate-slide-up">
          <div className="bg-bg-elevated text-fg rounded-2xl px-2 py-2 shadow-xl border border-border flex items-center gap-1">
            <div className="px-3 py-1.5">
              <span className="text-sm font-medium">
                <span className="text-accent font-bold">{multiSelection.size}</span>
                {' '}
                {t('files.selectedItemsShort', { count: multiSelection.size })}
              </span>
            </div>
            <div className="h-5 w-px bg-border mx-1" />
            <button
              onClick={handleDeleteSelected}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-sm text-danger hover:bg-danger/10 transition-colors"
            >
              <Trash2 size={16} />
              <span className="hidden sm:inline">{t('files.delete')}</span>
            </button>
            <button
              onClick={clearSelection}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-sm text-fg-muted hover:text-fg hover:bg-fg/5 transition-colors"
            >
              {t('common.cancel')}
            </button>
          </div>
        </div>
      )}

      <button
        onClick={handleNewClick}
        className="md:hidden fixed bottom-20 right-4 z-30 w-14 h-14 rounded-full bg-accent text-accent-fg shadow-xl flex items-center justify-center active:scale-95 transition-transform"
        aria-label={t('files.newFile')}
      >
        <Plus size={24} />
      </button>

      {isDragging && (
        <div className="fixed inset-0 z-50 bg-accent/10 backdrop-blur-sm border-4 border-dashed border-accent flex items-center justify-center pointer-events-none animate-fade-in">
          <div className="text-center">
            <Upload size={48} className="mx-auto text-accent mb-4" />
            <p className="text-lg font-semibold text-fg">{t('files.dropToUpload')}</p>
            <p className="text-sm text-fg-muted mt-1">{t('files.dragHint')}</p>
          </div>
        </div>
      )}

      <UploadProgress items={uploadManager.items} onDismiss={uploadManager.dismiss} />

      <Modal
        open={newItemType !== null}
        onClose={() => setNewItemType(null)}
        title={newItemType === 'file' ? t('files.newFileItem') : t('files.newFolder')}
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setNewItemType(null)}>
              {t('common.cancel')}
            </Button>
            <Button variant="primary" onClick={handleCreate} loading={creating}>
              {t('common.confirm')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <Input
            value={newItemName}
            onChange={(e) => {
              setNewItemName(e.target.value)
              if (newItemError) setNewItemError('')
            }}
            placeholder={newItemType === 'file' ? t('files.fileNamePlaceholder') : t('files.folderNamePlaceholder')}
            invalid={!!newItemError}
            autoFocus
            onKeyDown={(e) => {
              if (e.key === 'Enter') handleCreate()
            }}
          />
          {newItemError && (
            <p className="text-xs text-danger">{newItemError}</p>
          )}
        </div>
      </Modal>

      <Modal
        open={renameFile !== null}
        onClose={() => setRenameFile(null)}
        title={t('files.renameTitle')}
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setRenameFile(null)}>
              {t('common.cancel')}
            </Button>
            <Button variant="primary" onClick={handleRenameSubmit} loading={renaming}>
              {t('common.confirm')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <p className="text-sm text-fg-muted">{renameFile?.name}</p>
          <Input
            value={renameName}
            onChange={(e) => {
              setRenameName(e.target.value)
              if (renameError) setRenameError('')
            }}
            placeholder={t('files.renamePlaceholder')}
            invalid={!!renameError}
            autoFocus
            onKeyDown={(e) => {
              if (e.key === 'Enter') handleRenameSubmit()
            }}
          />
          {renameError && (
            <p className="text-xs text-danger">{renameError}</p>
          )}
        </div>
      </Modal>

      <Confirm
        open={deleteConfirm.open}
        title={t('files.deleteConfirm')}
        message={
          deleteConfirm.files.length === 1
            ? t('files.deleteConfirmSingle', { name: deleteConfirm.files[0].name })
            : t('files.deleteConfirmMultiple', { count: deleteConfirm.files.length })
        }
        confirmText={t('files.delete')}
        variant="danger"
        loading={deleting}
        onConfirm={handleDeleteConfirm}
        onCancel={() => setDeleteConfirm({ open: false, files: [] })}
      />

      {contextMenu && (
        <ContextMenu
          x={contextMenu.x}
          y={contextMenu.y}
          file={contextMenu.file}
          onClose={() => setContextMenu(null)}
          onOpen={() => handleOpenFile(contextMenu.file)}
          onRename={() => handleRename(contextMenu.file)}
          onCopy={() => handleCopy(contextMenu.file)}
          onMove={() => handleMove(contextMenu.file)}
          onDownload={() => handleDownload(contextMenu.file)}
          onDelete={() => handleDeleteSingle(contextMenu.file)}
          onPermissions={() => handlePermissions(contextMenu.file)}
          t={t}
        />
      )}

      <BottomSheet
        open={actionSheetFile !== null}
        onClose={() => setActionSheetFile(null)}
        title={actionSheetFile?.name}
      >
        {actionSheetFile && (
          <>
            <ActionSheetItem
              icon={actionSheetFile.type === 'dir' ? <FolderOpen size={20} /> : <FileText size={20} />}
              label={t('files.open')}
              onClick={() => {
                handleOpenFile(actionSheetFile)
                setActionSheetFile(null)
              }}
            />
            <ActionSheetSeparator />
            <ActionSheetItem
              icon={<Edit3 size={20} />}
              label={t('files.rename')}
              onClick={() => {
                handleRename(actionSheetFile)
                setActionSheetFile(null)
              }}
            />
            <ActionSheetItem
              icon={<Copy size={20} />}
              label={t('files.copy')}
              onClick={() => {
                handleCopy(actionSheetFile)
                setActionSheetFile(null)
              }}
            />
            <ActionSheetItem
              icon={<MoveRight size={20} />}
              label={t('files.move')}
              onClick={() => {
                handleMove(actionSheetFile)
                setActionSheetFile(null)
              }}
            />
            <ActionSheetSeparator />
            <ActionSheetItem
              icon={<Download size={20} />}
              label={t('files.download')}
              onClick={() => {
                handleDownload(actionSheetFile)
                setActionSheetFile(null)
              }}
            />
            <ActionSheetItem
              icon={<Shield size={20} />}
              label={t('files.permissions')}
              onClick={() => {
                handlePermissions(actionSheetFile)
                setActionSheetFile(null)
              }}
            />
            <ActionSheetSeparator />
            <ActionSheetItem
              icon={<Trash2 size={20} />}
              label={t('files.delete')}
              danger
              onClick={() => {
                handleDeleteSingle(actionSheetFile)
                setActionSheetFile(null)
              }}
            />
          </>
        )}
      </BottomSheet>

      <BottomSheet
        open={showFabSheet}
        onClose={() => setShowFabSheet(false)}
        title={t('files.newFile')}
      >
        <ActionSheetItem
          icon={<FilePlus size={20} />}
          label={t('files.newFileItem')}
          onClick={() => {
            openNewModal('file')
            setShowFabSheet(false)
          }}
        />
        <ActionSheetItem
          icon={<FolderPlus size={20} />}
          label={t('files.newFolder')}
          onClick={() => {
            openNewModal('folder')
            setShowFabSheet(false)
          }}
        />
        <ActionSheetSeparator />
        <ActionSheetItem
          icon={<Upload size={20} />}
          label={t('files.uploadFiles')}
          onClick={() => {
            handleUploadClick()
            setShowFabSheet(false)
          }}
        />
      </BottomSheet>
    </div>
  )
}

function FileRow({
  file,
  index,
  selected,
  exiting,
  isNew,
  onToggleSelect,
  onContextMenu,
  onMoreActions,
  onOpen,
}: {
  file: FileEntry
  index: number
  selected: boolean
  exiting: boolean
  isNew: boolean
  onToggleSelect: () => void
  onContextMenu: (e: React.MouseEvent) => void
  onMoreActions: (e: React.MouseEvent) => void
  onOpen: () => void
}) {
  const { t } = useI18n()
  const { formatDate, formatBytes } = useFormat()
  const { handlers, active } = useLongPress<HTMLLIElement>(onToggleSelect, {
    delay: 400,
  })

  const Icon = getFileIcon(file)

  const animationClass = exiting
    ? 'animate-list-exit'
    : isNew
    ? 'animate-list-enter'
    : ''

  return (
    <li
      {...handlers}
      onContextMenu={onContextMenu}
      className={`
        flex items-center gap-3 px-3 md:px-4 py-2.5 md:py-2
        min-h-[48px]
        transition-colors cursor-pointer
        ${selected ? 'bg-accent/10' : 'hover:bg-fg/5'}
        ${active ? 'bg-bg-sunken' : ''}
        ${animationClass}
      `}
      style={{ animationDelay: isNew ? `${index * 30}ms` : undefined }}
    >
      {selected && (
        <div className="w-5 h-5 rounded border-2 border-accent bg-accent flex items-center justify-center shrink-0">
          <svg viewBox="0 0 20 20" fill="currentColor" className="w-3 h-3 text-accent-fg">
            <path d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0z" />
          </svg>
        </div>
      )}

      <div
        className={`w-9 h-9 rounded-md flex items-center justify-center shrink-0 ${
          file.type === 'dir' ? 'bg-accent/10 text-accent' : 'bg-bg-sunken text-fg-muted'
        }`}
      >
        <Icon size={18} />
      </div>

      <div className="flex-1 min-w-0" onClick={!selected ? onOpen : undefined}>
        <span
          className="text-sm text-fg truncate block hover:text-accent transition-colors"
        >
          {file.name}
        </span>
        <div className="flex items-center gap-3 text-xs text-fg-subtle mt-0.5 md:hidden">
          <span>{formatBytes(file.size)}</span>
          <span>{formatDate(file.mtime)}</span>
        </div>
      </div>

      <div className="hidden md:block w-24 text-right text-sm text-fg-muted">
        {file.type === 'dir' ? '—' : formatBytes(file.size)}
      </div>

      <div className="hidden lg:block w-36 text-right text-xs text-fg-subtle">
        {formatDate(file.mtime)}
      </div>

      <div className="hidden md:block w-10 text-right text-xs text-fg-subtle font-mono">
        {file.perms}
      </div>

      <div className="flex justify-end md:w-10 md:block">
        <button
          className="min-h-[44px] min-w-[44px] md:min-h-0 md:min-w-0 flex items-center justify-center p-1.5 rounded-md text-fg-subtle hover:text-fg hover:bg-bg-sunken transition-colors"
          onClick={onMoreActions}
          aria-label={t('files.moreActions')}
        >
          <MoreVertical size={16} />
        </button>
      </div>
    </li>
  )
}

function FileGridItem({
  file,
  index,
  selected,
  exiting,
  isNew,
  onToggleSelect,
  onContextMenu,
  onMoreActions,
  onOpen,
}: {
  file: FileEntry
  index: number
  selected: boolean
  exiting: boolean
  isNew: boolean
  onToggleSelect: () => void
  onContextMenu: (e: React.MouseEvent) => void
  onMoreActions: (e: React.MouseEvent) => void
  onOpen: () => void
}) {
  const { t } = useI18n()
  const { formatBytes } = useFormat()
  const { handlers, active } = useLongPress<HTMLDivElement>(onToggleSelect, {
    delay: 400,
  })

  const Icon = getFileIcon(file)

  const animationClass = exiting
    ? 'animate-list-exit'
    : isNew
    ? 'animate-list-enter'
    : ''

  return (
    <div
      {...handlers}
      onContextMenu={onContextMenu}
      className={`
        relative flex flex-col items-center gap-2 p-3 rounded-lg
        transition-all duration-150 cursor-pointer
        ${selected ? 'bg-accent/10 ring-2 ring-accent/30' : 'hover:bg-fg/5'}
        ${active ? 'bg-bg-sunken' : ''}
        ${animationClass}
      `}
      style={{ animationDelay: isNew ? `${index * 30}ms` : undefined }}
      onClick={!selected ? onOpen : undefined}
    >
      {selected && (
        <div className="absolute top-2 right-2 w-5 h-5 rounded border-2 border-accent bg-accent flex items-center justify-center z-10">
          <svg viewBox="0 0 20 20" fill="currentColor" className="w-3 h-3 text-accent-fg">
            <path d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0z" />
          </svg>
        </div>
      )}

      <div
        className={`
          w-14 h-14 rounded-lg flex items-center justify-center
          ${file.type === 'dir' ? 'bg-accent/10 text-accent' : 'bg-bg-sunken text-fg-muted'}
        `}
      >
        <Icon size={28} />
      </div>

      <div className="w-full text-center">
        <span className="text-xs text-fg truncate block leading-tight">
          {file.name}
        </span>
        <span className="text-[10px] text-fg-subtle block mt-0.5">
          {file.type === 'dir' ? t('files.folder') : formatBytes(file.size)}
        </span>
      </div>

      <button
        className="absolute bottom-1 right-1 p-1 rounded-md text-fg-subtle hover:text-fg hover:bg-bg-sunken transition-colors opacity-0 hover:opacity-100"
        onClick={(e) => {
          e.stopPropagation()
          onMoreActions(e)
        }}
        aria-label={t('files.moreActions')}
      >
        <MoreVertical size={14} />
      </button>
    </div>
  )
}

function getFileIcon(file: FileEntry) {
  if (file.type === 'dir') return FolderOpen
  if (isImageFile(file.name)) return Image
  const ext = getFileExtension(file.name)
  if (['php', 'js', 'ts', 'tsx', 'css', 'html', 'json', 'sql', 'py'].includes(ext)) return FileCode
  if (isTextFile(file.name)) return FileText
  return File
}

export { FileList }
