import { useState, useMemo, useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Sun, Moon, Monitor, Clock, Lock, Download, RefreshCw, Eye, EyeOff, CheckCircle2, Code2, Server, Palette, Users, ExternalLink, Heart, Shield, Copy, RotateCcw, History, KeyRound, QrCode, X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Confirm, Modal } from '@/components/ui/Modal'
import { useTheme } from '@/hooks/useTheme'
import { useAuth } from '@/hooks/useAuth'
import { useUiStore } from '@/stores/uiStore'
import { authApi } from '@/api/auth'
import { toast } from '@/components/ui/Toast'
import type { ThemeMode, Language, TotpEnrollResponse } from '@shared/types'
import { useI18n } from '@/hooks/useI18n'
import { NotificationChannelsCard } from '@/components/notifications/NotificationChannelsCard'

type TotpState = 'disabled' | 'enrolling' | 'enabled'

export default function Settings() {
  const { t } = useI18n()
  const { theme, setTheme } = useTheme()
  const language = useUiStore((s) => s.language)
  const setLanguage = useUiStore((s) => s.setLanguage)
  const { user, backendVersion, frontendVersion } = useAuth()
  const queryClient = useQueryClient()

  const [oldPassword, setOldPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [showOldPassword, setShowOldPassword] = useState(false)
  const [showNewPassword, setShowNewPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)
  const [showReset, setShowReset] = useState(false)
  const [showRegenToken, setShowRegenToken] = useState(false)
  const [logRetention, setLogRetention] = useState<number | ''>(500)
  const [logRetentionTouched, setLogRetentionTouched] = useState(false)

  const [totpState, setTotpState] = useState<TotpState>('disabled')
  const [enrollData, setEnrollData] = useState<TotpEnrollResponse | null>(null)
  const [confirmCode, setConfirmCode] = useState('')
  const [showRecoveryModal, setShowRecoveryModal] = useState(false)
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([])
  const [recoveryModalTitle, setRecoveryModalTitle] = useState('')
  const [recoveryWarning, setRecoveryWarning] = useState(false)
  const [recoveryFilename, setRecoveryFilename] = useState('gojs-recovery-codes.txt')

  const [showPasswordPrompt, setShowPasswordPrompt] = useState(false)
  const [passwordPromptType, setPasswordPromptType] = useState<'disable' | 'view' | 'regenerate'>('disable')
  const [promptPassword, setPromptPassword] = useState('')

  const { data: settings } = useQuery({
    queryKey: ['settings'],
    queryFn: () => authApi.getSettings(),
  })

  const { data: totpStatus } = useQuery({
    queryKey: ['totpStatus'],
    queryFn: () => authApi.totpStatus(),
  })

  useEffect(() => {
    if (totpStatus) {
      setTotpState(totpStatus.enabled ? 'enabled' : 'disabled')
    }
  }, [totpStatus])

  useEffect(() => {
    if (!logRetentionTouched && typeof settings?.logRetention === 'number') {
      setLogRetention(settings.logRetention)
    }
  }, [settings?.logRetention, logRetentionTouched])

  const logRetentionMutation = useMutation({
    mutationFn: (value: number) =>
      authApi.updateSettings({ logRetention: value }),
    onSuccess: () => {
      toast({ type: 'success', title: t('settings.logRetentionSaved') })
      queryClient.invalidateQueries({ queryKey: ['settings'] })
      setLogRetentionTouched(false)
    },
    onError: (err: Error) => {
      toast({
        type: 'error',
        title: t('common.saveFailed'),
        description: err.message,
      })
    },
  })

  const handleSaveLogRetention = () => {
    const value = typeof logRetention === 'number' ? logRetention : 500
    if (value < 50) {
      toast({ type: 'error', title: t('settings.logRetentionInvalid') })
      return
    }
    logRetentionMutation.mutate(value)
  }

  const regenerateMutation = useMutation({
    mutationFn: () => authApi.regenerateAccessToken(),
    onSuccess: () => {
      toast({ type: 'success', title: t('settings.tokenRegenerated') })
      setShowRegenToken(false)
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('settings.regenerateFailed'), description: err.message })
    },
  })

  const changePwMutation = useMutation({
    mutationFn: () => authApi.changePassword(oldPassword, newPassword),
    onSuccess: () => {
      toast({ type: 'success', title: t('settings.passwordChanged') })
      setOldPassword('')
      setNewPassword('')
      setConfirmPassword('')
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('settings.changeFailed'), description: err.message })
    },
  })

  const totpEnrollMutation = useMutation({
    mutationFn: () => authApi.totpEnroll(),
    onSuccess: (data) => {
      setEnrollData(data)
      setRecoveryCodes(data.recovery_codes)
      setTotpState('enrolling')
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.saveFailed'), description: err.message })
    },
  })

  const totpConfirmMutation = useMutation({
    mutationFn: (code: string) => authApi.totpConfirm(code),
    onSuccess: () => {
      toast({ type: 'success', title: t('totp.enabled') })
      setTotpState('enabled')
      setConfirmCode('')
      setEnrollData(null)
      setRecoveryModalTitle(t('totp.recoveryCodesShownOnce'))
      setRecoveryWarning(true)
      setShowRecoveryModal(true)
      queryClient.invalidateQueries({ queryKey: ['totpStatus'] })
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('totp.codeInvalid'), description: err.message })
    },
  })

  const totpDisableMutation = useMutation({
    mutationFn: (adminPassword: string) => authApi.totpDisable(adminPassword),
    onSuccess: () => {
      toast({ type: 'success', title: t('totp.disabled') })
      setTotpState('disabled')
      setEnrollData(null)
      setConfirmCode('')
      setShowPasswordPrompt(false)
      setPromptPassword('')
      queryClient.invalidateQueries({ queryKey: ['totpStatus'] })
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('settings.changeFailed'), description: err.message })
    },
  })

  const totpRecoveryViewMutation = useMutation({
    mutationFn: (adminPassword: string) =>
      authApi.totpRecoveryCodes(adminPassword, passwordPromptType === 'regenerate' ? 'regenerate' : 'view'),
    onSuccess: (data) => {
      if (passwordPromptType === 'regenerate' && data.recovery_codes) {
        setRecoveryCodes(data.recovery_codes)
        setRecoveryFilename(`gojs-recovery-codes-$$new Date().toISOString().slice(0, 10).replace(/-/g, '')}.txt`)
        setRecoveryModalTitle(t('totp.recoveryRegenerated'))
        setRecoveryWarning(true)
        setShowRecoveryModal(true)
        toast({ type: 'success', title: t('totp.recoveryRegenerated'), description: t('totp.recoveryCodesShownOnce') })
      } else if (passwordPromptType === 'view') {
        if (data.legacy || data.view_only) {
          toast({ type: 'warning', title: t('totp.recoveryLegacyNotice') })
        } else if (data.recovery_codes) {
          setRecoveryCodes(data.recovery_codes)
          setRecoveryFilename(data.filename || 'gojs-recovery-codes.txt')
          setRecoveryModalTitle(t('totp.viewRecoveryCodes'))
          setRecoveryWarning(false)
          setShowRecoveryModal(true)
        }
      }
      queryClient.invalidateQueries({ queryKey: ['totpStatus'] })
      setShowPasswordPrompt(false)
      setPromptPassword('')
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.saveFailed'), description: err.message })
    },
  })

  const handleDownloadRecoveryCodes = () => {
    if (recoveryCodes.length === 0) return
    const content = recoveryCodes.join('\n') + '\n'
    const blob = new Blob([content], { type: 'text/plain;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = recoveryFilename
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
    toast({ type: 'success', title: t('totp.recoveryDownloaded') })
  }

  const passwordStrength = useMemo(() => {
    if (!newPassword) return { score: 0, label: '', percent: 0 }

    let score = 0
    const checks = {
      length: newPassword.length >= 8,
      upper: /[A-Z]/.test(newPassword),
      lower: /[a-z]/.test(newPassword),
      number: /\d/.test(newPassword),
    }

    score = Object.values(checks).filter(Boolean).length

    const labels = ['', 'weak', 'fair', 'good', 'strong']
    const label = labels[score]

    return {
      score,
      label,
      percent: (score / 4) * 100,
      checks,
    }
  }, [newPassword])

  const strengthColors: Record<string, string> = {
    '': 'bg-border',
    weak: 'bg-danger',
    fair: 'bg-warning',
    good: 'bg-info',
    strong: 'bg-success',
  }

  const handleChangePassword = () => {
    if (!oldPassword || !newPassword || !confirmPassword) {
      toast({ type: 'error', title: t('settings.fillAllFields') })
      return
    }
    if (newPassword !== confirmPassword) {
      toast({ type: 'error', title: t('settings.passwordsNotMatch') })
      return
    }
    if (newPassword.length < 8) {
      toast({ type: 'error', title: t('settings.newPasswordMinLength') })
      return
    }
    changePwMutation.mutate()
  }

  const handleExport = () => {
    toast({ type: 'info', title: t('settings.exportInProgress') })
  }

  const handleReset = () => {
    toast({ type: 'info', title: t('settings.resetInProgress') })
    setShowReset(false)
  }

  const openDisablePrompt = () => {
    setPasswordPromptType('disable')
    setPromptPassword('')
    setShowPasswordPrompt(true)
  }

  const openViewRecoveryPrompt = () => {
    setPasswordPromptType('view')
    setPromptPassword('')
    setShowPasswordPrompt(true)
  }

  const openRegeneratePrompt = () => {
    setPasswordPromptType('regenerate')
    setPromptPassword('')
    setShowPasswordPrompt(true)
  }

  const handlePasswordPromptConfirm = () => {
    if (!promptPassword) {
      toast({ type: 'error', title: t('totp.passwordRequired') })
      return
    }
    if (passwordPromptType === 'disable') {
      totpDisableMutation.mutate(promptPassword)
    } else {
      totpRecoveryViewMutation.mutate(promptPassword)
    }
  }

  const handleActivate = () => {
    const code = confirmCode.replace(/\D/g, '')
    if (code.length !== 6) {
      toast({ type: 'error', title: t('totp.codeRequired') })
      return
    }
    totpConfirmMutation.mutate(code)
  }

  const themeOptions: { value: ThemeMode; label: string; icon: React.ReactNode }[] = [
    { value: 'light', label: t('settings.light'), icon: <Sun size={18} /> },
    { value: 'dark', label: t('settings.dark'), icon: <Moon size={18} /> },
    { value: 'system', label: t('settings.system'), icon: <Monitor size={18} /> },
  ]

  const langOptions: { value: Language; label: string; flag: string }[] = [
    { value: 'zh', label: t('settings.zh'), flag: '🇨🇳' },
    { value: 'en', label: t('settings.en'), flag: '🇺🇸' },
  ]

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-2xl mx-auto page-enter">
      <div className="stagger-1">
        <h1 className="text-xl font-semibold text-fg">{t('settings.title')}</h1>
        <p className="text-sm text-fg-muted mt-0.5">{t('settings.subtitle')}</p>
      </div>

      <Card className="stagger-2 card-hover">
        <CardHeader className="flex items-center gap-3">
          <div className="w-11 h-11 rounded-xl bg-accent/10 text-accent flex items-center justify-center">
            <Palette size={20} />
          </div>
          <div>
            <div className="text-sm font-semibold text-fg">{t('settings.appearance')}</div>
            <div className="text-xs text-fg-subtle">{t('settings.themeAndLanguage')}</div>
          </div>
        </CardHeader>
        <CardBody className="space-y-6">
          <div>
            <label className="block text-sm font-medium text-fg mb-3">{t('settings.theme')}</label>
            <div className="grid grid-cols-3 gap-3">
              {themeOptions.map((opt) => (
                <button
                  key={opt.value}
                  onClick={() => setTheme(opt.value)}
                  className={`
                    flex flex-col items-center gap-2.5 py-4 px-3 rounded-xl border transition-all duration-200 min-h-[90px]
                    active:scale-95
                    $$
                      theme === opt.value
                        ? 'border-accent bg-accent/5 text-accent shadow-sm shadow-accent/10'
                        : 'border-border hover:border-border-strong text-fg-muted hover:text-fg hover:bg-bg-sunken/50'
                    }
                  `}
                >
                  <div className={`w-10 h-10 rounded-lg flex items-center justify-center transition-colors $$
                    theme === opt.value ? 'bg-accent/10' : 'bg-bg-sunken'
                  }`}>
                    {opt.icon}
                  </div>
                  <span className="text-xs font-semibold">{opt.label}</span>
                  {theme === opt.value && (
                    <div className="w-4 h-4 rounded-full bg-accent text-accent-fg flex items-center justify-center">
                      <CheckCircle2 size={12} />
                    </div>
                  )}
                </button>
              ))}
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-fg mb-3">{t('settings.language')}</label>
            <div className="grid grid-cols-2 gap-3">
              {langOptions.map((opt) => (
                <button
                  key={opt.value}
                  onClick={() => setLanguage(opt.value)}
                  className={`
                    flex items-center justify-center gap-3 h-12 rounded-xl border text-sm transition-all duration-200
                    active:scale-95
                    $$
                      language === opt.value
                        ? 'border-accent bg-accent/5 text-accent font-semibold shadow-sm shadow-accent/10'
                        : 'border-border hover:border-border-strong text-fg-muted hover:text-fg hover:bg-bg-sunken/50'
                    }
                  `}
                >
                  <span className="text-lg">{opt.flag}</span>
                  <span>{opt.label}</span>
                </button>
              ))}
            </div>
          </div>
        </CardBody>
      </Card>

      <Card className="stagger-3 card-hover">
        <CardHeader className="flex items-center gap-3">
          <div className="w-11 h-11 rounded-xl bg-warning/10 text-warning flex items-center justify-center">
            <Clock size={20} />
          </div>
          <div>
            <div className="text-sm font-semibold text-fg">{t('settings.session')}</div>
            <div className="text-xs text-fg-subtle">{t('settings.sessionSettings')}</div>
          </div>
        </CardHeader>
        <CardBody className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-fg mb-2">
              {t('settings.sessionTimeout')}
            </label>
            <Input
              type="number"
              defaultValue={30}
              inputMode="numeric"
              min={5}
              max={1440}
              autoComplete="off"
            />
            <p className="text-xs text-fg-subtle mt-2">{t('settings.sessionTimeoutDesc')}</p>
          </div>
        </CardBody>
      </Card>

      <Card className="stagger-3 card-hover">
        <CardHeader className="flex items-center gap-3">
          <div className="w-11 h-11 rounded-xl bg-accent/10 text-accent flex items-center justify-center">
            <History size={20} />
          </div>
          <div>
            <div className="text-sm font-semibold text-fg">{t('settings.logRetention')}</div>
            <div className="text-xs text-fg-subtle">{t('settings.logRetentionDesc')}</div>
          </div>
        </CardHeader>
        <CardBody className="space-y-3">
          <div className="flex items-end gap-2">
            <div className="flex-1">
              <Input
                type="number"
                value={logRetention}
                onChange={(e) => {
                  const v = e.target.value
                  setLogRetentionTouched(true)
                  setLogRetention(v === '' ? '' : Number(v))
                }}
                inputMode="numeric"
                min={50}
                autoComplete="off"
                invalid={
                  logRetentionTouched &&
                  (logRetention === '' || logRetention < 50)
                }
              />
            </div>
            <Button
              variant="primary"
              onClick={handleSaveLogRetention}
              loading={logRetentionMutation.isPending}
              disabled={
                !logRetentionTouched ||
                logRetention === '' ||
                logRetention < 50 ||
                logRetention === settings?.logRetention
              }
            >
              {t('common.save')}
            </Button>
          </div>
          <p className="text-xs text-fg-subtle">{t('settings.logRetentionDesc')}</p>
        </CardBody>
      </Card>

      <Card className="stagger-3 card-hover">
        <CardHeader className="flex items-center gap-3">
          <div className="w-11 h-11 rounded-xl bg-info/10 text-info flex items-center justify-center">
            <Shield size={20} />
          </div>
          <div>
            <div className="text-sm font-semibold text-fg">{t('settings.privateAccess')}</div>
            <div className="text-xs text-fg-subtle">{t('settings.privateAccessDesc')}</div>
          </div>
        </CardHeader>
        <CardBody className="space-y-4">
          <div className="space-y-2">
            <label className="block text-sm font-medium text-fg">{t('settings.panelAccessLink')}</label>
            <div className="flex items-center gap-2">
              <Input
                readOnly
                value={settings?.accessToken
                  ? `$$window.location.origin}$$window.location.pathname}?token=$$settings.accessToken}`
                  : t('common.loading')}
                className="font-mono text-xs"
              />
              <Button
                size="sm"
                variant="ghost"
                onClick={() => {
                  if (settings?.accessToken) {
                    const url = `$$window.location.origin}$$window.location.pathname}?token=$$settings.accessToken}`
                    navigator.clipboard.writeText(url)
                    toast({ type: 'success', title: t('settings.linkCopied') })
                  }
                }}
              >
                <Copy size={16} />
              </Button>
            </div>
            <p className="text-xs text-fg-subtle">
              {t('settings.privateAccessHint')}
            </p>
          </div>

          <Button
            variant="secondary"
            className="w-full justify-center"
            onClick={() => setShowRegenToken(true)}
          >
            <RotateCcw size={16} />
            {t('settings.regenerateToken')}
          </Button>
        </CardBody>
      </Card>

      <Card className="stagger-4 card-hover">
        <CardHeader className="flex items-center gap-3">
          <div className="w-11 h-11 rounded-xl bg-danger/10 text-danger flex items-center justify-center">
            <Lock size={20} />
          </div>
          <div>
            <div className="text-sm font-semibold text-fg">{t('settings.security')}</div>
            <div className="text-xs text-fg-subtle">{t('settings.passwordAndAuth')}</div>
          </div>
        </CardHeader>
        <CardBody className="space-y-4">
          <div className="space-y-2">
            <label className="block text-sm font-medium text-fg">{t('settings.currentPassword')}</label>
            <div className="relative">
              <Input
                type={showOldPassword ? 'text' : 'password'}
                value={oldPassword}
                onChange={(e) => setOldPassword(e.target.value)}
                placeholder={t('settings.enterCurrentPassword')}
                autoComplete="current-password"
              />
              <button
                type="button"
                onClick={() => setShowOldPassword((v) => !v)}
                className="absolute right-2 top-1/2 -translate-y-1/2 text-fg-subtle hover:text-fg-muted p-1.5 rounded-lg hover:bg-bg-sunken transition-colors"
                aria-label={showOldPassword ? t('login.hidePassword') : t('login.showPassword')}
              >
                {showOldPassword ? <EyeOff size={16} /> : <Eye size={16} />}
              </button>
            </div>
          </div>

          <div className="space-y-2">
            <label className="block text-sm font-medium text-fg">{t('settings.newPassword')}</label>
            <div className="relative">
              <Input
                type={showNewPassword ? 'text' : 'password'}
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                placeholder={t('settings.atLeast8Chars')}
                autoComplete="new-password"
              />
              <button
                type="button"
                onClick={() => setShowNewPassword((v) => !v)}
                className="absolute right-2 top-1/2 -translate-y-1/2 text-fg-subtle hover:text-fg-muted p-1.5 rounded-lg hover:bg-bg-sunken transition-colors"
                aria-label={showNewPassword ? t('login.hidePassword') : t('login.showPassword')}
              >
                {showNewPassword ? <EyeOff size={16} /> : <Eye size={16} />}
              </button>
            </div>

            {newPassword.length > 0 && (
              <div className="space-y-1.5 pt-1 animate-fade-in">
                <div className="password-strength-bar">
                  <div
                    className={`password-strength-fill $$strengthColors[passwordStrength.label]}`}
                    style={{ width: `$$passwordStrength.percent}%` }}
                  />
                </div>
                <div className="flex items-center justify-between text-xs">
                  <span className={
                    passwordStrength.label === 'weak' ? 'text-danger' :
                    passwordStrength.label === 'fair' ? 'text-warning' :
                    passwordStrength.label === 'good' ? 'text-info' :
                    passwordStrength.label === 'strong' ? 'text-success' :
                    'text-fg-subtle'
                  }>
                    {passwordStrength.score > 0 ? t(`install.strength$$passwordStrength.label.charAt(0).toUpperCase() + passwordStrength.label.slice(1)}` as any) : ''}
                  </span>
                  <span className="text-fg-subtle">{passwordStrength.score}/4</span>
                </div>
              </div>
            )}
          </div>

          <div className="space-y-2">
            <label className="block text-sm font-medium text-fg">{t('settings.confirmPassword')}</label>
            <div className="relative">
              <Input
                type={showConfirmPassword ? 'text' : 'password'}
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                placeholder={t('settings.enterNewPasswordAgain')}
                autoComplete="new-password"
                invalid={confirmPassword.length > 0 && newPassword !== confirmPassword}
              />
              <button
                type="button"
                onClick={() => setShowConfirmPassword((v) => !v)}
                className="absolute right-2 top-1/2 -translate-y-1/2 text-fg-subtle hover:text-fg-muted p-1.5 rounded-lg hover:bg-bg-sunken transition-colors"
                aria-label={showConfirmPassword ? t('login.hidePassword') : t('login.showPassword')}
              >
                {showConfirmPassword ? <EyeOff size={16} /> : <Eye size={16} />}
              </button>
            </div>
          </div>

          <Button
            variant="primary"
            className="w-full font-semibold"
            onClick={handleChangePassword}
            loading={changePwMutation.isPending}
          >
            {t('settings.changePassword')}
          </Button>
        </CardBody>
      </Card>

      <Card className="stagger-5 card-hover">
        <CardHeader className="flex items-center gap-3">
          <div className="w-11 h-11 rounded-xl bg-success/10 text-success flex items-center justify-center">
            <KeyRound size={20} />
          </div>
          <div className="flex-1">
            <div className="text-sm font-semibold text-fg">{t('totp.cardTitle')}</div>
            <div className="text-xs text-fg-subtle flex items-center gap-2">
              {totpState === 'enabled' ? (
                <Badge variant="success">{t('common.enabled')}</Badge>
              ) : totpState === 'enrolling' ? (
                <Badge variant="warning">Pending activation</Badge>
              ) : (
                <Badge variant="muted">{t('common.disabled')}</Badge>
              )}
              {totpStatus?.recoveryCodesCount !== undefined && totpState === 'enabled' && (
                <span className="text-fg-muted">
                  · {totpStatus.recoveryCodesCount} {t('totp.viewRecoveryCodes').toLowerCase()}
                </span>
              )}
            </div>
          </div>
        </CardHeader>
        <CardBody className="space-y-4">
          {totpState === 'disabled' && (
            <Button
              variant="primary"
              className="w-full font-semibold"
              onClick={() => totpEnrollMutation.mutate()}
              loading={totpEnrollMutation.isPending}
            >
              <Shield size={16} />
              {t('totp.enable')}
            </Button>
          )}

          {totpState === 'enrolling' && enrollData && (
            <div className="space-y-5">
              <div className="grid md:grid-cols-1 gap-4">
                <div className="space-y-2 text-center">
                  <img
                    src={enrollData.qr_svg_data_url}
                    alt="TOTP QR Code"
                    className="mx-auto rounded-xl border border-border bg-white max-w-[300px] w-full"
                  />
                  <p className="text-xs text-fg-muted">{t('totp.qrHint')}</p>
                </div>
              </div>

              <div className="space-y-2">
                <label className="block text-sm font-medium text-fg">Secret Key</label>
                <div className="flex items-center gap-2">
                  <Input
                    readOnly
                    value={enrollData.secret}
                    className="font-mono text-sm tracking-wider text-center"
                  />
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                      navigator.clipboard.writeText(enrollData.secret)
                      toast({ type: 'success', title: t('totp.secretCopied') })
                    }}
                  >
                    <Copy size={16} />
                  </Button>
                </div>
                <div className="flex items-center gap-2">
                  <Input
                    readOnly
                    value={enrollData.otpauth_url}
                    className="font-mono text-xs"
                  />
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                      navigator.clipboard.writeText(enrollData.otpauth_url)
                      toast({ type: 'success', title: t('totp.otpauthCopied') })
                    }}
                  >
                    <Copy size={16} />
                  </Button>
                </div>
              </div>

              <div className="space-y-2">
                <label className="block text-sm font-medium text-fg">{t('totp.enterCode')}</label>
                <div className="flex items-end gap-2">
                  <div className="flex-1">
                    <Input
                      type="text"
                      value={confirmCode}
                      onChange={(e) => setConfirmCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                      placeholder={t('login.totpPlaceholder')}
                      inputMode="numeric"
                      autoComplete="one-time-code"
                      maxLength={6}
                      className="text-center font-mono tracking-widest text-lg"
                    />
                  </div>
                  <Button
                    variant="primary"
                    onClick={handleActivate}
                    loading={totpConfirmMutation.isPending}
                    disabled={confirmCode.replace(/\D/g, '').length !== 6}
                  >
                    <QrCode size={16} />
                    {t('totp.activate2fa')}
                  </Button>
                </div>
              </div>

              <div className="rounded-lg border border-border/50 bg-bg-sunken/30 p-3 space-y-2">
                <p className="text-xs font-semibold text-fg flex items-center gap-1.5">
                  <Lock size={14} className="text-warning" />
                  {t('totp.recoveryCodesShownOnce')}
                </p>
                <div className="grid grid-cols-2 gap-2">
                  {recoveryCodes.map((code, i) => (
                    <div
                      key={i}
                      className="font-mono text-xs text-center py-1.5 px-2 rounded bg-bg-elevated border border-border/50 text-fg"
                    >
                      {code}
                    </div>
                  ))}
                </div>
              </div>

              <Button
                variant="ghost"
                className="w-full"
                onClick={() => {
                  setTotpState('disabled')
                  setEnrollData(null)
                  setConfirmCode('')
                }}
              >
                <X size={16} />
                {t('common.cancel')}
              </Button>
            </div>
          )}

          {totpState === 'enabled' && (
            <div className="space-y-3">
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <Button
                  variant="secondary"
                  onClick={openViewRecoveryPrompt}
                  className="justify-center"
                >
                  <Eye size={16} />
                  {t('totp.viewRecoveryCodes')}
                </Button>
                <Button
                  variant="secondary"
                  onClick={openRegeneratePrompt}
                  className="justify-center"
                >
                  <KeyRound size={16} />
                  {t('totp.regenerateRecoveryCodes')}
                </Button>
                <Button
                  variant="ghost"
                  className="text-danger hover:text-danger hover:bg-danger/5 justify-center"
                  onClick={openDisablePrompt}
                >
                  <Lock size={16} />
                  {t('totp.disable')}
                </Button>
              </div>
            </div>
          )}
        </CardBody>
      </Card>

      <Card className="stagger-6 card-hover">
        <CardHeader className="flex items-center gap-3">
          <div className="w-11 h-11 rounded-xl bg-success/10 text-success flex items-center justify-center">
            <Download size={20} />
          </div>
          <div>
            <div className="text-sm font-semibold text-fg">{t('settings.config')}</div>
            <div className="text-xs text-fg-subtle">{t('settings.exportAndReset')}</div>
          </div>
        </CardHeader>
        <CardBody className="space-y-3">
          <Button variant="secondary" className="w-full justify-center" onClick={handleExport}>
            <Download size={16} />
            {t('settings.exportConfig')}
          </Button>
          <Button
            variant="ghost"
            className="w-full justify-center text-danger hover:text-danger hover:bg-danger/5"
            onClick={() => setShowReset(true)}
          >
            <RefreshCw size={16} />
            {t('settings.resetAll')}
          </Button>
        </CardBody>
      </Card>

      <NotificationChannelsCard />

      <Card className="stagger-7 card-hover">
        <CardHeader className="flex items-center gap-3">
          <div className="w-11 h-11 rounded-xl bg-fg/5 text-fg-muted flex items-center justify-center">
            <Code2 size={20} />
          </div>
          <div>
            <div className="text-sm font-semibold text-fg">{t('settings.about')}</div>
            <div className="text-xs text-fg-subtle">{t('settings.versionInfo')}</div>
          </div>
        </CardHeader>
        <CardBody className="space-y-3 text-sm">
          <div className="flex items-center justify-between py-2 border-b border-border/50">
            <span className="text-fg-muted flex items-center gap-2">
              <Server size={14} />
              {t('common.user')}
            </span>
            <span className="text-fg font-medium">{user?.username || t('common.admin')}</span>
          </div>
          <div className="flex items-center justify-between py-2 border-b border-border/50">
            <span className="text-fg-muted flex items-center gap-2">
              <Code2 size={14} />
              {t('settings.frontendVersion')}
            </span>
            <Badge variant="muted" className="font-mono">{frontendVersion}</Badge>
          </div>
          <div className="flex items-center justify-between py-2">
            <span className="text-fg-muted flex items-center gap-2">
              <Server size={14} />
              {t('settings.backendVersion')}
            </span>
            <Badge variant="muted" className="font-mono">{backendVersion || '—'}</Badge>
          </div>
        </CardBody>
      </Card>

      <Card className="stagger-7 card-hover">
        <CardHeader className="flex items-center gap-3">
          <div className="w-11 h-11 rounded-xl bg-gradient-to-br from-accent/20 to-success/20 text-accent flex items-center justify-center">
            <Users size={20} />
          </div>
          <div>
            <div className="text-sm font-semibold text-fg">{t('settings.developer')}</div>
            <div className="text-xs text-fg-subtle">{t('settings.developerSubtitle')}</div>
          </div>
        </CardHeader>
        <CardBody className="space-y-3 text-sm">
          <div className="flex items-center justify-between py-2 border-b border-border/50">
            <span className="text-fg-muted flex items-center gap-2">
              <Heart size={14} className="text-danger" />
              {t('settings.developerTeam')}
            </span>
            <span className="text-fg font-semibold">YQteam</span>
          </div>
          <a
            href="https://yq-tuandui.xyz"
            target="_blank"
            rel="noopener noreferrer"
            className="flex items-center justify-between py-2 border-b border-border/50 group"
          >
            <span className="text-fg-muted flex items-center gap-2 group-hover:text-fg transition-colors">
              <ExternalLink size={14} />
              {t('settings.teamWebsite')}
            </span>
            <span className="text-accent font-medium text-xs">yq-tuandui.xyz</span>
          </a>
          <a
            href="https://gojs.yq-tuandui.xyz"
            target="_blank"
            rel="noopener noreferrer"
            className="flex items-center justify-between py-2 group"
          >
            <span className="text-fg-muted flex items-center gap-2 group-hover:text-fg transition-colors">
              <ExternalLink size={14} />
              {t('settings.projectWebsite')}
            </span>
            <span className="text-accent font-medium text-xs">gojs.yq-tuandui.xyz</span>
          </a>
          <div className="pt-2 text-center text-xs text-fg-subtle">
            {t('settings.madeWithLove')}
          </div>
        </CardBody>
      </Card>

      <Confirm
        open={showReset}
        title={t('settings.resetSettings')}
        message={t('settings.resetConfirmMessage')}
        confirmText={t('settings.reset')}
        variant="danger"
        onConfirm={handleReset}
        onCancel={() => setShowReset(false)}
      />

      <Confirm
        open={showRegenToken}
        title={t('settings.regenerateTokenTitle')}
        message={t('settings.regenerateTokenConfirm')}
        confirmText={t('settings.regenerateTokenBtn')}
        variant="primary"
        onConfirm={() => regenerateMutation.mutate()}
        onCancel={() => setShowRegenToken(false)}
      />

      <Modal
        open={showPasswordPrompt}
        onClose={() => setShowPasswordPrompt(false)}
        title={t('totp.reenterAdminPassword')}
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowPasswordPrompt(false)}>
              {t('common.cancel')}
            </Button>
            <Button
              variant={passwordPromptType === 'disable' ? 'danger' : 'primary'}
              onClick={handlePasswordPromptConfirm}
              loading={
                passwordPromptType === 'disable'
                  ? totpDisableMutation.isPending
                  : totpRecoveryViewMutation.isPending
              }
            >
              {t('common.confirm')}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <p className="text-sm text-fg-muted">
            {passwordPromptType === 'disable'
              ? t('totp.disableConfirm')
              : passwordPromptType === 'regenerate'
              ? t('totp.recoveryRegenerated')
              : t('totp.viewRecoveryCodes')}
          </p>
          <Input
            type="password"
            value={promptPassword}
            onChange={(e) => setPromptPassword(e.target.value)}
            placeholder={t('settings.enterCurrentPassword')}
            autoComplete="current-password"
          />
        </div>
      </Modal>

      <Modal
        open={showRecoveryModal}
        onClose={() => setShowRecoveryModal(false)}
        title={recoveryModalTitle || t('totp.viewRecoveryCodes')}
        size="md"
        closeOnBackdrop={false}
        footer={
          <>
            {recoveryCodes.length > 0 && (
              <Button variant="secondary" onClick={handleDownloadRecoveryCodes}>
                <Download size={16} className="mr-1.5" />
                {t('totp.recoveryDownload')}
              </Button>
            )}
            <Button variant="primary" onClick={() => setShowRecoveryModal(false)}>
              {t('common.close')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          {recoveryWarning && (
            <div className="rounded-lg bg-warning/10 border border-warning/30 p-3">
              <p className="text-sm text-warning flex items-start gap-2">
                <Shield size={16} className="shrink-0 mt-0.5" />
                <span>{t('totp.recoveryCodesShownOnce')}</span>
              </p>
            </div>
          )}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
            {recoveryCodes.map((code, i) => (
              <div
                key={i}
                className="font-mono text-sm text-center py-2 px-3 rounded-lg bg-bg-sunken border border-border/60 text-fg select-all"
              >
                {code}
              </div>
            ))}
          </div>
        </div>
      </Modal>
    </div>
  )
}
