import { useState, Suspense, lazy, useEffect } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Save, FileText, RefreshCw, Download } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { Badge } from '@/components/ui/Badge'
import { filesApi } from '@/api/files'
import { formatBytes } from '@/lib/format'
import { toast } from '@/components/ui/Toast'
import type { FileContent } from '@shared/types'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'

const CodeEditor = lazy(() =>
  import('@/components/editor/CodeEditor').then((m) => ({ default: m.CodeEditor })),
)

export default function FileEditor() {
  const { t } = useI18n()
  const { '*': path = '' } = useParams()
  const queryClient = useQueryClient()
  const [content, setContent] = useState('')
  const [isEditing, setIsEditing] = useState(false)

  const filePath = '/' + path

  const { data, isLoading, error } = useQuery<FileContent>({
    queryKey: ['file-content', filePath],
    queryFn: () => filesApi.getContent(filePath),
  })

  useEffect(() => {
    if (data?.type === 'text') {
      setContent(data.content)
    }
  }, [data])

  const saveMutation = useMutation({
    mutationFn: (text: string) => filesApi.saveContent(filePath, text),
    onSuccess: () => {
      toast({ type: 'success', title: t('files.saveSuccess') })
      setIsEditing(false)
      queryClient.invalidateQueries({ queryKey: ['file-content', filePath] })
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.saveFailed'), description: resolveErrorText(err) })
    },
  })

  const handleSave = () => {
    saveMutation.mutate(content)
  }

  const handleDownload = () => {
    toast({ type: 'info', title: t('files.downloadInProgress') })
  }

  if (isLoading) {
    return (
      <div className="p-6 flex justify-center">
        <Spinner size="lg" />
      </div>
    )
  }

  if (error) {
    return (
      <div className="p-6">
        <Card className="p-6 text-center text-danger">
          {t('common.error')}：{resolveErrorText(error) || t('common.unknownError')}
        </Card>
      </div>
    )
  }

  if (!data) return null

  const filename = path.split('/').pop() || path

  return (
    <div className="h-full flex flex-col">
      <div className="flex items-center gap-3 px-4 md:px-5 py-3 border-b border-border shrink-0">
        <Link
          to={`/files$$path.substring(0, path.lastIndexOf('/')) || ''}`}
          className="p-1.5 -ml-1.5 rounded-md text-fg-muted hover:text-fg hover:bg-fg/5 transition-colors"
          aria-label={t('common.back')}
        >
          <ArrowLeft size={18} />
        </Link>

        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2">
            <FileText size={16} className="text-fg-muted shrink-0" />
            <h1 className="text-sm font-medium text-fg truncate">{filename}</h1>
            <Badge variant="muted" className="shrink-0">
              {data.type === 'text' ? t('files.text') : data.type === 'image' ? t('files.image') : t('files.binary')}
            </Badge>
          </div>
          <div className="text-xs text-fg-subtle mt-0.5 truncate">
            {filePath} · {formatBytes(data.size || 0)}
          </div>
        </div>

        <div className="flex items-center gap-1">
          <Button variant="ghost" size="icon-sm" onClick={handleDownload} aria-label={t('common.download')}>
            <Download size={16} />
          </Button>
          <Button variant="ghost" size="icon-sm" aria-label={t('common.refresh')}>
            <RefreshCw size={16} />
          </Button>
          {data.type === 'text' && (
            <Button
              variant="primary"
              size="sm"
              onClick={isEditing ? handleSave : () => setIsEditing(true)}
              loading={saveMutation.isPending}
            >
              <Save size={16} />
              <span className="hidden sm:inline">{isEditing ? t('common.save') : t('common.edit')}</span>
            </Button>
          )}
        </div>
      </div>

      <div className="flex-1 min-h-0 overflow-auto bg-bg-sunken">
        {data.type === 'text' ? (
          <Suspense
            fallback={
              <div className="h-full flex items-center justify-center">
                <Spinner />
              </div>
            }
          >
            <CodeEditor
              value={content}
              onChange={setContent}
              readOnly={!isEditing}
              filename={filename}
            />
          </Suspense>
        ) : data.type === 'image' ? (
          <div className="h-full flex items-center justify-center p-4">
            <img
              src={`data:$$data.mime};base64,$$data.content}`}
              alt={filename}
              className="max-w-full max-h-full object-contain rounded shadow-lg"
            />
          </div>
        ) : (
          <div className="p-6 text-center text-fg-muted">
            {t('files.binaryPreview')}
          </div>
        )}
      </div>
    </div>
  )
}
