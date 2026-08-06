import { useState, useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { Lock, Sun, Moon, Monitor, Eye, EyeOff, ShieldCheck, ShieldAlert } from 'lucide-react'
import { Logo } from '@/components/branding/Logo'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card } from '@/components/ui/Card'
import { useAuth } from '@/hooks/useAuth'
import { useTheme } from '@/hooks/useTheme'
import { toast } from '@/components/ui/Toast'
import { useI18n } from '@/hooks/useI18n'
import { useAuthBootstrap } from '@/hooks/useAuth'
import { Spinner } from '@/components/ui/Spinner'
import { ApiError } from '@/api/client'

export default function Login() {
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [loading, setLoading] = useState(false)
  const [totp, setTotp] = useState('')
  const [showTotp, setShowTotp] = useState(false)
  const [usingRecoveryCode, setUsingRecoveryCode] = useState(false)
  const [error, setError] = useState('')
  const [shake, setShake] = useState(false)
  const [lockCountdown, setLockCountdown] = useState(0)
  const totpInputRef = useRef<HTMLInputElement>(null)
  const { login } = useAuth()
  const { theme, setTheme, resolvedTheme } = useTheme()
  const { t } = useI18n()
  const navigate = useNavigate()
  const { installed, loading: bootstrapLoading, authenticated } = useAuthBootstrap()

  useEffect(() => {
    if (lockCountdown <= 0) return
    const timer = setInterval(() => {
      setLockCountdown((prev) => (prev <= 1 ? 0 : prev - 1))
    }, 1000)
    return () => clearInterval(timer)
  }, [lockCountdown > 0])

  const formatCountdown = (seconds: number) => {
    const m = Math.floor(seconds / 60)
    const s = seconds % 60
    return `${m}:${s.toString().padStart(2, '0')}`
  }

  useEffect(() => {
    document.title = t('login.documentTitle')
  }, [t])

  useEffect(() => {
    if (!bootstrapLoading) {
      if (!installed) {
        navigate('/install', { replace: true })
      } else if (authenticated) {
        navigate('/dashboard', { replace: true })
      }
    }
  }, [installed, authenticated, bootstrapLoading, navigate])

  useEffect(() => {
    if (showTotp && totpInputRef.current) {
      totpInputRef.current.focus()
    }
  }, [showTotp, usingRecoveryCode])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!password || lockCountdown > 0) return

    setLoading(true)
    setError('')
    try {
      const totpCode = showTotp && !usingRecoveryCode ? totp : undefined
      const recoveryCode = showTotp && usingRecoveryCode ? totp : undefined
      await login(password, totpCode, recoveryCode)
      toast({ type: 'success', title: t('login.success') })
      navigate('/dashboard', { replace: true })
    } catch (err: unknown) {
      if (err instanceof ApiError) {
        if (err.code === 'ip_locked') {
          const retryAfter = err.retryAfter && err.retryAfter > 0 ? err.retryAfter : 900
          setLockCountdown(retryAfter)
          setError('')
          const minutes = Math.ceil(retryAfter / 60)
          toast({
            type: 'error',
            title: t('login.ipLocked'),
            description: t('login.retryAfter', { minutes }),
          })
          return
        }
        if (err.code === 'totp_required') {
          setShowTotp(true)
          setUsingRecoveryCode(false)
          setError(t('login.totpRequired'))
          toast({ type: 'warning', title: t('login.twoFactor'), description: t('login.totpRequired') })
          return
        }
        if (err.code === 'totp_invalid') {
          setError(t('login.totpInvalid'))
          setShake(true)
          setTimeout(() => setShake(false), 500)
          toast({ type: 'error', title: t('login.totpInvalid') })
          return
        }
        if (err.code === 'recovery_code_invalid') {
          setError(t('login.recoveryCodeInvalid'))
          setShake(true)
          setTimeout(() => setShake(false), 500)
          toast({ type: 'error', title: t('login.recoveryCodeInvalid') })
          return
        }
        if (err.code === 'recovery_code_already_used') {
          setError(t('login.recoveryCodeAlreadyUsed'))
          setShake(true)
          setTimeout(() => setShake(false), 500)
          toast({ type: 'error', title: t('login.recoveryCodeAlreadyUsed') })
          return
        }
      }
      const msg = err instanceof Error ? err.message : t('login.fail')
      setError(msg)
      setShake(true)
      setTimeout(() => setShake(false), 500)
      if (msg.includes('TOTP') || msg.includes('2FA') || msg.includes('双因素')) {
        setShowTotp(true)
      }
      toast({ type: 'error', title: t('login.fail'), description: msg })
    } finally {
      setLoading(false)
    }
  }

  const cycleTheme = () => {
    if (theme === 'light') setTheme('dark')
    else if (theme === 'dark') setTheme('system')
    else setTheme('light')
  }

  const toggleRecoveryMode = () => {
    setUsingRecoveryCode((v) => !v)
    setTotp('')
  }

  if (bootstrapLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <Spinner size="lg" />
      </div>
    )
  }

  return (
    <div className="min-h-screen flex items-center justify-center p-4 relative overflow-hidden">
      <div className="absolute inset-0 -z-10 grid-bg opacity-40" />
      <div className="absolute inset-0 -z-10 bg-gradient-to-b from-transparent via-transparent to-bg-sunken/50" />

      <div className="absolute top-0 right-0 w-96 h-96 bg-accent/10 rounded-full blur-[100px] -translate-y-1/2 translate-x-1/3" />
      <div className="absolute bottom-0 left-0 w-80 h-80 bg-accent/5 rounded-full blur-[80px] translate-y-1/3 -translate-x-1/3" />

      <button
        onClick={cycleTheme}
        className="fixed top-4 right-4 z-10 p-2.5 rounded-xl text-fg-muted hover:text-fg hover:bg-bg-elevated/80 backdrop-blur-soft transition-all duration-200 focus-ring border border-border/50 active:scale-95"
        aria-label={t('common.toggleTheme')}
      >
        {theme === 'light' ? <Sun size={18} /> : theme === 'dark' ? <Moon size={18} /> : <Monitor size={18} />}
      </button>

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
          <p className="text-sm text-fg-muted">{t('login.subtitle')}</p>
        </div>

        <Card className={`p-6 md:p-8 shadow-xl shadow-black/5 ${shake ? 'animate-shake' : ''}`}>
          <div className="flex items-center gap-3 mb-6">
            <div className="w-10 h-10 rounded-xl bg-accent/10 text-accent flex items-center justify-center">
              <ShieldCheck size={20} />
            </div>
            <div>
              <h1 className="text-lg font-semibold text-fg">{t('login.title')}</h1>
              <p className="text-xs text-fg-subtle">{t('login.subtitle')}</p>
            </div>
          </div>

          <form onSubmit={handleSubmit} className="space-y-5">
            <div className="space-y-2">
              <label className="block text-sm font-medium text-fg">
                {t('login.passwordLabel')}
              </label>
              <div className="relative group">
                <Input
                  type={showPassword ? 'text' : 'password'}
                  value={password}
                  onChange={(e) => {
                    setPassword(e.target.value)
                    if (error) setError('')
                  }}
                  placeholder={t('login.passwordPlaceholder')}
                  icon={<Lock size={18} />}
                  autoFocus
                  inputMode="text"
                  autoComplete="current-password"
                  invalid={!!error && !showTotp}
                  className={`transition-all duration-200 ${error ? 'animate-pulse' : ''}`}
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
            </div>

            {showTotp && (
              <div className="space-y-2 animate-fade-in-up">
                <div className="flex items-center justify-between">
                  <label className="block text-sm font-medium text-fg">
                    {usingRecoveryCode ? t('login.recoveryCodeLabel') : t('login.totpLabel')}
                  </label>
                  <button
                    type="button"
                    onClick={toggleRecoveryMode}
                    className="text-xs text-accent hover:text-accent/80 hover:underline transition-colors"
                  >
                    {usingRecoveryCode ? t('login.useTotpCode') : t('login.useRecoveryCode')}
                  </button>
                </div>
                <Input
                  ref={totpInputRef}
                  type="text"
                  value={totp}
                  onChange={(e) => {
                    if (usingRecoveryCode) {
                      setTotp(e.target.value.toUpperCase().replace(/[^A-Z0-9-]/g, '').slice(0, 19))
                    } else {
                      setTotp(e.target.value.replace(/\D/g, '').slice(0, 6))
                    }
                    if (error) setError('')
                  }}
                  placeholder={usingRecoveryCode ? t('login.recoveryCodePlaceholder') : t('login.totpPlaceholder')}
                  inputMode={usingRecoveryCode ? 'text' : 'numeric'}
                  autoComplete={usingRecoveryCode ? 'off' : 'one-time-code'}
                  maxLength={usingRecoveryCode ? 19 : 6}
                  className={usingRecoveryCode
                    ? 'font-mono text-sm tracking-wide text-center'
                    : 'text-center font-mono tracking-widest text-lg'}
                  invalid={!!error}
                />
              </div>
            )}

            {error && (
              <div className="text-xs text-danger bg-danger/10 rounded-lg px-3 py-2 animate-fade-in flex items-center gap-2">
                <div className="w-1.5 h-1.5 rounded-full bg-danger shrink-0 animate-pulse" />
                <span>{error}</span>
              </div>
            )}

            {lockCountdown > 0 ? (
              <div className="rounded-lg bg-warning/10 border border-warning/30 px-4 py-3 animate-fade-in space-y-2">
                <div className="flex items-center gap-2 text-warning">
                  <ShieldAlert size={18} className="shrink-0" />
                  <span className="text-sm font-medium">{t('login.ipLocked')}</span>
                </div>
                <p className="text-xs text-fg-muted">{t('login.lockedMessage')}</p>
                <div className="flex items-center gap-2 text-warning">
                  <span className="text-2xl font-mono font-semibold tabular-nums">
                    {formatCountdown(lockCountdown)}
                  </span>
                  <span className="text-xs text-fg-muted">
                    {t('login.retryAfterSeconds', { seconds: lockCountdown })}
                  </span>
                </div>
              </div>
            ) : null}

            <Button
              type="submit"
              size="lg"
              className="w-full font-semibold"
              loading={loading}
              disabled={lockCountdown > 0}
            >
              {t('login.submit')}
            </Button>
          </form>
        </Card>

        <div className="flex items-center justify-center gap-2 mt-6">
          <div className="w-2 h-2 rounded-full bg-success/60" />
          <p className="text-xs text-fg-subtle">
            {t('login.currentTheme')}: {resolvedTheme === 'dark' ? t('settings.dark') : t('settings.light')}
          </p>
        </div>
      </div>
    </div>
  )
}
