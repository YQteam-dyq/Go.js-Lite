import { useState, useMemo } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Sun, Moon, Monitor, Clock, Lock, Download, RefreshCw, Eye, EyeOff, CheckCircle2, Code2, Server, Palette, Users, ExternalLink, Heart, Shield, Copy, RotateCcw } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Confirm } from '@/components/ui/Modal'
import { useTheme } from '@/hooks/useTheme'
import { useAuth } from '@/hooks/useAuth'
import { useUiStore } from '@/stores/uiStore'
import { authApi } from '@/api/auth'
import { toast } from '@/components/ui/Toast'
import type { ThemeMode, Language } from '@shared/types'
import { useI18n } from '@/hooks/useI18n'

export default function Settings() {
  const { t } = useI18n()
  const { theme, setTheme } = useTheme()
  const language = useUiStore((s) => s.language)
  const setLanguage = useUiStore((s) => s.setLanguage)
  const { user, backendVersion, frontendVersion } = useAuth()

  const [oldPassword, setOldPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [showOldPassword, setShowOldPassword] = useState(false)
  const [showNewPassword, setShowNewPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)
  const [showReset, setShowReset] = useState(false)
  const [showRegenToken, setShowRegenToken] = useState(false)

  const { data: settings } = useQuery({
    queryKey: ['settings'],
    queryFn: () => authApi.getSettings(),
  })

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
                    ${
                      theme === opt.value
                        ? 'border-accent bg-accent/5 text-accent shadow-sm shadow-accent/10'
                        : 'border-border hover:border-border-strong text-fg-muted hover:text-fg hover:bg-bg-sunken/50'
                    }
                  `}
                >
                  <div className={`w-10 h-10 rounded-lg flex items-center justify-center transition-colors ${
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
                    ${
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
                  ? `${window.location.origin}${window.location.pathname}?token=${settings.accessToken}`
                  : t('common.loading')}
                className="font-mono text-xs"
              />
              <Button
                size="sm"
                variant="ghost"
                onClick={() => {
                  if (settings?.accessToken) {
                    const url = `${window.location.origin}${window.location.pathname}?token=${settings.accessToken}`
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
                    className={`password-strength-fill ${strengthColors[passwordStrength.label]}`}
                    style={{ width: `${passwordStrength.percent}%` }}
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
                    {passwordStrength.score > 0 ? t(`install.strength${passwordStrength.label.charAt(0).toUpperCase() + passwordStrength.label.slice(1)}` as any) : ''}
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

      <Card className="stagger-6 card-hover">
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
    </div>
  )
}
