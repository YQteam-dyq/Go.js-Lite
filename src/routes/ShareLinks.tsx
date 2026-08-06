import { useState, useEffect } from 'react'
import { useI18n } from '@/hooks/useI18n'
import { apiGet, apiPost } from '@/api'
import { Spinner } from '@/components/ui/Spinner'
import { Link2, X, Copy, Check, AlertCircle, Clock } from 'lucide-react'

interface ShareItem {
  token: string
  path: string
  created_at: number
  expires_at: number
  remaining_seconds: number
  max_downloads: number
  download_count: number
  has_password: boolean
  is_dir: boolean
}

export default function ShareLinks() {
  const { t } = useI18n()
  const [shares, setShares] = useState<ShareItem[]>([])
  const [loading, setLoading] = useState(true)
  const [showCreate, setShowCreate] = useState(false)
  const [createPath, setCreatePath] = useState('')
  const [createExpires, setCreateExpires] = useState('24')
  const [createPassword, setCreatePassword] = useState('')
  const [createMaxDownloads, setCreateMaxDownloads] = useState('0')
  const [creating, setCreating] = useState(false)
  const [copiedToken, setCopiedToken] = useState('')
  const [error, setError] = useState('')
  const [shareUrl, setShareUrl] = useState('')

  const loadShares = async () => {
    setLoading(true)
    try {
      const res = await apiGet<{ shares: ShareItem[] }>('share/list')
      if (res.ok && res.data) setShares(res.data.shares || [])
    } catch {}
    setLoading(false)
  }

  useEffect(() => { loadShares() }, [])

  const handleCreate = async () => {
    if (!createPath) return
    setCreating(true)
    setError('')
    setShareUrl('')
    try {
      const res = await apiPost<{ share_url: string }>('share/create', {
        path: createPath,
        expires_in: parseInt(createExpires) || 24,
        password: createPassword || undefined,
        max_downloads: parseInt(createMaxDownloads) || 0,
      })
      if (res.ok && res.data) {
        setShareUrl(res.data.share_url)
        setCreatePath('')
        setCreatePassword('')
        loadShares()
      } else {
        setError(res.error?.message || t('common.unknownError'))
      }
    } catch {
      setError(t('common.unknownError'))
    }
    setCreating(false)
  }

  const handleRevoke = async (token: string) => {
    if (!confirm(t('shareLinks.confirmRevoke'))) return
    try {
      await apiPost('share/revoke', { token })
      setShares(prev => prev.filter(s => s.token !== token))
    } catch {}
  }

  const handleCopy = (url: string) => {
    navigator.clipboard.writeText(url)
    setCopiedToken(url)
    setTimeout(() => setCopiedToken(''), 2000)
  }

  const formatRemaining = (seconds: number) => {
    if (seconds <= 0) return t('shareLinks.expired')
    const h = Math.floor(seconds / 3600)
    const m = Math.floor((seconds % 3600) / 60)
    if (h > 0) return `${h}h ${m}m`
    return `${m}m`
  }

  const scheme = window.location.protocol
  const host = window.location.host

  if (loading) return <div className="p-8 flex justify-center"><Spinner /></div>

  return (
    <div className="p-4 md:p-6 max-w-4xl mx-auto">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-xl font-bold flex items-center gap-2"><Link2 size={22} />{t('shareLinks.title')}</h1>
          <p className="text-sm text-fg-muted mt-1">{t('shareLinks.subtitle')}</p>
        </div>
        <button
          onClick={() => { setShowCreate(!showCreate); setShareUrl('') }}
          className="text-sm px-3 py-2 rounded-lg bg-accent text-white hover:bg-accent/90"
        >
          {t('shareLinks.create')}
        </button>
      </div>

      {error && (
        <div className="mb-4 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg flex items-center gap-2 text-sm text-red-700 dark:text-red-300">
          <AlertCircle size={16} />{error}
        </div>
      )}

      {showCreate && (
        <div className="mb-6 border border-border rounded-xl p-4 bg-bg-elevated space-y-3">
          <input
            type="text"
            value={createPath}
            onChange={e => setCreatePath(e.target.value)}
            placeholder="/path/to/file"
            className="w-full px-3 py-2 rounded-lg border border-border bg-bg text-sm focus:outline-none focus:ring-2 focus:ring-accent/50"
          />
          <div className="flex gap-3">
            <div className="flex-1">
              <label className="text-xs text-fg-muted block mb-1">{t('shareLinks.expiresIn')}</label>
              <input
                type="number"
                value={createExpires}
                onChange={e => setCreateExpires(e.target.value)}
                min="1"
                className="w-full px-3 py-2 rounded-lg border border-border bg-bg text-sm focus:outline-none focus:ring-2 focus:ring-accent/50"
              />
            </div>
            <div className="flex-1">
              <label className="text-xs text-fg-muted block mb-1">{t('shareLinks.maxDownloads')}</label>
              <input
                type="number"
                value={createMaxDownloads}
                onChange={e => setCreateMaxDownloads(e.target.value)}
                min="0"
                className="w-full px-3 py-2 rounded-lg border border-border bg-bg text-sm focus:outline-none focus:ring-2 focus:ring-accent/50"
              />
            </div>
          </div>
          <input
            type="text"
            value={createPassword}
            onChange={e => setCreatePassword(e.target.value)}
            placeholder={t('shareLinks.password')}
            className="w-full px-3 py-2 rounded-lg border border-border bg-bg text-sm focus:outline-none focus:ring-2 focus:ring-accent/50"
          />
          <div className="flex gap-2">
            <button
              onClick={handleCreate}
              disabled={creating || !createPath}
              className="px-4 py-2 rounded-lg bg-accent text-white text-sm hover:bg-accent/90 disabled:opacity-50"
            >
              {creating ? <Spinner size="sm" /> : t('shareLinks.create')}
            </button>
            <button
              onClick={() => setShowCreate(false)}
              className="px-4 py-2 rounded-lg border border-border text-sm text-fg-muted hover:bg-fg/5"
            >
              {t('common.cancel')}
            </button>
          </div>
          {shareUrl && (
            <div className="flex items-center gap-2 p-2 bg-green-50 dark:bg-green-900/20 rounded-lg text-sm">
              <span className="flex-1 truncate">{shareUrl}</span>
              <button onClick={() => handleCopy(shareUrl)} className="p-1 hover:text-accent shrink-0">
                {copiedToken === shareUrl ? <Check size={16} className="text-green-500" /> : <Copy size={16} />}
              </button>
            </div>
          )}
        </div>
      )}

      {shares.length === 0 && !showCreate && (
        <div className="text-center py-12 text-fg-muted">{t('shareLinks.noShares')}</div>
      )}

      <div className="space-y-2">
        {shares.map(share => (
          <div key={share.token} className="border border-border rounded-xl p-4 bg-bg-elevated">
            <div className="flex items-start justify-between gap-3">
              <div className="flex-1 min-w-0">
                <div className="text-sm font-medium truncate">{share.path}</div>
                <div className="flex items-center gap-3 mt-1 text-xs text-fg-muted">
                  <span className="flex items-center gap-1">
                    <Clock size={12} />
                    {formatRemaining(share.remaining_seconds)}
                  </span>
                  <span>{t('shareLinks.downloadCount', { count: share.download_count })}</span>
                  {share.has_password && <span className="text-amber-500">&#128274;</span>}
                </div>
              </div>
              <div className="flex items-center gap-2 shrink-0">
                <button
                  onClick={() => handleCopy(`${scheme}//${host}/gojs/share/${share.token}`)}
                  className="p-2 rounded-lg hover:bg-fg/5 text-fg-muted hover:text-fg"
                  title={t('shareLinks.copyLink')}
                >
                  {copiedToken === `${scheme}//${host}/gojs/share/${share.token}` ? <Check size={16} className="text-green-500" /> : <Copy size={16} />}
                </button>
                <button
                  onClick={() => handleRevoke(share.token)}
                  className="p-2 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 text-fg-muted hover:text-red-500"
                  title={t('shareLinks.revoke')}
                >
                  <X size={16} />
                </button>
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}