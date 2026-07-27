import { memo } from 'react'
import { useI18n } from '@/hooks/useI18n'

interface CodeEditorProps {
  value: string
  onChange: (value: string) => void
  readOnly?: boolean
  filename?: string
  language?: string
}

function CodeEditorComponent({ value, onChange, readOnly, filename }: CodeEditorProps) {
  const language = getLanguage(filename || '')
  const { t } = useI18n()

  return (
    <div className="h-full flex flex-col">
      <div className="flex items-center gap-2 px-4 py-2 bg-bg-elevated/50 border-b border-border text-xs text-fg-muted">
        <span className="font-mono">{filename}</span>
        <span className="badge-muted badge">{language}</span>
      </div>
      <textarea
        value={value}
        onChange={(e) => onChange(e.target.value)}
        readOnly={readOnly}
        spellCheck={false}
        className="
          flex-1 w-full h-full p-4
          font-mono text-sm leading-relaxed
          bg-bg-sunken text-fg
          resize-none outline-none
          focus:outline-none
        "
        style={{ tabSize: 4 }}
      />
      <div className="flex items-center justify-between px-4 py-1.5 bg-bg-elevated/50 border-t border-border text-[10px] text-fg-subtle font-mono">
        <span>{t('codeEditor.lineCount', { count: value.split('\n').length })}</span>
        <span>{language}</span>
      </div>
    </div>
  )
}

function getLanguage(filename: string): string {
  const ext = filename.split('.').pop()?.toLowerCase() || ''
  const map: Record<string, string> = {
    php: 'PHP',
    js: 'JavaScript',
    jsx: 'JSX',
    ts: 'TypeScript',
    tsx: 'TSX',
    css: 'CSS',
    scss: 'SCSS',
    html: 'HTML',
    json: 'JSON',
    md: 'Markdown',
    sql: 'SQL',
    py: 'Python',
    sh: 'Shell',
    yml: 'YAML',
    yaml: 'YAML',
    xml: 'XML',
  }
  return map[ext] || ext.toUpperCase() || 'Text'
}

export const CodeEditor = memo(CodeEditorComponent)
