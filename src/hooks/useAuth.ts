import { useEffect, useCallback } from 'react'
import { useAuthStore } from '@/stores/authStore'
import { authApi } from '@/api/auth'
import { setCsrfToken } from '@/api/client'

export function useAuthBootstrap() {
  const { bootstrapped, bootstrapFailed, authenticated, installed, loading, capabilities } = useAuthStore()
  const setBootstrap = useAuthStore((s) => s.setBootstrap)
  const setBootstrapFailed = useAuthStore((s) => s.setBootstrapFailed)
  const reset = useAuthStore((s) => s.reset)

  const load = useCallback(async () => {
    try {
      const data = await authApi.bootstrap()
      setCsrfToken(data.csrfToken)
      setBootstrap(data)
    } catch (err) {

      if (err instanceof Error && err.message.includes('Not Found')) {
        setBootstrapFailed()
      } else {
        setBootstrap({
          authenticated: false,
          installed: true,
          csrfToken: '',
          capabilities: {
            disk: true,
            mysql: false,
            terminal: false,
            processes: false,
            cron: false,
            zip: false,
            gd: false,
            openBasedir: false,
            disabledFunctions: [],
            phpVersion: '',
            sapi: '',
            maxUpload: 0,
            maxPost: 0,
            memoryLimit: 0,
          },
          backendVersion: '',
          frontendVersion: '',
        })
      }
    }
  }, [setBootstrap, setBootstrapFailed])

  useEffect(() => {
    if (!bootstrapped) {
      load()
    }
  }, [bootstrapped, load])

  useEffect(() => {
    const handler = () => reset()
    window.addEventListener('auth:expired', handler)
    return () => window.removeEventListener('auth:expired', handler)
  }, [reset])

  return {
    loading: loading || !bootstrapped,
    authenticated,
    installed,
    capabilities,
    bootstrapFailed,
  }
}

export function useAuth() {
  const { authenticated, user, backendVersion, frontendVersion } = useAuthStore()
  const reset = useAuthStore((s) => s.reset)

  const logout = useCallback(async () => {
    try {
      await authApi.logout()
    } finally {
      reset()
      window.location.href = '/login'
    }
  }, [reset])

  const login = useCallback(async (password: string, totp?: string) => {
    await authApi.login({ username: 'admin', password, totp })
    const data = await authApi.bootstrap()
    setCsrfToken(data.csrfToken)
    useAuthStore.getState().setBootstrap(data)
    return data
  }, [])

  return {
    authenticated,
    user,
    backendVersion,
    frontendVersion,
    login,
    logout,
  }
}
