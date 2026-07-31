import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { Lock, ShieldCheck, Eye, EyeOff, CheckCircle2, Zap, Copy, LogIn } from 'lucide-react'
import { Logo } from '@/components/branding/Logo'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card } from '@/components/ui/Card'
import { authApi } from '@/api/auth'
import { toast } from '@/components/ui/Toast'
import { useI18n } from '@/hooks/useI18n'
import { useAuthStore } from '@/stores/authStore'
import { setCsrfToken } from '@/api/client'
import { useAuthBootstrap } from '@/hooks/useAuth'
import { Spinner } from '@/components/ui/Spinner'

export default function Install() {
  const [password, setPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [loading, setLoading] = useState(false)
  const [done, setDone] = useState(false)
  const [accessToken, setAccessToken] = useState('')
  const { t } = useI18n()
  const navigate = useNavigate()
  const { installed, loading: bootstrapLoading } = useAuthBootstrap()

  useEffect(() => {
    document.title = t('install.documentTitle')
  }, [t])

  useEffect(() => {
    if (!bootstrapLoading && installed && !done) {
      navigate('/login', { replace: true })
    }
  }, [installed, bootstrapLoading, done, navigate])

  const passwordStrength = {
    length: password.length >= 8,
    upper: /[A-Z]/.test(password),
    lower: /[a-z]/.test(password),
    number: /\d/.test(password),
    match: password === confirmPassword && password.length > 0,
  }

  const strengthScore = Object.values({
    length: passwordStrength.length,
    upper: passwordStrength.upper,
    lower: passwordStrength.lower,
    number: passwordStrength.number,
  }).filter(Boolean).length

  const strengthLabel = ['', 'weak', 'fair', 'good', 'strong'][strengthScore]
  const strengthPercent = (strengthScore / 4) * 100

  const strengthColors: Record<string, string> = {
    '': 'bg-border',
    weak: 'bg-danger',
    fair: 'bg-warning',
    good: 'bg-info',
    strong: 'bg-success',
  }

  const strengthTextColors: Record<string, string> = {
    '': 'text-fg-subtle',
    weak: 'text-danger',
    fair: 'text-warning',
    good: 'text-info',
    strong: 'text-success',
  }

  const canSubmit =
    passwordStrength.length &&
    passwordStrength.upper &&
    passwordStrength.lower &&
    passwordStrength.number &&
    passwordStrength.match &&
    !loading

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!canSubmit) return

    setLoading(true)
    try {
      const data = await authApi.install({ password })
      setCsrfToken(data.csrfToken)
      useAuthStore.getState().setBootstrap(data)
      if (data.accessToken) {
        setAccessToken(data.accessToken)
      }
      setDone(true)
      toast({ type: 'success', title: t('install.success'), description: t('install.redirect') })
      setTimeout(() => navigate('/dashboard', { replace: true }), 5000)
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : t('install.fail')
      toast({ type: 'error', title: t('install.fail'), description: msg })
    } finally {
      setLoading(false)
    }
  }

  if (bootstrapLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <Spinner size="lg" />
      </div>
    )
  }

  if (done) {
    const panelUrl = accessToken
      ? `${window.location.origin}${window.location.pathname}?token=${accessToken}`
      : ''
    return (
      <div className="min-h-screen flex items-center justify-center p-4 relative overflow-hidden">
        <div className="absolute inset-0 -z-10 grid-bg opacity-40" />
        <div className="absolute top-0 right-0 w-96 h-96 bg-success/10 rounded-full blur-[100px] -translate-y-1/2 translate-x-1/3" />

        <div className="w-full max-w-md page-enter">
          <Card className="p-8 text-center shadow-xl shadow-black/5">
            <div className="relative w-20 h-20 mx-auto mb-6">
              <div className="absolute inset-0 bg-success/20 rounded-full animate-pulse" />
              <div className="relative w-full h-full rounded-full bg-success/10 text-success flex items-center justify-center">
                <CheckCircle2 size={40} className="animate-bounce-subtle" />
              </div>
            </div>
            <h2 className="text-xl font-bold text-fg mb-2">{t('install.doneTitle')}</h2>
            <p className="text-sm text-fg-muted mb-6">{t('install.doneSubtitle')}</p>

            {accessToken && (
              <div className="text-left mb-6 p-4 rounded-xl bg-bg-sunken/50 border border-border/50">
                <p className="text-xs font-medium text-fg-subtle mb-2 flex items-center gap-1.5">
                  <ShieldCheck size={14} />
                  面板访问链接（请收藏）
                </p>
                <div className="flex items-center gap-2">
                  <code className="flex-1 text-xs bg-bg px-3 py-2 rounded-lg border border-border break-all">
                    {panelUrl}
                  </code>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                      navigator.clipboard.writeText(panelUrl)
                      toast({ type: 'success', title: '已复制链接' })
                    }}
                  >
                    <Copy size={16} />
                  </Button>
                </div>
                <p className="text-xs text-fg-subtle mt-2">
                  请保存此链接，后续只能通过此链接访问面板
                </p>
              </div>
            )}

            <Button
              size="lg"
              variant="primary"
              className="w-full font-semibold mb-6"
              onClick={() => navigate('/login')}
            >
              <LogIn size={18} />
              {t('install.goToLogin')}
            </Button>

            <div className="flex items-center justify-center gap-1">
              {[0, 1, 2].map((i) => (
                <div
                  key={i}
                  className="w-2 h-2 rounded-full bg-success animate-pulse"
                  style={{ animationDelay: `${i * 0.2}s` }}
                />
              ))}
            </div>
          </Card>
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen flex items-center justify-center p-4 relative overflow-hidden">
      <div className="absolute inset-0 -z-10 grid-bg opacity-40" />
      <div className="absolute inset-0 -z-10 bg-gradient-to-b from-transparent via-transparent to-bg-sunken/50" />

      <div className="absolute top-0 right-0 w-96 h-96 bg-accent/10 rounded-full blur-[100px] -translate-y-1/2 translate-x-1/3" />
      <div className="absolute bottom-0 left-0 w-80 h-80 bg-accent/5 rounded-full blur-[80px] translate-y-1/3 -translate-x-1/3" />

      <div className="w-full max-w-md page-enter">
        <div className="flex flex-col items-center mb-8">
          <div className="mb-5 relative">
            <div className="absolute inset-0 bg-accent/20 rounded-3xl blur-xl" />
            <div className="relative">
              <Logo size="lg" showText={false} />
            </div>
          </div>
          <div className="mb-2">
            <Logo size="md" showText={true} />
          </div>
          <p className="text-sm text-fg-muted">{t('install.subtitle')}</p>
        </div>

        <Card className="p-6 md:p-8 shadow-xl shadow-black/5">
          <div className="flex items-center gap-3 mb-6">
            <div className="w-10 h-10 rounded-xl bg-accent/10 text-accent flex items-center justify-center">
              <Zap size={20} />
            </div>
            <div>
              <h1 className="text-lg font-semibold text-fg">{t('install.title')}</h1>
              <p className="text-xs text-fg-subtle">{t('install.subtitle')}</p>
            </div>
          </div>

          <form onSubmit={handleSubmit} className="space-y-5">
            <div className="space-y-2">
              <label className="block text-sm font-medium text-fg">
                {t('install.passwordLabel')}
              </label>
              <div className="relative">
                <Input
                  type={showPassword ? 'text' : 'password'}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder={t('install.passwordHint')}
                  icon={<Lock size={18} />}
                  autoFocus
                  inputMode="text"
                  autoComplete="new-password"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((v) => !v)}
                  className="absolute right-2 top-1/2 -translate-y-1/2 text-fg-subtle hover:text-fg-muted p-1.5 rounded-lg hover:bg-bg-sunken transition-colors"
                  aria-label={showPassword ? t('login.hidePassword') : t('login.showPassword')}
                >
                  {showPassword ? <EyeOff size={16} /> : <Eye size={16} />}
                </button>
              </div>

              {password.length > 0 && (
                <div className="space-y-2 pt-1 animate-fade-in">
                  <div className="password-strength-bar">
                    <div
                      className={`password-strength-fill ${strengthColors[strengthLabel]}`}
                      style={{ width: `${strengthPercent}%` }}
                    />
                  </div>
                  <div className="flex items-center justify-between text-xs">
                    <span className={`font-medium ${strengthTextColors[strengthLabel]}`}>
                      {t(`install.strength${strengthLabel.charAt(0).toUpperCase() + strengthLabel.slice(1)}` as any) || t('install.weak')}
                    </span>
                    <span className="text-fg-subtle">{strengthScore}/4</span>
                  </div>
                </div>
              )}
            </div>

            <div className="space-y-2">
              <label className="block text-sm font-medium text-fg">
                {t('install.confirmLabel')}
              </label>
              <Input
                type={showPassword ? 'text' : 'password'}
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                placeholder={t('install.confirmPlaceholder')}
                icon={<ShieldCheck size={18} />}
                inputMode="text"
                autoComplete="new-password"
                invalid={confirmPassword.length > 0 && !passwordStrength.match}
              />
            </div>

            <div className="space-y-2 py-2">
              <CheckPassword ok={passwordStrength.length} label={t('install.lengthRequirement')} />
              <CheckPassword ok={passwordStrength.upper && passwordStrength.lower} label={t('install.mixRequirement')} />
              <CheckPassword ok={passwordStrength.number} label={t('install.numberRequirement') as any} />
              <CheckPassword ok={passwordStrength.match} label={t('install.matchRequirement')} />
            </div>

            <Button
              type="submit"
              size="lg"
              className="w-full font-semibold"
              loading={loading}
              disabled={!canSubmit}
            >
              {t('install.submit')}
            </Button>
          </form>
        </Card>

        <p className="text-center text-xs text-fg-subtle mt-6">
          {t('install.securityNote')}
        </p>
      </div>
    </div>
  )
}

function CheckPassword({ ok, label }: { ok: boolean; label: string }) {
  return (
    <div className={`flex items-center gap-2 text-xs transition-all duration-200 ${ok ? 'text-success' : 'text-fg-subtle'}`}>
      <div className={`w-4 h-4 rounded-full flex items-center justify-center transition-all duration-200 ${ok ? 'bg-success/10' : 'bg-bg-sunken'}`}>
        <CheckCircle2 size={12} className={ok ? '' : 'opacity-40'} />
      </div>
      <span className={ok ? 'font-medium' : ''}>{label}</span>
    </div>
  )
}
