import { useState, type ReactNode } from 'react'
import { ChevronRight } from 'lucide-react'
import { useI18n } from '@/hooks/useI18n'

export function useRelativeTime() {
  const { t, language } = useI18n()
  return (ts: number) => {
    const diff = Math.max(0, Math.floor((Date.now() - ts * 1000) / 1000))
    if (diff < 60) return t('notify.relNow', { defaultValue: '刚刚' })
    if (diff < 3600) return `${Math.floor(diff / 60)} ${t('notify.relMin', { defaultValue: '分钟前' })}`
    if (diff < 86400) return `${Math.floor(diff / 3600)} ${t('notify.relHour', { defaultValue: '小时前' })}`
    const locale = language === 'en' ? 'en-US' : 'zh-CN'
    return new Date(ts * 1000).toLocaleDateString(locale)
  }
}

export function JsonTree({ data }: { data: unknown }) {
  const [open, setOpen] = useState<Record<string, boolean>>({})
  const render = (node: unknown, path: string, depth = 0): ReactNode => {
    const indent = { paddingLeft: `${depth * 16 + 8}px` }
    if (node === null) return <span style={indent} className="font-mono text-xs text-fg-subtle">null</span>
    if (typeof node !== 'object') {
      return (
        <div style={indent} className="font-mono text-xs py-0.5 break-all">
          {typeof node === 'string' ? (
            <span className="text-success">"{node}"</span>
          ) : typeof node === 'number' ? (
            <span className="text-warning">{String(node)}</span>
          ) : typeof node === 'boolean' ? (
            <span className="text-accent">{String(node)}</span>
          ) : (
            <span className="text-fg">{String(node)}</span>
          )}
        </div>
      )
    }
    const arr = Array.isArray(node)
    const entries = arr
      ? (node as unknown[]).map((v, i) => [String(i), v] as const)
      : Object.entries(node as Record<string, unknown>)
    const isOpen = open[path] ?? depth < 1
    return (
      <div>
        <button
          type="button"
          className="w-full text-left font-mono text-xs py-0.5 text-fg-muted hover:text-fg transition-colors"
          style={indent}
          onClick={() => setOpen((p) => ({ ...p, [path]: !isOpen }))}
        >
          <ChevronRight
            size={12}
            className={`inline mr-1 -ml-1 transition-transform ${isOpen ? 'rotate-90' : ''}`}
          />
          <span className="text-fg">{arr ? `[${entries.length}]` : `{${entries.length}}`}</span>
        </button>
        {isOpen && entries.length > 0 && (
          <div>
            {entries.map(([k, v]) => (
              <div key={path + '.' + k}>
                {!arr && (
                  <span
                    style={{ paddingLeft: `${(depth + 1) * 16 + 8}px` }}
                    className="font-mono text-xs text-accent py-0.5 inline-block"
                  >
                    {k}:
                  </span>
                )}
                {render(v, path + '.' + k, depth + 1)}
              </div>
            ))}
          </div>
        )}
      </div>
    )
  }
  return (
    <div className="rounded-lg border border-border bg-bg-sunken/40 p-2 max-h-[420px] overflow-auto">
      {render(data, 'root')}
    </div>
  )
}

export const NOTIFY_SEVERITY_BADGE: Record<'info' | 'success' | 'warning' | 'critical', 'accent' | 'success' | 'warning' | 'danger'> = {
  info: 'accent',
  success: 'success',
  warning: 'warning',
  critical: 'danger',
}

export function renderI18nText(
  t: ReturnType<typeof useI18n>['t'],
  key: string | undefined,
  params?: Record<string, string | number>,
): string | null {
  if (!key) return null
  const p: Record<string, string> = {}
  if (params) for (const [k, v] of Object.entries(params)) p[k] = String(v)
  try {
    return t(key as never, p as never)
  } catch {
    return key
  }
}
