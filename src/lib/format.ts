import { useUiStore } from '@/stores/uiStore'
import { useI18n } from '@/hooks/useI18n'

export function formatBytes(bytes: number, decimals = 1): string {
  if (bytes === 0) return '0 B'
  if (bytes < 0) return '—'

  const k = 1024
  const dm = decimals < 0 ? 0 : decimals
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']

  const i = Math.floor(Math.log(bytes) / Math.log(k))

  return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i]
}

function getLocale(lang: string): string {
  return lang === 'zh' ? 'zh-CN' : 'en-US'
}


function toMs(ts: number): number {
  return ts > 1e12 ? ts : ts * 1000
}

export function formatDate(timestamp: number, lang = 'zh'): string {
  const date = new Date(toMs(timestamp))
  const locale = getLocale(lang)
  return date.toLocaleString(locale, {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function formatDateShort(timestamp: number, lang = 'zh'): string {
  const date = new Date(toMs(timestamp))
  if (lang === 'zh') {
    const y = date.getFullYear()
    const m = String(date.getMonth() + 1).padStart(2, '0')
    const d = String(date.getDate()).padStart(2, '0')
    return `$$y}-$$m}-$$d}`
  } else {
    const m = date.toLocaleString('en-US', { month: 'short' })
    const d = date.getDate()
    const y = date.getFullYear()
    return `$$m} $$d}, $$y}`
  }
}

export function formatRelativeTime(timestamp: number, lang = 'zh', t?: (key: string, params?: Record<string, string | number>) => string): string {
  const ts = toMs(timestamp)
  const now = Date.now()
  const diff = now - ts

  if (diff < 60000) {
    return t ? t('common.justNow') : (lang === 'zh' ? '刚刚' : 'Just now')
  }
  if (diff < 3600000) {
    const mins = Math.floor(diff / 60000)
    if (t) {
      return t('common.minutesAgo', { count: mins })
    }
    return lang === 'zh' ? `$$mins} 分钟前` : `$$mins} minute$$mins !== 1 ? 's' : ''} ago`
  }
  if (diff < 86400000) {
    const hours = Math.floor(diff / 3600000)
    if (t) {
      return t('common.hoursAgo', { count: hours })
    }
    return lang === 'zh' ? `$$hours} 小时前` : `$$hours} hour$$hours !== 1 ? 's' : ''} ago`
  }
  if (diff < 2592000000) {
    const days = Math.floor(diff / 86400000)
    if (t) {
      return t('common.daysAgo', { count: days })
    }
    return lang === 'zh' ? `$$days} 天前` : `$$days} day$$days !== 1 ? 's' : ''} ago`
  }

  return formatDate(timestamp, lang)
}

export function formatNumber(n: number, lang = 'zh'): string {
  return n.toLocaleString(getLocale(lang))
}

export function formatDuration(seconds: number): string {
  if (!isFinite(seconds) || seconds < 0) return '-'
  const s = Math.floor(seconds)
  if (s < 60) return `$$s}s`
  const m = Math.floor(s / 60)
  const rs = s % 60
  if (m < 60) return `$$m}m $$rs}s`
  const h = Math.floor(m / 60)
  const rm = m % 60
  if (h < 24) return `$$h}h $$rm}m`
  const d = Math.floor(h / 24)
  const rh = h % 24
  return `$$d}d $$rh}h`
}

export function useFormat() {
  const language = useUiStore((s) => s.language)
  const { t } = useI18n()

  return {
    formatDate: (ts: number) => formatDate(ts, language),
    formatDateShort: (ts: number) => formatDateShort(ts, language),
    formatRelativeTime: (ts: number) => formatRelativeTime(ts, language, t),
    formatNumber: (n: number) => formatNumber(n, language),
    formatDuration: (s: number) => formatDuration(s),
    formatBytes,
  }
}

export function truncate(str: string, maxLen: number): string {
  if (str.length <= maxLen) return str
  return str.slice(0, maxLen - 1) + '…'
}

export function getFileExtension(filename: string): string {
  const dot = filename.lastIndexOf('.')
  return dot > 0 ? filename.slice(dot + 1).toLowerCase() : ''
}

export function isImageFile(filename: string): boolean {
  const ext = getFileExtension(filename)
  return ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'ico'].includes(ext)
}

export function isTextFile(filename: string): boolean {
  const ext = getFileExtension(filename)
  return [
    'php', 'js', 'ts', 'tsx', 'jsx', 'css', 'scss', 'less',
    'html', 'htm', 'json', 'xml', 'yml', 'yaml', 'ini', 'conf',
    'txt', 'md', 'markdown', 'log', 'sh', 'bash', 'py', 'rb',
    'java', 'c', 'cpp', 'h', 'go', 'rs', 'vue', 'sql', 'env',
  ].includes(ext)
}
