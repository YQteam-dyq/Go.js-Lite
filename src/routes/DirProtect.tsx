import { useState } from 'react'
import { useI18n } from '@/hooks/useI18n'
import { apiGet, apiPost } from '@/api'
import { Spinner } from '@/components/ui/Spinner'
import { Shield, ShieldOff, Plus, Trash2, AlertCircle, CheckCircle } from 'lucide-react'

interface DirProtectState {
  protected: boolean
  auth_name: string
  users: Array<{ username: string }>
}

export default function DirProtect() {
  const { t } = useI18n()
  const [path, setPath] = useState('')
  const [state, setState] = useState<DirProtectState | null>(null)
  const [loading, setLoading] = useState(false)
  const [authName, setAuthName] = useState('Restricted Area')
  const [newUser, setNewUser] = useState('')
  const [newPass, setNewPass] = useState('')
  const [statusMsg, setStatusMsg] = useState('')
  const [statusType, setStatusType] = useState<'success' | 'error'>('success')

  const checkStatus = async () => {
    if (!path) return
    setLoading(true)
    setStatusMsg('')
    try {
      const res = await apiGet('dir-protect/status', { path })
      if (res.ok) {
        setState(res.data)
        setAuthName(res.data.auth_name || 'Restricted Area')
      }
    } catch {}
    setLoading(false)
  }

  const handleEnable = async () => {
    if (!path) return
    setLoading(true)
    setStatusMsg('')
    try {
      const res = await apiPost('dir-protect/enable', { path, auth_name: authName, users: [] })
      if (res.ok) {
        setState({ protected: true, auth_name: authName, users: [] })
        setStatusMsg(t('common.success'))
        setStatusType('success')
      } else {
        setStatusMsg(res.error?.message || t('common.unknownError'))
        setStatusType('error')
      }
    } catch {
      setStatusMsg(t('common.unknownError'))
      setStatusType('error')
    }
    setLoading(false)
  }

  const handleDisable = async () => {
    if (!confirm(t('dirProtect.confirmDisable'))) return
    setLoading(true)
    try {
      await apiPost('dir-protect/disable', { path })
      setState({ protected: false, auth_name: '', users: [] })
      setStatusMsg(t('common.success'))
      setStatusType('success')
    } catch {
      setStatusMsg(t('common.unknownError'))
      setStatusType('error')
    }
    setLoading(false)
  }

  const handleAddUser = async () => {
    if (!newUser || !newPass) return
    try {
      const res = await apiPost('dir-protect/users', { path, action: 'add', username: newUser, password: newPass })
      if (res.ok) {
        setState(prev => prev ? { ...prev, users: [...prev.users, { username: newUser }] } : prev)
        setNewUser('')
        setNewPass('')
      }
    } catch {}
  }

  const handleDeleteUser = async (username: string) => {
    try {
      const res = await apiPost('dir-protect/users', { path, action: 'delete', username })
      if (res.ok) {
        setState(prev => prev ? { ...prev, users: prev.users.filter(u => u.username !== username) } : prev)
      }
    } catch {}
  }

  return (
    <div className="p-4 md:p-6 max-w-4xl mx-auto">
      <div className="mb-6">
        <h1 className="text-xl font-bold flex items-center gap-2"><Shield size={22} />{t('dirProtect.title')}</h1>
        <p className="text-sm text-fg-muted mt-1">{t('dirProtect.subtitle')}</p>
      </div>

      <div className="border border-border rounded-xl p-4 bg-bg-elevated space-y-4">
        <div className="flex gap-3">
          <input
            type="text"
            value={path}
            onChange={e => setPath(e.target.value)}
            placeholder="/path/to/directory"
            className="flex-1 px-3 py-2 rounded-lg border border-border bg-bg text-sm focus:outline-none focus:ring-2 focus:ring-accent/50"
          />
          <button
            onClick={checkStatus}
            disabled={!path || loading}
            className="px-4 py-2 rounded-lg border border-border text-sm hover:bg-fg/5 disabled:opacity-50"
          >
            {loading ? <Spinner size="sm" /> : t('common.search')}
          </button>
        </div>

        {statusMsg && (
          <div className={`p-3 rounded-lg flex items-center gap-2 text-sm $$
            statusType === 'success'
              ? 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300 border border-green-200 dark:border-green-800'
              : 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 border border-red-200 dark:border-red-800'
          }`}>
            {statusType === 'success' ? <CheckCircle size={16} /> : <AlertCircle size={16} />}
            {statusMsg}
          </div>
        )}

        {state && (
          <div className="space-y-4">
            <div className="flex items-center gap-3">
              <span className="text-sm font-medium">{t(state.protected ? 'dirProtect.protected' : 'dirProtect.notProtected')}</span>
              {state.protected ? (
                <Shield size={18} className="text-green-500" />
              ) : (
                <ShieldOff size={18} className="text-fg-muted" />
              )}
              <div className="flex-1" />
              {state.protected ? (
                <button onClick={handleDisable} className="text-sm px-3 py-1.5 rounded-lg border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">
                  {t('dirProtect.disable')}
                </button>
              ) : (
                <button onClick={handleEnable} className="text-sm px-3 py-1.5 rounded-lg bg-accent text-white hover:bg-accent/90">
                  {t('dirProtect.enable')}
                </button>
              )}
            </div>

            {state.protected && (
              <>
                <div>
                  <label className="text-xs text-fg-muted block mb-1">{t('dirProtect.authName')}</label>
                  <input
                    type="text"
                    value={authName}
                    onChange={e => setAuthName(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg border border-border bg-bg text-sm focus:outline-none focus:ring-2 focus:ring-accent/50"
                  />
                </div>

                <div>
                  <h3 className="text-sm font-medium mb-2">{t('dirProtect.users')}</h3>
                  {state.users.length === 0 && (
                    <p className="text-xs text-fg-muted">{t('dirProtect.noUsers')}</p>
                  )}
                  <div className="space-y-1 mb-3">
                    {state.users.map(user => (
                      <div key={user.username} className="flex items-center justify-between px-3 py-2 rounded-lg bg-bg-sunken text-sm">
                        <span>{user.username}</span>
                        <button onClick={() => handleDeleteUser(user.username)} className="p-1 text-fg-muted hover:text-red-500">
                          <Trash2 size={14} />
                        </button>
                      </div>
                    ))}
                  </div>
                  <div className="flex gap-2">
                    <input
                      type="text"
                      value={newUser}
                      onChange={e => setNewUser(e.target.value)}
                      placeholder={t('dirProtect.username')}
                      className="flex-1 px-3 py-2 rounded-lg border border-border bg-bg text-sm focus:outline-none focus:ring-2 focus:ring-accent/50"
                    />
                    <input
                      type="password"
                      value={newPass}
                      onChange={e => setNewPass(e.target.value)}
                      placeholder={t('dirProtect.password')}
                      className="flex-1 px-3 py-2 rounded-lg border border-border bg-bg text-sm focus:outline-none focus:ring-2 focus:ring-accent/50"
                    />
                    <button
                      onClick={handleAddUser}
                      disabled={!newUser || !newPass}
                      className="px-3 py-2 rounded-lg bg-accent text-white text-sm hover:bg-accent/90 disabled:opacity-50 flex items-center gap-1"
                    >
                      <Plus size={14} />{t('dirProtect.addUser')}
                    </button>
                  </div>
                </div>
              </>
            )}
          </div>
        )}
      </div>
    </div>
  )
}