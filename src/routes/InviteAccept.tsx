import { useState } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { CheckCircle2, AlertTriangle, Eye, EyeOff } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card, CardBody } from '@/components/ui/Card'
import { Logo } from '@/components/branding/Logo'
import { Spinner } from '@/components/ui/Spinner'
import { invitationsApi } from '@/api/invitations'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'

export default function InviteAccept() {
  const { t } = useI18n()
  const navigate = useNavigate()
  const { token = '' } = useParams()

  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [fieldError, setFieldError] = useState('')
  const [done, setDone] = useState(false)

  const { data: preview, isLoading, isError, error } = useQuery({
    queryKey: ['invite-preview', token],
    queryFn: () => invitationsApi.preview(token),
    retry: false,
    enabled: token !== '',
  })

  const acceptMutation = useMutation({
    mutationFn: () => invitationsApi.accept({ token, username: username.trim(), password }),
    onSuccess: () => setDone(true),
    onError: (err: Error) => setFieldError(resolveErrorText(err)),
  })

  const suggested = preview?.suggested_username || ''

  const handleSubmit = () => {
    const finalName = (username || suggested).trim()
    if (!finalName || !password) {
      setFieldError(t('inviteAccept.fillAll'))
      return
    }
    if (password !== confirm) {
      setFieldError(t('inviteAccept.passwordsNotMatch'))
      return
    }
    if (password.length < 8) {
      setFieldError(t('inviteAccept.passwordMinLength'))
      return
    }
    setFieldError('')
    acceptMutation.mutate()
  }

  const status = preview?.status
  const blocked = isError || (status && status !== 'pending')

  return (
    <div className="min-h-screen flex items-center justify-center bg-bg p-4">
      <div className="w-full max-w-md">
        <div className="flex justify-center mb-6">
          <Logo size="md" showText />
        </div>

        <Card>
          <CardBody className="space-y-4">
            {isLoading ? (
              <div className="flex justify-center py-8">
                <Spinner size="lg" />
              </div>
            ) : done ? (
              <div className="text-center space-y-3 py-4">
                <CheckCircle2 size={40} className="mx-auto text-success" />
                <div className="text-lg font-semibold text-fg">{t('inviteAccept.successTitle')}</div>
                <p className="text-sm text-fg-muted">{t('inviteAccept.successDesc')}</p>
                <Button variant="primary" className="w-full" onClick={() => navigate('/login')}>
                  {t('inviteAccept.goToLogin')}
                </Button>
              </div>
            ) : blocked ? (
              <div className="text-center space-y-3 py-4">
                <AlertTriangle size={40} className="mx-auto text-warning" />
                <div className="text-lg font-semibold text-fg">
                  {t(`inviteAccept.status_${status || 'unavailable'}`)}
                </div>
                <p className="text-sm text-fg-muted">
                  {isError ? resolveErrorText(error) : t('inviteAccept.contactAdmin')}
                </p>
                <Link to="/login" className="text-sm text-accent hover:underline">
                  {t('inviteAccept.goToLogin')}
                </Link>
              </div>
            ) : (
              <>
                <div>
                  <h1 className="text-lg font-semibold text-fg">{t('inviteAccept.title')}</h1>
                  <p className="text-sm text-fg-muted mt-1">
                    {t('inviteAccept.subtitle', { email: preview?.email_masked || '', role: preview?.role || '' })}
                  </p>
                </div>

                <div>
                  <label className="block text-sm font-medium text-fg mb-1">{t('inviteAccept.username')}</label>
                  <Input
                    value={username}
                    onChange={(e) => setUsername(e.target.value)}
                    placeholder={suggested}
                    autoComplete="username"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-fg mb-1">{t('inviteAccept.password')}</label>
                  <div className="relative">
                    <Input
                      type={showPassword ? 'text' : 'password'}
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
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
                </div>

                <div>
                  <label className="block text-sm font-medium text-fg mb-1">{t('inviteAccept.confirmPassword')}</label>
                  <Input
                    type={showPassword ? 'text' : 'password'}
                    value={confirm}
                    onChange={(e) => setConfirm(e.target.value)}
                    autoComplete="new-password"
                  />
                </div>

                {fieldError && (
                  <div className="text-sm text-danger bg-danger/10 border border-danger/30 rounded-lg px-3 py-2">
                    {fieldError}
                  </div>
                )}

                <Button variant="primary" className="w-full" onClick={handleSubmit} loading={acceptMutation.isPending}>
                  {t('inviteAccept.submit')}
                </Button>
              </>
            )}
          </CardBody>
        </Card>
      </div>
    </div>
  )
}
