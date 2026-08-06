import { useState, useEffect } from 'react'
import { useI18n } from '@/hooks/useI18n'
import { apiGet, apiPost } from '@/api'
import { Spinner } from '@/components/ui/Spinner'
import { ExternalLink, Download, Trash2, CheckCircle, AlertCircle, Package } from 'lucide-react'

interface AppMeta {
  id: string
  name: string
  description: string
  version: string
  website: string
  icon: string
  category: string
  installed: boolean
}

export default function AppStore() {
  const { t } = useI18n()
  const [apps, setApps] = useState<AppMeta[]>([])
  const [loading, setLoading] = useState(true)
  const [installing, setInstalling] = useState<string | null>(null)
  const [error, setError] = useState('')

  const loadApps = async () => {
    setLoading(true)
    setError('')
    try {
      const res = await apiGet<{ apps: AppMeta[] }>('appstore/list')
      if (res.ok && res.data) setApps(res.data.apps || [])
      else setError(t('common.unknownError'))
    } catch {
      setError(t('common.unknownError'))
    }
    setLoading(false)
  }

  useEffect(() => { loadApps() }, [])

  const handleInstall = async (appId: string) => {
    setInstalling(appId)
    setError('')
    try {
      const res = await apiPost('appstore/install', { app_id: appId })
      if (res.ok) {
        setApps(prev => prev.map(a => a.id === appId ? { ...a, installed: true } : a))
      } else {
        setError(t('appStore.installFailed', { name: appId }))
      }
    } catch {
      setError(t('appStore.installFailed', { name: appId }))
    }
    setInstalling(null)
  }

  const handleUninstall = async (appId: string) => {
    if (!confirm(t('common.confirm'))) return
    setInstalling(appId)
    setError('')
    try {
      const res = await apiPost('appstore/uninstall', { app_id: appId })
      if (res.ok) {
        setApps(prev => prev.map(a => a.id === appId ? { ...a, installed: false } : a))
      } else {
        setError(t('appStore.uninstallSuccess', { name: appId }))
      }
    } catch {
      setError(t('appStore.installFailed', { name: appId }))
    }
    setInstalling(null)
  }

  if (loading) return <div className="p-8 flex justify-center"><Spinner /></div>

  return (
    <div className="p-4 md:p-6 max-w-4xl mx-auto">
      <div className="mb-6">
        <h1 className="text-xl font-bold flex items-center gap-2"><Package size={22} />{t('appStore.title')}</h1>
        <p className="text-sm text-fg-muted mt-1">{t('appStore.subtitle')}</p>
      </div>

      {error && (
        <div className="mb-4 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg flex items-center gap-2 text-sm text-red-700 dark:text-red-300">
          <AlertCircle size={16} />{error}
        </div>
      )}

      {apps.length === 0 && !loading && (
        <div className="text-center py-12 text-fg-muted">{t('appStore.noApps')}</div>
      )}

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {apps.map(app => (
          <div key={app.id} className="border border-border rounded-xl p-4 bg-bg-elevated hover:shadow-sm transition-shadow">
            <div className="flex items-start gap-3">
              {app.icon ? (
                <img src={app.icon} alt={app.name} className="w-10 h-10 rounded-lg object-contain" />
              ) : (
                <div className="w-10 h-10 rounded-lg bg-accent/10 flex items-center justify-center text-accent font-bold text-lg">
                  {app.name[0]}
                </div>
              )}
              <div className="flex-1 min-w-0">
                <h3 className="font-semibold text-sm">{app.name}</h3>
                <p className="text-xs text-fg-muted mt-0.5 line-clamp-2">{app.description}</p>
                <div className="flex items-center gap-3 mt-2 text-xs text-fg-subtle">
                  <span>{t('appStore.version')}: {app.version}</span>
                  <span>{t('appStore.category')}: {app.category}</span>
                </div>
              </div>
            </div>
            <div className="flex items-center gap-2 mt-3 pt-3 border-t border-border">
              {app.website && (
                <a href={app.website} target="_blank" rel="noopener noreferrer" className="text-xs text-accent hover:underline flex items-center gap-1">
                  <ExternalLink size={12} />{t('appStore.website')}
                </a>
              )}
              <div className="flex-1" />
              {app.installed ? (
                <button
                  onClick={() => handleUninstall(app.id)}
                  disabled={installing === app.id}
                  className="text-xs px-3 py-1.5 rounded-lg border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 disabled:opacity-50 flex items-center gap-1"
                >
                  {installing === app.id ? <Spinner size="sm" /> : <Trash2 size={12} />}
                  {t('appStore.uninstall')}
                </button>
              ) : (
                <button
                  onClick={() => handleInstall(app.id)}
                  disabled={installing === app.id}
                  className="text-xs px-3 py-1.5 rounded-lg bg-accent text-white hover:bg-accent/90 disabled:opacity-50 flex items-center gap-1"
                >
                  {installing === app.id ? <Spinner size="sm" /> : <Download size={12} />}
                  {t('appStore.install')}
                </button>
              )}
              {app.installed && <CheckCircle size={14} className="text-green-500 shrink-0" />}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}