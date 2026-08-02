import { useState, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  HardDriveUpload,
  Plus,
  RefreshCw,
  Download,
  ChevronDown,
  CheckCircle2,
  AlertTriangle,
  ShieldX,
  Pencil,
  KeyRound,
  Trash2,
  LogIn,
  XCircle,
  Folder,
  Gauge,
  Users,
  Clock,
  ToggleLeft,
  ToggleRight,
  Lock,
} from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input, Textarea } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Spinner } from '@/components/ui/Spinner'
import { Modal, Confirm } from '@/components/ui/Modal'
import { DropdownMenu, MenuItem } from '@/components/ui/DropdownMenu'
import { EmptyState } from '@/components/ui/EmptyState'
import { ftpApi } from '@/api/ftp'
import { toast } from '@/components/ui/Toast'
import { useI18n } from '@/hooks/useI18n'
import { useIsMobile } from '@/hooks/useMediaQuery'
import { useFormat } from '@/lib/format'
import type { FtpAccount, FtpProvider, FtpAccountCreateInput, FtpAccountUpdateInput } from '@shared/types'

type ModalTab = 'general' | 'posix' | 'quota' | 'bandwidth'

interface AccountForm extends Partial<FtpAccountCreateInput> {
  confirm_password?: string
}

function emptyForm(defaults: Partial<AccountForm> = {}): AccountForm {
  return {
    username: '',
    password: '',
    confirm_password: '',
    home_dir: '',
    enabled: true,
    expires_at_ts: null,
    quota_size_mb: null,
    quota_files: null,
    upload_bw_kbps: null,
    download_bw_kbps: null,
    allow_client_ips: '',
    deny_client_ips: '',
    ...defaults,
  }
}

function passwordStrength(pw: string): { score: 0 | 1 | 2 | 3 | 4; label: string } {
  if (!pw) return { score: 0, label: '' }
  let score = 0
  if (pw.length >= 8) score++
  if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++
  if (/\d/.test(pw)) score++
  if (/[^A-Za-z0-9]/.test(pw)) score++
  const labels = ['ftp.pwWeak', 'ftp.pwFair', 'ftp.pwGood', 'ftp.pwStrong', 'ftp.pwStrong']
  return { score: score as 0 | 1 | 2 | 3 | 4, label: labels[score] }
}

function accountStatus(acc: FtpAccount): { variant: 'success' | 'warning' | 'danger' | 'muted'; key: string } {
  const now = Math.floor(Date.now() / 1000)
  if (!acc.enabled) return { variant: 'muted', key: 'ftp.statusDisabled' }
  if (acc.expires_at_ts && acc.expires_at_ts < now) return { variant: 'danger', key: 'ftp.statusExpired' }
  return { variant: 'success', key: 'ftp.statusEnabled' }
}

function homeDirDisplay(path: string): string {
  return path || '/'
}

export default function Ftp() {
  const { t, hasKey } = useI18n()
  const isMobile = useIsMobile()
  const queryClient = useQueryClient()
  const { formatRelativeTime } = useFormat()

  const [editing, setEditing] = useState(false)
  const [editAccount, setEditAccount] = useState<FtpAccount | null>(null)
  const [form, setForm] = useState<AccountForm>(emptyForm())
  const [activeTab, setActiveTab] = useState<ModalTab>('general')
  const [resetAccount, setResetAccount] = useState<FtpAccount | null>(null)
  const [resetPw1, setResetPw1] = useState('')
  const [resetPw2, setResetPw2] = useState('')
  const [testLoginAccount, setTestLoginAccount] = useState<FtpAccount | null>(null)
  const [testLoginPw, setTestLoginPw] = useState('')
  const [deleteId, setDeleteId] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState(false)
  const [syncing, setSyncing] = useState(false)
  const [exportingProftpd, setExportingProftpd] = useState(false)
  const [exportingPureftpd, setExportingPureftpd] = useState(false)

  const { data: caps, isLoading: loadingCaps } = useQuery({
    queryKey: ['ftp-capabilities'],
    queryFn: () => ftpApi.capabilities(),
    staleTime: 60_000,
  })

  const { data: accounts, isLoading: loadingAccounts, error, refetch } = useQuery({
    queryKey: ['ftp-accounts'],
    queryFn: () => ftpApi.list(),
  })

  const createMutation = useMutation({
    mutationFn: (data: FtpAccountCreateInput) => ftpApi.create(data),
    onSuccess: () => {
      toast({ type: 'success', title: t('ftp.accountCreated') })
      queryClient.invalidateQueries({ queryKey: ['ftp-accounts'] })
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.saveFailed'), description: err.message })
    },
  })

  const updateMutation = useMutation({
    mutationFn: (params: { id: string; data: FtpAccountUpdateInput }) => ftpApi.update(params.id, params.data),
    onSuccess: () => {
      toast({ type: 'success', title: t('ftp.accountUpdated') })
      queryClient.invalidateQueries({ queryKey: ['ftp-accounts'] })
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.saveFailed'), description: err.message })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string) => ftpApi.remove(id),
    onSuccess: () => {
      toast({ type: 'success', title: t('ftp.accountDeleted') })
      queryClient.invalidateQueries({ queryKey: ['ftp-accounts'] })
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.deleteFailed'), description: err.message })
    },
  })

  const openNew = () => {
    setEditAccount(null)
    const defaults: AccountForm = {
      uid: caps?.default_uid,
      gid: caps?.default_gid,
      enabled: true,
    }
    setForm(emptyForm(defaults))
    setActiveTab('general')
    setEditing(true)
  }

  const openEdit = (acc: FtpAccount) => {
    setEditAccount(acc)
    setForm({
      username: acc.username,
      password: '',
      confirm_password: '',
      home_dir: acc.home_dir,
      uid: acc.uid,
      gid: acc.gid,
      quota_size_mb: acc.quota_size_mb ?? null,
      quota_files: acc.quota_files ?? null,
      upload_bw_kbps: acc.upload_bw_kbps ?? null,
      download_bw_kbps: acc.download_bw_kbps ?? null,
      allow_client_ips: acc.allow_client_ips ?? '',
      deny_client_ips: acc.deny_client_ips ?? '',
      enabled: acc.enabled,
      expires_at_ts: acc.expires_at_ts ?? null,
    })
    setActiveTab('general')
    setEditing(true)
  }

  const validateAndSave = async () => {
    if (!form.username || !/^[a-zA-Z0-9_]{3,32}$/.test(form.username)) {
      toast({ type: 'warning', title: '用户名格式无效（3-32位字母数字下划线）' })
      return
    }
    if (!editAccount && (!form.password || form.password.length < 8)) {
      toast({ type: 'warning', title: t('ftp.pwMin8') })
      return
    }
    if (form.password && form.password !== form.confirm_password) {
      toast({ type: 'warning', title: t('ftp.pwMismatch') })
      return
    }
    if (!form.home_dir || form.home_dir.includes('..')) {
      toast({ type: 'warning', title: '家目录无效' })
      return
    }

    setSaving(true)
    try {
      const base: FtpAccountCreateInput | FtpAccountUpdateInput = {
        username: form.username,
        home_dir: form.home_dir,
        uid: form.uid,
        gid: form.gid,
        quota_size_mb: form.quota_size_mb ?? null,
        quota_files: form.quota_files ?? null,
        upload_bw_kbps: form.upload_bw_kbps ?? null,
        download_bw_kbps: form.download_bw_kbps ?? null,
        allow_client_ips: form.allow_client_ips,
        deny_client_ips: form.deny_client_ips,
        enabled: form.enabled,
        expires_at_ts: form.expires_at_ts ?? null,
      }
      if (editAccount) {
        const data: FtpAccountUpdateInput = { ...base }
        if (form.password) data.password_renew = form.password
        await updateMutation.mutateAsync({ id: editAccount.id, data })
      } else {
        await createMutation.mutateAsync({ ...base, password: form.password! } as FtpAccountCreateInput)
      }
      setEditing(false)
    } finally {
      setSaving(false)
    }
  }

  const handleResetPassword = async () => {
    if (!resetAccount) return
    if (resetPw1.length < 8) {
      toast({ type: 'warning', title: t('ftp.pwMin8') })
      return
    }
    if (resetPw1 !== resetPw2) {
      toast({ type: 'warning', title: t('ftp.pwMismatch') })
      return
    }
    setSaving(true)
    try {
      await updateMutation.mutateAsync({ id: resetAccount.id, data: { password_renew: resetPw1 } })
      setResetAccount(null)
      setResetPw1('')
      setResetPw2('')
    } finally {
      setSaving(false)
    }
  }

  const handleTestLogin = async () => {
    if (!testLoginAccount) return
    if (!testLoginPw) {
      toast({ type: 'warning', title: '请输入密码' })
      return
    }
    setTesting(true)
    try {
      const res = await ftpApi.testLogin(testLoginAccount.id, testLoginPw)
      if (res.ok) {
        toast({ type: 'success', title: t('ftp.testLoginSuccess') })
      } else {
        toast({ type: 'error', title: t('ftp.testLoginFailed') })
      }
      setTestLoginAccount(null)
      setTestLoginPw('')
      queryClient.invalidateQueries({ queryKey: ['ftp-accounts'] })
    } catch (err) {
      toast({
        type: 'error',
        title: t('ftp.testLoginFailed'),
        description: err instanceof Error ? err.message : undefined,
      })
    } finally {
      setTesting(false)
    }
  }

  const handleConfirmDelete = async () => {
    if (!deleteId) return
    try {
      await deleteMutation.mutateAsync(deleteId)
      setDeleteId(null)
    } catch {
      // handled in onError
    }
  }

  const handleSync = async () => {
    setSyncing(true)
    try {
      const res = await ftpApi.sync()
      toast({ type: 'success', title: `已同步 ${res.count} 个账户` })
    } catch (err) {
      toast({
        type: 'error',
        title: '同步失败',
        description: err instanceof Error ? err.message : undefined,
      })
    } finally {
      setSyncing(false)
    }
  }

  const handleExport = async (provider: FtpProvider, setBusy: (v: boolean) => void) => {
    setBusy(true)
    try {
      const blob = await ftpApi.exportBlob(provider)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = provider === 'pureftpd_passwd' ? 'pureftpd.passwd' : 'ftpd.passwd'
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      setTimeout(() => URL.revokeObjectURL(url), 5000)
      toast({ type: 'success', title: '导出成功' })
    } catch (err) {
      toast({
        type: 'error',
        title: '导出失败',
        description: err instanceof Error ? err.message : undefined,
      })
    } finally {
      setBusy(false)
    }
  }

  const hasActiveProvider = !!caps?.active_provider
  const bannerVariant = !caps?.active_provider ? 'warning' : caps?.can_write ? 'success' : 'danger'
  const strength = useMemo(() => passwordStrength(form.password || ''), [form.password])
  const resetStrength = useMemo(() => passwordStrength(resetPw1), [resetPw1])

  return (
    <div className="p-4 md:p-6 space-y-5">
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
              <HardDriveUpload size={22} className="text-accent" />
              {t('nav.ftp')}
            </h1>
            {hasActiveProvider && caps?.can_write && (
              <Badge variant="success" className="text-[10px] px-1.5 py-0.5">
                <CheckCircle2 size={10} className="inline mr-0.5" />
                Auto-sync
              </Badge>
            )}
            {!hasActiveProvider && (
              <Badge variant="warning" className="text-[10px] px-1.5 py-0.5">
                Virtual
              </Badge>
            )}
            {hasActiveProvider && !caps?.can_write && (
              <Badge variant="danger" className="text-[10px] px-1.5 py-0.5">
                Read-only
              </Badge>
            )}
          </div>
          <p className="text-sm text-fg-muted mt-0.5">ProFTPD / Pure-FTPd compatible</p>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          <Button variant="secondary" size="sm" onClick={() => refetch()} disabled={loadingAccounts}>
            <RefreshCw size={16} className={loadingAccounts ? 'animate-spin' : ''} />
            <span className="hidden sm:inline">{t('common.refresh')}</span>
          </Button>
          {hasActiveProvider && (
            <Button variant="secondary" size="sm" onClick={handleSync} loading={syncing} disabled={!caps?.can_write}>
              {t('ftp.syncNow')}
            </Button>
          )}
          <DropdownMenu
            trigger={
              <Button variant="secondary" size="sm">
                <Download size={16} />
                <span className="hidden sm:inline">{t('ftp.exportMenu')}</span>
                <ChevronDown size={14} />
              </Button>
            }
          >
            <MenuItem
              icon={<Download size={14} />}
              label={t('ftp.exportProftpd')}
              onClick={() => handleExport('proftpd_authfile', setExportingProftpd)}
              disabled={exportingProftpd}
            />
            <MenuItem
              icon={<Download size={14} />}
              label={t('ftp.exportPureftpd')}
              onClick={() => handleExport('pureftpd_passwd', setExportingPureftpd)}
              disabled={exportingPureftpd}
            />
          </DropdownMenu>
          <Button variant="primary" size="sm" onClick={openNew}>
            <Plus size={16} />
            <span className="hidden sm:inline">{t('ftp.newAccount')}</span>
          </Button>
        </div>
      </div>

      {/* Capability banner */}
      {loadingCaps ? (
        <Card>
          <CardBody className="flex items-center justify-center py-6">
            <Spinner />
          </CardBody>
        </Card>
      ) : bannerVariant === 'success' ? (
        <Card className="border-success/30 bg-success/[0.03]">
          <CardBody className="flex items-start gap-3">
            <div className="w-10 h-10 rounded-lg bg-success/10 text-success flex items-center justify-center shrink-0">
              <CheckCircle2 size={20} />
            </div>
            <div className="min-w-0 flex-1">
              <div className="text-sm font-medium text-fg">
                Active provider: {caps?.active_provider === 'proftpd_authfile' ? t('ftp.providerProftpd') : t('ftp.providerPureftpd')}
              </div>
              <p className="text-xs text-fg-muted mt-1 leading-relaxed break-all">
                {caps?.path} · File writable. {t('ftp.autoSync')}
              </p>
            </div>
          </CardBody>
        </Card>
      ) : bannerVariant === 'warning' ? (
        <Card className="border-warning/30 bg-warning/[0.03]">
          <CardBody className="flex items-start gap-3">
            <div className="w-10 h-10 rounded-lg bg-warning/10 text-warning flex items-center justify-center shrink-0">
              <AlertTriangle size={20} />
            </div>
            <div className="min-w-0 flex-1">
              <div className="text-sm font-medium text-fg">{t('ftp.providerVirtual')}</div>
              <p className="text-xs text-fg-muted mt-1 leading-relaxed">
                {t('ftp.virtualModeHint')}
              </p>
            </div>
          </CardBody>
        </Card>
      ) : (
        <Card className="border-danger/30 bg-danger/[0.03]">
          <CardBody className="flex items-start gap-3">
            <div className="w-10 h-10 rounded-lg bg-danger/10 text-danger flex items-center justify-center shrink-0">
              <ShieldX size={20} />
            </div>
            <div className="min-w-0 flex-1">
              <div className="text-sm font-medium text-fg">
                {caps?.active_provider === 'proftpd_authfile' ? t('ftp.providerProftpd') : t('ftp.providerPureftpd')}
              </div>
              <p className="text-xs text-fg-muted mt-1 leading-relaxed break-all">
                {caps?.path} · {t('ftp.fileNotWritable')}
              </p>
            </div>
          </CardBody>
        </Card>
      )}

      {/* Accounts list */}
      <Card>
        <CardHeader className="flex items-center justify-between gap-2">
          <div className="flex items-center gap-2">
            <Users size={16} className="text-fg-muted" />
            <div>
              <div className="text-sm font-medium text-fg">{t('nav.ftp')}</div>
              <div className="text-xs text-fg-subtle">虚拟账户管理</div>
            </div>
          </div>
          {accounts && <Badge variant="muted">{accounts.length} 个账户</Badge>}
        </CardHeader>
        <CardBody className="p-0">
          {loadingAccounts ? (
            <div className="p-8 flex justify-center">
              <Spinner />
            </div>
          ) : error ? (
            <div className="p-6 text-center text-sm text-danger">
              <XCircle size={24} className="mx-auto mb-2" />
              <p>{error instanceof Error ? error.message : t('common.error')}</p>
              <Button variant="secondary" size="sm" className="mt-3" onClick={() => refetch()}>
                {t('common.retry')}
              </Button>
            </div>
          ) : !accounts || accounts.length === 0 ? (
            <EmptyState
              title={t('nav.ftp')}
              description="还没有 FTP 账户。点击右上角「新建账户」创建第一个。"
              icon={<HardDriveUpload size={36} className="opacity-40" />}
            />
          ) : isMobile ? (
            <ul className="divide-y divide-border">
              {accounts.map((acc) => (
                <MobileAccountCard
                  key={acc.id}
                  acc={acc}
                  onEdit={() => openEdit(acc)}
                  onReset={() => setResetAccount(acc)}
                  onTest={() => setTestLoginAccount(acc)}
                  onDelete={() => setDeleteId(acc.id)}
                  t={t}
                  formatRelativeTime={formatRelativeTime}
                />
              ))}
            </ul>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-xs text-fg-muted border-b border-border">
                    <th className="px-4 py-3 font-medium">用户名</th>
                    <th className="px-4 py-3 font-medium">家目录</th>
                    <th className="px-4 py-3 font-medium whitespace-nowrap">配额</th>
                    <th className="px-4 py-3 font-medium whitespace-nowrap">带宽</th>
                    <th className="px-4 py-3 font-medium whitespace-nowrap">上次登录</th>
                    <th className="px-4 py-3 font-medium whitespace-nowrap">状态</th>
                    <th className="px-4 py-3 font-medium text-right whitespace-nowrap">{t('common.actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {accounts.map((acc) => {
                    const st = accountStatus(acc)
                    return (
                      <tr key={acc.id} className="border-b border-border/50 last:border-b-0 hover:bg-fg/[0.02] transition-colors">
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-2">
                            <div className="w-8 h-8 rounded-lg bg-accent/10 text-accent flex items-center justify-center shrink-0">
                              <Users size={14} />
                            </div>
                            <div className="min-w-0">
                              <div className="font-medium text-fg truncate">{acc.username}</div>
                              <div className="text-[11px] text-fg-subtle">UID {acc.uid ?? '—'} · GID {acc.gid ?? '—'}</div>
                            </div>
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-1.5 text-fg-muted">
                            <Folder size={13} className="shrink-0 opacity-60" />
                            <span className="font-mono text-xs truncate max-w-[220px]" title={acc.home_dir}>
                              {homeDirDisplay(acc.home_dir)}
                            </span>
                          </div>
                        </td>
                        <td className="px-4 py-3 text-fg-muted text-xs">
                          {(acc.quota_size_mb || acc.quota_files) ? (
                            <div className="space-y-0.5">
                              {acc.quota_size_mb && <div>{acc.quota_size_mb} MB</div>}
                              {acc.quota_files && <div className="text-fg-subtle">{acc.quota_files} files</div>}
                            </div>
                          ) : (
                            <span className="text-fg-subtle italic">Unlimited</span>
                          )}
                        </td>
                        <td className="px-4 py-3 text-fg-muted text-xs whitespace-nowrap">
                          {(acc.upload_bw_kbps || acc.download_bw_kbps) ? (
                            <div className="space-y-0.5">
                              {acc.upload_bw_kbps && <div>↑ {acc.upload_bw_kbps} kbps</div>}
                              {acc.download_bw_kbps && <div>↓ {acc.download_bw_kbps} kbps</div>}
                            </div>
                          ) : (
                            <span className="text-fg-subtle italic">Unlimited</span>
                          )}
                        </td>
                        <td className="px-4 py-3 text-fg-muted text-xs whitespace-nowrap">
                          {acc.last_login_at ? formatRelativeTime(acc.last_login_at) : (
                            <span className="text-fg-subtle italic">Never</span>
                          )}
                        </td>
                        <td className="px-4 py-3">
                          <Badge variant={st.variant}>
                            {hasKey(st.key) ? t(st.key) : (st.variant === 'success' ? 'Enabled' : st.variant === 'danger' ? 'Expired' : st.variant === 'warning' ? 'Warning' : 'Disabled')}
                          </Badge>
                        </td>
                        <td className="px-4 py-3">
                          <div className="flex items-center justify-end gap-1">
                            <Button variant="ghost" size="icon-sm" onClick={() => setTestLoginAccount(acc)} title={t('ftp.testLoginTitle')}>
                              <LogIn size={14} />
                            </Button>
                            <Button variant="ghost" size="icon-sm" onClick={() => openEdit(acc)} title={t('common.edit')}>
                              <Pencil size={14} />
                            </Button>
                            <Button variant="ghost" size="icon-sm" onClick={() => setResetAccount(acc)} title={t('ftp.resetPassword')}>
                              <KeyRound size={14} />
                            </Button>
                            <Button variant="ghost" size="icon-sm" onClick={() => setDeleteId(acc.id)} title={t('common.delete')} className="text-danger hover:text-danger">
                              <Trash2 size={14} />
                            </Button>
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      {/* New / Edit Modal */}
      <Modal
        open={editing}
        onClose={() => !saving && setEditing(false)}
        title={editAccount ? '编辑账户' : t('ftp.newAccount')}
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setEditing(false)} disabled={saving}>
              {t('common.cancel')}
            </Button>
            <Button onClick={validateAndSave} loading={saving}>
              {t('common.save')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          {/* Tabs */}
          <div className="flex items-center gap-1 border-b border-border -mx-5 -mt-4 px-5 mb-4 overflow-x-auto">
            {(['general', 'posix', 'quota', 'bandwidth'] as ModalTab[]).map((tab) => {
              const icons = { general: Users, posix: Gauge, quota: HardDriveUpload, bandwidth: Gauge }
              const Icon = icons[tab]
              const labelKey = `ftp.${tab}Tab` as const
              return (
                <button
                  key={tab}
                  type="button"
                  onClick={() => setActiveTab(tab)}
                  className={`inline-flex items-center gap-1.5 px-3 py-2.5 text-sm border-b-2 -mb-px transition-colors whitespace-nowrap ${
                    activeTab === tab
                      ? 'border-accent text-accent font-medium'
                      : 'border-transparent text-fg-muted hover:text-fg'
                  }`}
                >
                  <Icon size={14} />
                  {t(labelKey)}
                </button>
              )
            })}
          </div>

          {activeTab === 'general' && (
            <div className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-fg-muted mb-1.5">{t('ftp.username')}</label>
                <Input
                  value={form.username || ''}
                  onChange={(e) => setForm({ ...form, username: e.target.value })}
                  placeholder="e.g. user_blog"
                  invalid={Boolean(form.username) && !/^[a-zA-Z0-9_]{3,32}$/.test(form.username as string)}
                />
                <p className="text-xs text-fg-subtle mt-1">3-32 位字母、数字或下划线</p>
              </div>
              <div>
                <label className="block text-xs font-medium text-fg-muted mb-1.5">
                  {t('ftp.password')}
                  <span className="ml-1 text-fg-subtle font-normal">
                    {editAccount ? '（留空则不修改）' : ''}
                  </span>
                </label>
                <Input
                  type="password"
                  value={form.password || ''}
                  onChange={(e) => setForm({ ...form, password: e.target.value })}
                  placeholder={editAccount ? '留空不修改' : '至少 8 个字符'}
                  invalid={Boolean(form.password) && (form.password as string).length < 8}
                />
                {form.password && (
                  <div className="mt-2">
                    <div className="flex gap-1 mb-1">
                      {[0, 1, 2, 3].map((i) => (
                        <div
                          key={i}
                          className={`h-1.5 flex-1 rounded-full transition-colors ${
                            strength.score > i
                              ? strength.score <= 1
                                ? 'bg-danger'
                                : strength.score === 2
                                ? 'bg-warning'
                                : 'bg-success'
                              : 'bg-border'
                          }`}
                        />
                      ))}
                    </div>
                    <p className="text-xs text-fg-muted">
                      {strength.label && hasKey(strength.label) ? t(strength.label) : ''}
                    </p>
                  </div>
                )}
                <p className="text-xs text-fg-subtle mt-1">{t('ftp.passwordHint')}</p>
              </div>
              {form.password && (
                <div>
                  <label className="block text-xs font-medium text-fg-muted mb-1.5">确认密码</label>
                  <Input
                    type="password"
                    value={form.confirm_password || ''}
                    onChange={(e) => setForm({ ...form, confirm_password: e.target.value })}
                    invalid={form.confirm_password !== '' && form.confirm_password !== form.password}
                    placeholder="请再次输入密码"
                  />
                </div>
              )}
              <div>
                <label className="block text-xs font-medium text-fg-muted mb-1.5">{t('ftp.homeDir')}</label>
                <Input
                  value={form.home_dir || ''}
                  onChange={(e) => setForm({ ...form, home_dir: e.target.value })}
                  placeholder="/public_html/siteA 或绝对路径"
                  invalid={Boolean(form.home_dir) && ((form.home_dir as string).includes('..') || (form.home_dir as string).trim() === '')}
                  icon={<Folder size={14} className="opacity-50" />}
                />
                <p className="text-xs text-fg-subtle mt-1">面板相对路径或绝对路径；不能包含 ..</p>
              </div>
              <div className="flex items-center gap-4 flex-wrap">
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    onClick={() => setForm({ ...form, enabled: !form.enabled })}
                    className="text-accent"
                  >
                    {form.enabled ? <ToggleRight size={26} /> : <ToggleLeft size={26} className="opacity-60" />}
                  </button>
                  <span className="text-sm text-fg">{t('ftp.enabled')}</span>
                </div>
              </div>
              <div>
                <label className="block text-xs font-medium text-fg-muted mb-1.5">{t('ftp.expiresAt')}</label>
                <Input
                  type="datetime-local"
                  value={
                    form.expires_at_ts
                      ? new Date(form.expires_at_ts * 1000).toISOString().slice(0, 16)
                      : ''
                  }
                  onChange={(e) => {
                    const v = e.target.value
                    setForm({
                      ...form,
                      expires_at_ts: v ? Math.floor(new Date(v).getTime() / 1000) : null,
                    })
                  }}
                />
                <p className="text-xs text-fg-subtle mt-1">留空表示永不过期</p>
              </div>
            </div>
          )}

          {activeTab === 'posix' && (
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-medium text-fg-muted mb-1.5">{t('ftp.uid')}</label>
                <Input
                  type="number"
                  value={form.uid ?? ''}
                  onChange={(e) =>
                    setForm({ ...form, uid: e.target.value === '' ? undefined : parseInt(e.target.value, 10) })
                  }
                  placeholder={String(caps?.default_uid ?? 1000)}
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-fg-muted mb-1.5">{t('ftp.gid')}</label>
                <Input
                  type="number"
                  value={form.gid ?? ''}
                  onChange={(e) =>
                    setForm({ ...form, gid: e.target.value === '' ? undefined : parseInt(e.target.value, 10) })
                  }
                  placeholder={String(caps?.default_gid ?? 1000)}
                />
              </div>
            </div>
          )}

          {activeTab === 'quota' && (
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-medium text-fg-muted mb-1.5">{t('ftp.quotaSizeMb')}</label>
                <Input
                  type="number"
                  value={form.quota_size_mb ?? ''}
                  onChange={(e) =>
                    setForm({
                      ...form,
                      quota_size_mb: e.target.value === '' ? null : parseInt(e.target.value, 10),
                    })
                  }
                  placeholder="留空 = Unlimited"
                />
                <p className="text-xs text-fg-subtle mt-1">单位 MB，0 或空 = 不限</p>
              </div>
              <div>
                <label className="block text-xs font-medium text-fg-muted mb-1.5">{t('ftp.quotaFiles')}</label>
                <Input
                  type="number"
                  value={form.quota_files ?? ''}
                  onChange={(e) =>
                    setForm({
                      ...form,
                      quota_files: e.target.value === '' ? null : parseInt(e.target.value, 10),
                    })
                  }
                  placeholder="留空 = Unlimited"
                />
                <p className="text-xs text-fg-subtle mt-1">文件数上限，0 或空 = 不限</p>
              </div>
            </div>
          )}

          {activeTab === 'bandwidth' && (
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-medium text-fg-muted mb-1.5">{t('ftp.bandwidthUp')}</label>
                  <Input
                    type="number"
                    value={form.upload_bw_kbps ?? ''}
                    onChange={(e) =>
                      setForm({
                        ...form,
                        upload_bw_kbps: e.target.value === '' ? null : parseInt(e.target.value, 10),
                      })
                    }
                    placeholder="留空 = Unlimited"
                  />
                  <p className="text-xs text-fg-subtle mt-1">kbps，0 或空 = 不限</p>
                </div>
                <div>
                  <label className="block text-xs font-medium text-fg-muted mb-1.5">{t('ftp.bandwidthDown')}</label>
                  <Input
                    type="number"
                    value={form.download_bw_kbps ?? ''}
                    onChange={(e) =>
                      setForm({
                        ...form,
                        download_bw_kbps: e.target.value === '' ? null : parseInt(e.target.value, 10),
                      })
                    }
                    placeholder="留空 = Unlimited"
                  />
                  <p className="text-xs text-fg-subtle mt-1">kbps，0 或空 = 不限</p>
                </div>
              </div>
              <div>
                <label className="block text-xs font-medium text-fg-muted mb-1.5">{t('ftp.allowedIps')}</label>
                <Textarea
                  value={form.allow_client_ips || ''}
                  onChange={(e) => setForm({ ...form, allow_client_ips: e.target.value })}
                  placeholder="192.168.1.0/24, 10.0.0.1（逗号或换行分隔）"
                  rows={3}
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-fg-muted mb-1.5">{t('ftp.deniedIps')}</label>
                <Textarea
                  value={form.deny_client_ips || ''}
                  onChange={(e) => setForm({ ...form, deny_client_ips: e.target.value })}
                  placeholder="10.0.0.0/8（逗号或换行分隔）"
                  rows={3}
                />
              </div>
            </div>
          )}
        </div>
      </Modal>

      {/* Reset password modal */}
      <Modal
        open={resetAccount !== null}
        onClose={() => !saving && setResetAccount(null)}
        title={t('ftp.resetPasswordTitle')}
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setResetAccount(null)} disabled={saving}>
              {t('common.cancel')}
            </Button>
            <Button onClick={handleResetPassword} loading={saving}>
              {t('common.save')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <div className="flex items-center gap-2 p-3 rounded-lg bg-accent/5 border border-accent/20">
            <Users size={16} className="text-accent shrink-0" />
            <span className="text-sm text-fg font-medium">{resetAccount?.username}</span>
          </div>
          <div>
            <label className="block text-xs font-medium text-fg-muted mb-1.5">新密码</label>
            <Input
              type="password"
              value={resetPw1}
              onChange={(e) => setResetPw1(e.target.value)}
              invalid={resetPw1 !== '' && resetPw1.length < 8}
              placeholder="至少 8 个字符"
            />
            {resetPw1 && (
              <div className="mt-2">
                <div className="flex gap-1 mb-1">
                  {[0, 1, 2, 3].map((i) => (
                    <div
                      key={i}
                      className={`h-1.5 flex-1 rounded-full transition-colors ${
                        resetStrength.score > i
                          ? resetStrength.score <= 1
                            ? 'bg-danger'
                            : resetStrength.score === 2
                            ? 'bg-warning'
                            : 'bg-success'
                          : 'bg-border'
                      }`}
                    />
                  ))}
                </div>
              </div>
            )}
          </div>
          <div>
            <label className="block text-xs font-medium text-fg-muted mb-1.5">确认新密码</label>
            <Input
              type="password"
              value={resetPw2}
              onChange={(e) => setResetPw2(e.target.value)}
              invalid={resetPw2 !== '' && resetPw2 !== resetPw1}
              placeholder="请再次输入密码"
            />
          </div>
        </div>
      </Modal>

      {/* Test login modal */}
      <Modal
        open={testLoginAccount !== null}
        onClose={() => !testing && (setTestLoginAccount(null), setTestLoginPw(''))}
        title={t('ftp.testLoginTitle')}
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => { setTestLoginAccount(null); setTestLoginPw('') }} disabled={testing}>
              {t('common.cancel')}
            </Button>
            <Button onClick={handleTestLogin} loading={testing}>
              <LogIn size={14} />
              验证
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <div className="flex items-center gap-2 p-3 rounded-lg bg-success/5 border border-success/20">
            <Users size={16} className="text-success shrink-0" />
            <span className="text-sm text-fg font-medium">{testLoginAccount?.username}</span>
          </div>
          <div>
            <label className="block text-xs font-medium text-fg-muted mb-1.5">账户密码</label>
            <Input
              type="password"
              value={testLoginPw}
              onChange={(e) => setTestLoginPw(e.target.value)}
              placeholder="输入该账户的密码"
              icon={<Lock size={14} className="opacity-50" />}
              autoFocus
            />
            <p className="text-xs text-fg-subtle mt-1">仅本地验证密码哈希，不发起真实 FTP 连接</p>
          </div>
        </div>
      </Modal>

      <Confirm
        open={deleteId !== null}
        title="删除账户"
        message="确定要删除此 FTP 账户吗？此操作不可撤销。"
        variant="danger"
        confirmText={t('common.delete')}
        loading={saving}
        onConfirm={handleConfirmDelete}
        onCancel={() => setDeleteId(null)}
      />
    </div>
  )
}

interface MobileCardProps {
  acc: FtpAccount
  onEdit: () => void
  onReset: () => void
  onTest: () => void
  onDelete: () => void
  t: (k: string) => string
  formatRelativeTime: (ts: number) => string
}

function MobileAccountCard({ acc, onEdit, onReset, onTest, onDelete, t, formatRelativeTime }: MobileCardProps) {
  const st = accountStatus(acc)
  return (
    <li className="p-4 space-y-3">
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-center gap-2.5 min-w-0">
          <div className="w-9 h-9 rounded-xl bg-accent/10 text-accent flex items-center justify-center shrink-0">
            <Users size={16} />
          </div>
          <div className="min-w-0">
            <div className="font-medium text-fg truncate">{acc.username}</div>
            <div className="flex items-center gap-1.5 mt-0.5">
              <Badge variant={st.variant} className="text-[10px]">
                {st.variant === 'success' ? 'Enabled' : st.variant === 'danger' ? 'Expired' : st.variant === 'warning' ? 'Warning' : 'Disabled'}
              </Badge>
              {acc.last_login_at && (
                <span className="text-[10px] text-fg-subtle flex items-center gap-0.5">
                  <Clock size={10} />
                  {formatRelativeTime(acc.last_login_at)}
                </span>
              )}
            </div>
          </div>
        </div>
      </div>
      <div className="grid grid-cols-2 gap-3 text-xs">
        <div>
          <div className="text-fg-subtle mb-0.5">{t('ftp.homeDir')}</div>
          <div className="font-mono text-fg-muted truncate" title={acc.home_dir}>
            {homeDirDisplay(acc.home_dir)}
          </div>
        </div>
        <div>
          <div className="text-fg-subtle mb-0.5">UID/GID</div>
          <div className="text-fg-muted">{acc.uid ?? '—'} / {acc.gid ?? '—'}</div>
        </div>
        <div>
          <div className="text-fg-subtle mb-0.5">{t('ftp.quotaSizeMb')}</div>
          <div className="text-fg-muted">{acc.quota_size_mb ? `${acc.quota_size_mb} MB` : 'Unlimited'}</div>
        </div>
        <div>
          <div className="text-fg-subtle mb-0.5">带宽</div>
          <div className="text-fg-muted">
            {(acc.upload_bw_kbps || acc.download_bw_kbps)
              ? `↑${acc.upload_bw_kbps ?? '∞'} / ↓${acc.download_bw_kbps ?? '∞'}`
              : 'Unlimited'}
          </div>
        </div>
      </div>
      <div className="flex items-center justify-between pt-1 border-t border-border/50">
        <div className="flex items-center gap-1">
          <Button variant="ghost" size="icon-sm" onClick={onTest}>
            <LogIn size={13} />
          </Button>
          <Button variant="ghost" size="icon-sm" onClick={onEdit}>
            <Pencil size={13} />
          </Button>
          <Button variant="ghost" size="icon-sm" onClick={onReset}>
            <KeyRound size={13} />
          </Button>
        </div>
        <Button variant="ghost" size="icon-sm" onClick={onDelete} className="text-danger hover:text-danger">
          <Trash2 size={13} />
        </Button>
      </div>
    </li>
  )
}
