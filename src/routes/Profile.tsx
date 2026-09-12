import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { User, Save, Eye, EyeOff, Sun, Moon, Monitor, Check, Download } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { AvatarBadge, pickAvatarColor } from '@/components/ui/AvatarBadge'
import { toast } from '@/components/ui/Toast'
import { usersApi } from '@/api/users'
import { authApi } from '@/api/auth'
import { profileExportApi, parseExportPath } from '@/api/exports'
import { useTheme } from '@/hooks/useTheme'
import { useUiStore } from '@/stores/uiStore'
import { useAuth } from '@/hooks/useAuth'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'
import type { UserSettings } from '@shared/types'

export default function Profile() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const { user, logout } = useAuth()
  const { theme, setTheme } = useTheme()
  const language = useUiStore((s) => s.language)
  const setLanguage = useUiStore((s) => s.setLanguage)

  const { data: profile } = useQuery({
    queryKey: ['profile'],
    queryFn: () => usersApi.profile.get(),
  })

  const prefs: UserSettings | null = (profile?.preferences as any) || null

  const [oldPw, setOldPw] = useState('')
  const [newPw, setNewPw] = useState('')
  const [confirmPw, setConfirmPw] = useState('')
  const [showOld, setShowOld] = useState(false)
  const [showNew, setShowNew] = useState(false)
  const [showConfirm, setShowConfirm] = useState(false)

  useEffect(() => {
    const t2 = prefs?.theme
    if (t2 && (t2 === 'light' || t2 === 'dark' || t2 === 'system') && theme !== t2) {
      setTheme(t2 as any)
    }
  }, [prefs?.theme])

  const updatePrefsMutation = useMutation({
    mutationFn: (payload: Partial<UserSettings>) => usersApi.profile.update(payload),
    onSuccess: () => {
      toast({ type: 'success', title: t('profile.saved') })
      queryClient.invalidateQueries({ queryKey: ['profile'] })
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.saveFailed'), description: resolveErrorText(err) })
    },
  })

  const changePwMutation = useMutation({
    mutationFn: () => authApi.changePassword(oldPw, newPw),
    onSuccess: () => {
      toast({ type: 'success', title: t('profile.passwordChanged') })
      setOldPw(''); setNewPw(''); setConfirmPw('')
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('profile.changeFailed'), description: resolveErrorText(err) })
    },
  })

  const exportMutation = useMutation({
    mutationFn: () => profileExportApi.create(),
    onSuccess: async (res) => {
      const target = parseExportPath(res.download_path)
      if (!target) {
        toast({ type: 'error', title: t('profile.exportFailed') })
        return
      }
      try {
        const blob = await profileExportApi.download(target.export_id, target.exp, target.sig)
        const url = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = `gojs-export-${target.export_id}.zip`
        document.body.appendChild(a)
        a.click()
        a.remove()
        URL.revokeObjectURL(url)
        toast({ type: 'success', title: t('profile.exportReady') })
      } catch (err) {
        toast({ type: 'error', title: t('profile.exportFailed'), description: resolveErrorText(err) })
      }
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('profile.exportFailed'), description: resolveErrorText(err) })
    },
  })

  const handleSaveTheme = (next: 'light' | 'dark' | 'system') => {
    setTheme(next as any)
    updatePrefsMutation.mutate({ theme: next })
  }

  const handleSaveLanguage = (next: 'zh' | 'en') => {
    setLanguage(next as any)
    updatePrefsMutation.mutate({ language: next })
  }

  const handleChangePassword = () => {
    if (!oldPw || !newPw) {
      toast({ type: 'error', title: t('profile.fillAll') })
      return
    }
    if (newPw !== confirmPw) {
      toast({ type: 'error', title: t('profile.passwordsNotMatch') })
      return
    }
    if (newPw.length < 8) {
      toast({ type: 'error', title: t('profile.newPasswordMinLength') })
      return
    }
    changePwMutation.mutate()
  }

  const username = profile?.username || user?.username || 'admin'
  const role = profile?.role || 'admin'
  const color = profile?.avatar_color || pickAvatarColor(username)

  const themeOptions: Array<{ value: 'light' | 'dark' | 'system'; label: string; icon: any }> = [
    { value: 'light', label: t('settings.light'), icon: Sun },
    { value: 'dark',  label: t('settings.dark'),  icon: Moon },
    { value: 'system', label: t('settings.system'), icon: Monitor },
  ]
  const langOptions: Array<{ value: 'zh' | 'en'; label: string; flag: string }> = [
    { value: 'zh', label: t('settings.zh'), flag: '🇨🇳' },
    { value: 'en', label: t('settings.en'), flag: '🇺🇸' },
  ]

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-2xl mx-auto page-enter">
      <div className="stagger-1 flex items-center gap-4">
        <AvatarBadge username={username} color={color} size="lg" />
        <div className="flex-1">
          <h1 className="text-xl font-semibold text-fg">{username}</h1>
          <div className="flex items-center gap-2 mt-0.5">
            <Badge variant="muted">{role}</Badge>
            <span className="text-xs text-fg-subtle">{t('profile.subtitle')}</span>
          </div>
        </div>
      </div>

      <Card className="stagger-2 card-hover">
        <CardHeader>
          <div className="text-sm font-semibold text-fg">{t('profile.appearance')}</div>
          <div className="text-xs text-fg-subtle">{t('profile.appearanceDesc')}</div>
        </CardHeader>
        <CardBody className="space-y-5">
          <div>
            <label className="block text-sm font-medium text-fg mb-2">{t('settings.theme')}</label>
            <div className="grid grid-cols-3 gap-2">
              {themeOptions.map((opt) => {
                const Icon = opt.icon
                const active = theme === opt.value
                return (
                  <button
                    key={opt.value}
                    type="button"
                    aria-pressed={active}
                    aria-label={opt.label}
                    onClick={() => handleSaveTheme(opt.value)}
                    className={`flex flex-col items-center gap-2 py-3 px-2 rounded-xl border transition-all duration-200 ${
                      active
                        ? 'border-accent bg-accent/5 text-accent'
                        : 'border-border hover:border-border-strong text-fg-muted hover:text-fg hover:bg-bg-sunken/50'
                    }`}
                  >
                    <Icon size={18} />
                    <span className="text-xs font-semibold">{opt.label}</span>
                    {active && <Check size={14} className="text-accent" />}
                  </button>
                )
              })}
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-fg mb-2">{t('settings.language')}</label>
            <div className="grid grid-cols-2 gap-2">
              {langOptions.map((opt) => {
                const active = language === opt.value
                return (
                  <button
                    key={opt.value}
                    type="button"
                    aria-pressed={active}
                    onClick={() => handleSaveLanguage(opt.value)}
                    className={`flex items-center justify-center gap-3 h-12 rounded-xl border text-sm transition-all duration-200 ${
                      active
                        ? 'border-accent bg-accent/5 text-accent font-semibold'
                        : 'border-border hover:border-border-strong text-fg-muted hover:text-fg hover:bg-bg-sunken/50'
                    }`}
                  >
                    <span className="text-lg">{opt.flag}</span>
                    <span>{opt.label}</span>
                    {active && <Check size={14} className="text-accent" />}
                  </button>
                )
              })}
            </div>
          </div>
        </CardBody>
      </Card>

      <Card className="stagger-3 card-hover">
        <CardHeader>
          <div className="text-sm font-semibold text-fg">{t('profile.security')}</div>
          <div className="text-xs text-fg-subtle">{t('profile.changePassword')}</div>
        </CardHeader>
        <CardBody className="space-y-3">
          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('settings.currentPassword')}</label>
            <div className="relative">
              <Input type={showOld ? 'text' : 'password'} value={oldPw} onChange={(e) => setOldPw(e.target.value)} autoComplete="current-password" />
              <button
                type="button"
                onClick={() => setShowOld((v) => !v)}
                className="absolute right-2 top-1/2 -translate-y-1/2 text-fg-subtle hover:text-fg-muted p-1.5 rounded-lg hover:bg-bg-sunken transition-colors"
                aria-label={showOld ? t('login.hidePassword') : t('login.showPassword')}
              >
                {showOld ? <EyeOff size={16} /> : <Eye size={16} />}
              </button>
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('settings.newPassword')}</label>
            <div className="relative">
              <Input type={showNew ? 'text' : 'password'} value={newPw} onChange={(e) => setNewPw(e.target.value)} autoComplete="new-password" />
              <button
                type="button"
                onClick={() => setShowNew((v) => !v)}
                className="absolute right-2 top-1/2 -translate-y-1/2 text-fg-subtle hover:text-fg-muted p-1.5 rounded-lg hover:bg-bg-sunken transition-colors"
                aria-label={showNew ? t('login.hidePassword') : t('login.showPassword')}
              >
                {showNew ? <EyeOff size={16} /> : <Eye size={16} />}
              </button>
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-fg mb-1">{t('settings.confirmPassword')}</label>
            <div className="relative">
              <Input type={showConfirm ? 'text' : 'password'} value={confirmPw} onChange={(e) => setConfirmPw(e.target.value)} autoComplete="new-password" />
              <button
                type="button"
                onClick={() => setShowConfirm((v) => !v)}
                className="absolute right-2 top-1/2 -translate-y-1/2 text-fg-subtle hover:text-fg-muted p-1.5 rounded-lg hover:bg-bg-sunken transition-colors"
                aria-label={showConfirm ? t('login.hidePassword') : t('login.showPassword')}
              >
                {showConfirm ? <EyeOff size={16} /> : <Eye size={16} />}
              </button>
            </div>
          </div>

          <Button variant="primary" className="w-full" onClick={handleChangePassword} loading={changePwMutation.isPending}>
            <Save size={16} />
            {t('settings.changePassword')}
          </Button>
        </CardBody>
      </Card>

      <Card className="stagger-4 card-hover">
        <CardHeader>
          <div className="text-sm font-semibold text-fg">{t('profile.dataExport')}</div>
          <div className="text-xs text-fg-subtle">{t('profile.dataExportDesc')}</div>
        </CardHeader>
        <CardBody className="space-y-2">
          <Button
            variant="secondary"
            className="w-full"
            onClick={() => exportMutation.mutate()}
            loading={exportMutation.isPending}
          >
            <Download size={16} />
            {t('profile.exportData')}
          </Button>
          <p className="text-xs text-fg-subtle">{t('profile.exportHint')}</p>
        </CardBody>
      </Card>

      <Card className="stagger-5 card-hover">
        <CardHeader>
          <div className="text-sm font-semibold text-fg">{t('profile.account')}</div>
        </CardHeader>
        <CardBody>
          <Button variant="ghost" className="w-full text-danger" onClick={() => logout()}>
            <User size={16} />
            {t('common.logout')}
          </Button>
        </CardBody>
      </Card>
    </div>
  )
}