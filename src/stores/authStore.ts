import { create } from 'zustand'
import type { BootstrapData, Capabilities } from '@shared/types'

interface AuthState {
  bootstrapped: boolean
  bootstrapFailed: boolean
  authenticated: boolean
  installed: boolean
  loading: boolean
  csrfToken: string
  capabilities: Capabilities | null
  user: { username: string } | null
  backendVersion: string
  frontendVersion: string

  setBootstrap: (data: BootstrapData) => void
  setBootstrapFailed: () => void
  setLoading: (loading: boolean) => void
  reset: () => void
}

const defaultCaps: Capabilities = {
  disk: true,
  mysql: false,
  terminal: false,
  processes: false,
  cron: false,
  zip: false,
  targz: false,
  gd: false,
  openBasedir: false,
  disabledFunctions: [],
  phpVersion: '',
  sapi: '',
  maxUpload: 0,
  maxPost: 0,
  memoryLimit: 0,
}

export const useAuthStore = create<AuthState>((set) => ({
  bootstrapped: false,
  bootstrapFailed: false,
  authenticated: false,
  installed: true,
  loading: true,
  csrfToken: '',
  capabilities: null,
  user: null,
  backendVersion: '',
  frontendVersion: import.meta.env.VITE_APP_VERSION || '0.3.1',

  setBootstrap: (data) =>
    set({
      bootstrapped: true,
      bootstrapFailed: false,
      loading: false,
      authenticated: data.authenticated,
      installed: data.installed,
      csrfToken: data.csrfToken,
      capabilities: data.capabilities,
      user: data.user || null,
      backendVersion: data.backendVersion,
      frontendVersion: data.frontendVersion || '0.3.1',
    }),

  setBootstrapFailed: () =>
    set({
      bootstrapped: true,
      bootstrapFailed: true,
      loading: false,
    }),

  setLoading: (loading) => set({ loading }),

  reset: () =>
    set({
      authenticated: false,
      user: null,
      csrfToken: '',
    }),
}))

export function getDefaultCaps() {
  return defaultCaps
}
